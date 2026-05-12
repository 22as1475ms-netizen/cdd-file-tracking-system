<?php
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/DocumentRoute.php";
require_once __DIR__ . "/../models/Permission.php";
require_once __DIR__ . "/../models/Notification.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/../models/Division.php";
require_once __DIR__ . "/../services/AccessService.php";

class DocumentShareService {
  public static function shareDocument(
    PDO $pdo,
    array $doc,
    int $actorId,
    array $target,
    string $permission,
    array $options = []
  ): array {
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) {
      throw new RuntimeException('not_found');
    }

    if (!self::canForwardDocument($pdo, $docId, $actorId)) {
      throw new RuntimeException('forbidden');
    }

    self::assertValidTarget($doc, $actorId, $target);
    if (self::shareLockedForUser($pdo, $doc, $actorId)) {
      throw new RuntimeException('share_in_progress');
    }

    $permission = self::normalizePermission($permission);
    $targetId = (int)($target['id'] ?? 0);
    $role = strtoupper((string)($target['role'] ?? ''));
    $division = (int)($target['division_id'] ?? 0) > 0 ? Division::find($pdo, (int)$target['division_id']) : null;

    // Do not revoke existing permissions — allow multiple recipients to hold shared access.
    Permission::upsert($pdo, $docId, $targetId, $permission, $actorId);
    // If the share target is an admin, auto-accept the share so admin sees the file immediately.
    if ($role === 'ADMIN') {
      Permission::accept($pdo, $docId, $targetId);
      $recipientName = trim((string)($target['name'] ?? 'Admin'));
      Document::updateTrackingState($pdo, $docId, 'Shared with ' . $recipientName, 'SHARE_ACCEPTED');
      Document::markRouteActive($pdo, $docId);
      DocumentRoute::add(
        $pdo,
        $docId,
        (string)($doc['current_location'] ?? ''),
        'Shared with ' . $recipientName,
        'SHARE_ACCEPTED',
        'Shared with admin: ' . $recipientName,
        $actorId
      );
      if (($options['notify'] ?? true) !== false) {
        // Notify the owner that the share was accepted by admin
        $notifyUserId = (int)($doc['owner_id'] ?? 0);
        if ($notifyUserId > 0) {
          Notification::add(
            $pdo,
            $notifyUserId,
            'Shared document accepted',
            'The document was shared with an administrator and is now visible to them.',
            '/documents/view?id=' . $docId
          );
        }
      }
    } else {
      Document::updateTrackingState($pdo, $docId, 'Awaiting recipient acceptance', 'PENDING_SHARE_ACCEPTANCE');
      Document::markRouteActive($pdo, $docId);
      DocumentRoute::add(
        $pdo,
        $docId,
        (string)($doc['current_location'] ?? ''),
        'Awaiting recipient acceptance',
        'PENDING_SHARE_ACCEPTANCE',
        self::shareRouteNote($target, $division, (string)($options['note_suffix'] ?? '')),
        $actorId
      );
    }

    if (($options['audit'] ?? true) !== false) {
      AuditLog::add(
        $pdo,
        $actorId,
        (string)($options['audit_action'] ?? 'Shared document'),
        $docId,
        'to=' . trim((string)($target['email'] ?? ''))
      );
    }

    if (($options['notify'] ?? true) !== false && $role !== 'ADMIN') {
      Notification::add(
        $pdo,
        $targetId,
        (string)($options['notification_title'] ?? 'A routed file was shared with you'),
        (string)($options['notification_body'] ?? 'Open the routed file to accept it.'),
        (string)($options['notification_link'] ?? ('/documents/view?id=' . $docId))
      );
    }

    return [
      'document_id' => $docId,
      'target_id' => $targetId,
      'target_email' => trim((string)($target['email'] ?? '')),
      'permission' => $permission,
    ];
  }

  public static function respondToShare(PDO $pdo, array $doc, array $permissionRow, int $actorId, string $decision, ?string $note = null): array {
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) {
      throw new RuntimeException('not_found');
    }

    $decision = strtoupper(trim($decision));
    if ($decision === 'ACCEPT') {
      Permission::accept($pdo, $docId, $actorId);
      $recipientName = trim((string)($_SESSION['user']['name'] ?? 'recipient'));
      Document::updateTrackingState($pdo, $docId, 'Shared with ' . $recipientName, 'SHARE_ACCEPTED');
      Document::markRouteActive($pdo, $docId);
      DocumentRoute::add($pdo, $docId, 'Awaiting recipient acceptance', 'Shared with ' . $recipientName, 'SHARE_ACCEPTED', 'Recipient accepted the routed document.', $actorId);
      $notifyUserId = (int)($permissionRow['shared_by'] ?? 0);
      if ($notifyUserId <= 0) {
        $notifyUserId = (int)($doc['owner_id'] ?? 0);
      }
      Notification::add($pdo, $notifyUserId, 'Shared document accepted', (string)($_SESSION['user']['email'] ?? ''), '/documents/view?id=' . $docId);
      AuditLog::add($pdo, $actorId, 'Accepted shared document', $docId, null);
      return ['status' => 'accepted'];
    }

    $cleanNote = trim((string)$note);
    if ($cleanNote === '') {
      throw new RuntimeException('response_note_required');
    }

    Permission::decline($pdo, $docId, $actorId, $cleanNote);
    Document::updateTrackingState($pdo, $docId, 'Share declined by recipient', 'SHARE_DECLINED');
    Document::closeRoute($pdo, $docId, 'RETURNED');
    DocumentRoute::add($pdo, $docId, 'Awaiting recipient acceptance', 'Share declined by recipient', 'SHARE_DECLINED', $cleanNote, $actorId);
    Notification::add($pdo, (int)($doc['owner_id'] ?? 0), 'Shared document not accepted', $cleanNote, '/documents/view?id=' . $docId);
    AuditLog::add($pdo, $actorId, 'Declined shared document', $docId, $cleanNote);

    return ['status' => 'declined'];
  }

  public static function revokeShare(PDO $pdo, array $doc, int $actorId, string $ownerName): array {
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) {
      throw new RuntimeException('not_found');
    }

    $shareMembers = Permission::listForDoc($pdo, $docId);
    if (empty($shareMembers)) {
      throw new RuntimeException('not_found');
    }

    foreach ($shareMembers as $member) {
      $memberUserId = (int)($member['user_id'] ?? 0);
      if ($memberUserId <= 0) {
        continue;
      }
      Permission::revoke($pdo, $docId, $memberUserId);
      Notification::add($pdo, $memberUserId, 'Share cancelled by owner', (string)($doc['title'] ?? $doc['name'] ?? ''), '/documents?tab=shared');
    }

    Document::updateTrackingState($pdo, $docId, $ownerName, 'AVAILABLE');
    Document::closeRoute($pdo, $docId, 'RETURNED');
    DocumentRoute::add(
      $pdo,
      $docId,
      (string)($doc['current_location'] ?? 'Awaiting recipient acceptance'),
      $ownerName,
      'AVAILABLE',
      'Share cancelled by owner and file returned to owner.',
      $actorId
    );
    AuditLog::add($pdo, $actorId, 'Cancelled share', $docId, 'members=' . count($shareMembers));

    return ['revoked_members' => count($shareMembers)];
  }

  private static function normalizePermission(string $permission): string {
    return 'editor';
  }

  private static function canForwardDocument(PDO $pdo, int $docId, int $actorId): bool {
    $level = AccessService::level($pdo, $docId, $actorId);
    return in_array($level, ['admin', 'owner', 'editor', 'viewer', 'division_chief'], true);
  }

  private static function shareLockedForUser(PDO $pdo, array $doc, int $actorId): bool {
    $level = AccessService::level($pdo, (int)($doc['id'] ?? 0), $actorId);
    $routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
    $routeOutcome = strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE'));
    if ($routeOutcome !== 'ACTIVE' || in_array($routingStatus, ['APPROVED', 'REJECTED'], true)) {
      return true;
    }

    return match ($routingStatus) {
      'PENDING_SHARE_ACCEPTANCE', 'PENDING_REVIEW_ACCEPTANCE' => true,
      'SHARE_ACCEPTED' => !in_array($level, ['admin', 'editor', 'viewer'], true),
      'IN_REVIEW' => !in_array($level, ['admin', 'division_chief'], true),
      default => false,
    };
  }

  private static function assertValidTarget(array $doc, int $actorId, array $target): void {
    $role = strtoupper((string)($target['role'] ?? ''));
    if (!in_array($role, ['EMPLOYEE', 'DIVISION_CHIEF', 'ADMIN'], true)) {
      throw new RuntimeException('user_not_found');
    }

    $targetId = (int)($target['id'] ?? 0);
    if ($targetId <= 0) {
      throw new RuntimeException('user_not_found');
    }
    if ($targetId === $actorId) {
      throw new RuntimeException('cannot_share_self');
    }

    $docDivisionId = (int)($doc['division_id'] ?? 0);
    if ($docDivisionId > 0 && (int)($target['division_id'] ?? 0) !== $docDivisionId) {
      throw new RuntimeException('user_not_found');
    }
  }

  private static function shareRouteNote(array $target, ?array $division = null, string $suffix = ''): string {
    $targetName = trim((string)($target['name'] ?? 'Recipient'));
    $targetEmail = trim((string)($target['email'] ?? ''));
    $divisionName = trim((string)($division['name'] ?? ($target['division_name'] ?? '')));
    $chiefName = trim((string)($division['chief_name'] ?? ''));

    $parts = ['Document routed to ' . $targetName];
    if ($targetEmail !== '') {
      $parts[] = '(' . $targetEmail . ')';
    }
    if ($divisionName !== '') {
      $parts[] = 'under ' . $divisionName;
    }
    if ($chiefName !== '') {
      $parts[] = 'with division chief ' . $chiefName;
    }

    $note = implode(' ', $parts) . ' and is waiting for acceptance.';
    $suffix = trim($suffix);
    if ($suffix !== '') {
      $note .= ' ' . $suffix;
    }

    return $note;
  }
}
