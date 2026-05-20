<?php
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/DocumentRoute.php";
require_once __DIR__ . "/../models/Permission.php";
require_once __DIR__ . "/../models/Notification.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/../models/Division.php";
require_once __DIR__ . "/../models/User.php";
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
    $division = (int)($target['division_id'] ?? 0) > 0 ? Division::find($pdo, (int)$target['division_id']) : null;

    Permission::upsert($pdo, $docId, $targetId, $permission, $actorId);
    Permission::accept($pdo, $docId, $targetId);
    $recipientName = trim((string)($target['name'] ?? 'Recipient'));
    Document::updateTrackingState($pdo, $docId, 'Shared with ' . $recipientName, 'SHARE_ACCEPTED');
    Document::markRouteActive($pdo, $docId);
    DocumentRoute::add(
      $pdo,
      $docId,
      (string)($doc['current_location'] ?? ''),
      'Shared with ' . $recipientName,
      'SHARE_ACCEPTED',
      self::shareRouteNote($target, $division, (string)($options['note_suffix'] ?? '')),
      $actorId
    );

    if (($options['audit'] ?? true) !== false) {
      AuditLog::add(
        $pdo,
        $actorId,
        (string)($options['audit_action'] ?? 'Shared document'),
        $docId,
        'to=' . trim((string)($target['email'] ?? ''))
      );
    }

    if (($options['notify'] ?? true) !== false) {
      Notification::add(
        $pdo,
        $targetId,
        (string)($options['notification_title'] ?? 'A routed file was shared with you'),
        (string)($options['notification_body'] ?? 'The routed file is now available in your files.'),
        (string)($options['notification_link'] ?? ('/documents/view?id=' . $docId))
      );
    }

    self::notifyAdminAboutShare($pdo, $doc, $actorId, $target);

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

  public static function completeRouteLifecycle(PDO $pdo, array $doc, int $actorId, ?string $note = null): array {
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) {
      throw new RuntimeException('not_found');
    }

    $level = AccessService::level($pdo, $docId, $actorId);
    if (!in_array($level, ['editor', 'viewer', 'division_chief'], true)) {
      throw new RuntimeException('forbidden');
    }

    $routeOutcome = strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE'));
    $routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
    $actor = User::findById($pdo, $actorId);
    $actorRole = strtoupper((string)($actor['role'] ?? $_SESSION['user']['role'] ?? ''));
    if (in_array($actorRole, ['SECTION_ADMIN', 'DIVISION_CHIEF'], true)) {
      throw new RuntimeException('forbidden');
    }
    if ($routeOutcome !== 'ACTIVE' || $routingStatus === 'COMPLETED') {
      throw new RuntimeException('decision_already_final');
    }

    $actorName = trim((string)($actor['name'] ?? $_SESSION['user']['name'] ?? 'Current holder'));
    $holderLocation = trim((string)($doc['current_location'] ?? ''));
    if ($holderLocation === '') {
      $holderLocation = 'Shared with ' . $actorName;
    }

    $storedNote = trim((string)$note);
    if ($storedNote !== '') {
      $storedNote = mb_substr($storedNote, 0, 1000);
    } else {
      $storedNote = 'Route lifecycle completed by ' . $actorName . ' and remains with the final routed holder.';
    }

    $ownerId = (int)($doc['owner_id'] ?? 0);

    // Keep the completing staff member with read-only access to retain a copy
    // of the routed file, while preserving visibility for prior routed recipients.
    try {
      Permission::upsert($pdo, $docId, $actorId, 'viewer', $ownerId > 0 ? $ownerId : null);
      Permission::accept($pdo, $docId, $actorId);
      
      // Verify the permission was actually saved
      $verifyPerm = Permission::findRowForUser($pdo, $docId, $actorId);
      if (!$verifyPerm || empty($verifyPerm['accepted_at'])) {
        throw new RuntimeException('permission_not_saved');
      }
      } catch (Exception $e) {
      throw new RuntimeException('permission_setup_failed: ' . $e->getMessage());
    }

    Document::completeRouteLifecycle($pdo, $docId, $holderLocation);
    DocumentRoute::add(
      $pdo,
      $docId,
      $holderLocation,
      $holderLocation,
      'COMPLETED',
      $storedNote,
      $actorId
    );

    if ($ownerId > 0) {
      Notification::add(
        $pdo,
        $ownerId,
        'Route lifecycle completed',
        $actorName . ' completed the route and the file remains with the final routed holder.',
        '/documents/view?id=' . $docId
      );
    }

    AuditLog::add($pdo, $actorId, 'Completed route lifecycle', $docId, $storedNote);

    return [
      'document_id' => $docId,
      'status' => 'completed',
    ];
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
    $actor = User::findById($pdo, $actorId);
    $actorRole = strtoupper((string)($actor['role'] ?? $_SESSION['user']['role'] ?? ''));
    if ($routeOutcome !== 'ACTIVE' || in_array($routingStatus, ['APPROVED', 'REJECTED', 'COMPLETED'], true)) {
      return true;
    }

    if ($level === 'admin') {
      return false;
    }

    if (in_array($actorRole, ['SECTION_ADMIN', 'DIVISION_CHIEF'], true) && $routingStatus === 'SHARE_ACCEPTED') {
      $currentAcceptedUserId = Permission::currentAcceptedUserId($pdo, (int)($doc['id'] ?? 0));
      if ($currentAcceptedUserId !== $actorId) {
        return true;
      }
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
    if (!in_array($role, ['SECTION_STAFF', 'SECTION_ADMIN', 'EMPLOYEE', 'DIVISION_CHIEF', 'ADMIN', 'SUPER_ADMIN'], true)) {
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

    $note = implode(' ', $parts) . ' and is now active in the routed workflow.';
    $suffix = trim($suffix);
    if ($suffix !== '') {
      $note .= ' ' . $suffix;
    }

    return $note;
  }

  private static function notifyAdminAboutShare(PDO $pdo, array $doc, int $actorId, array $target): void {
    $actor = User::findById($pdo, $actorId);
    if (!$actor || in_array(strtoupper((string)($actor['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true)) {
      return;
    }

    $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('SUPER_ADMIN', 'ADMIN') AND status = 'ACTIVE' ORDER BY FIELD(role, 'SUPER_ADMIN', 'ADMIN'), id LIMIT 1");
    $stmt->execute();
    $adminId = (int)$stmt->fetchColumn();
    if ($adminId <= 0) {
      return;
    }

    $docName = trim((string)($doc['name'] ?? 'Routed file'));
    $actorName = trim((string)($actor['name'] ?? 'A user'));
    $targetName = trim((string)($target['name'] ?? 'another user'));
    Notification::add(
      $pdo,
      $adminId,
      'File shared to another user',
      $actorName . ' routed ' . $docName . ' to ' . $targetName . '.',
      '/documents/view?id=' . (int)($doc['id'] ?? 0)
    );
  }
}
