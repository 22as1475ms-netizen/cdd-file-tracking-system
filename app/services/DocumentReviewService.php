<?php
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/Division.php";
require_once __DIR__ . "/../models/Organization.php";
require_once __DIR__ . "/../models/DocumentRoute.php";
require_once __DIR__ . "/../models/DocumentReview.php";
require_once __DIR__ . "/../models/Permission.php";
require_once __DIR__ . "/../models/Notification.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/../models/User.php";

class DocumentReviewService {
  public static function submitForReview(PDO $pdo, array $doc, int $actorId): array {
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) {
      throw new RuntimeException('not_found');
    }
    if ((int)($doc['owner_id'] ?? 0) !== $actorId && strtoupper((string)($_SESSION['user']['role'] ?? '')) !== 'ADMIN') {
      throw new RuntimeException('forbidden');
    }
    if (!self::canSubmit($doc)) {
      throw new RuntimeException(((int)($doc['approval_locked'] ?? 0) === 1) ? 'approval_locked' : 'decision_already_final');
    }

    $divisionId = (int)($doc['division_id'] ?? 0);
    if ($divisionId <= 0) {
      throw new RuntimeException('division_required');
    }

    $assignment = TeamMember::sectionReviewAssignmentForUser($pdo, (int)($doc['owner_id'] ?? 0));
    if (!$assignment || (int)($assignment['chief_user_id'] ?? 0) <= 0 || (int)($assignment['section_id'] ?? 0) <= 0) {
      throw new RuntimeException('section_chief_required');
    }

    Document::submitForReview($pdo, $docId, $divisionId, (int)$assignment['section_id'], (int)$assignment['chief_user_id']);
    $assignedReviewerId = (int)($assignment['chief_user_id'] ?? 0);
    $assignedReviewer = $assignedReviewerId > 0 ? User::findById($pdo, $assignedReviewerId) : null;
    // If the assigned reviewer is an admin, auto-accept the review so the admin sees it immediately.
    if (($assignedReviewer['role'] ?? '') === 'ADMIN') {
      Document::acceptReviewAssignment($pdo, $docId);
      $stageLabel = 'Section Chief';
      Document::updateTrackingState($pdo, $docId, $stageLabel . ' Review Workspace', 'IN_REVIEW');
      Document::markRouteActive($pdo, $docId);
      DocumentRoute::add(
        $pdo,
        $docId,
        $stageLabel . ' Review Queue',
        $stageLabel . ' Review Workspace',
        'IN_REVIEW',
        $stageLabel . ' accepted the routed document for review.',
        $assignedReviewerId
      );
      Notification::add($pdo, (int)($doc['owner_id'] ?? 0), 'Routed file accepted for review', (string)($doc['name'] ?? ''), '/documents/view?id=' . $docId);
      AuditLog::add($pdo, $actorId, 'Submitted routed file for section review (auto-accepted by admin)', $docId, 'division_id=' . $divisionId . ',section_id=' . (int)$assignment['section_id']);
    } else {
      Document::updateTrackingState($pdo, $docId, 'Section Chief Review Queue', 'PENDING_REVIEW_ACCEPTANCE');
      Document::markRouteActive($pdo, $docId);
      DocumentRoute::add(
        $pdo,
        $docId,
        (string)($doc['current_location'] ?? ''),
        'Section Chief Review Queue',
        'PENDING_REVIEW_ACCEPTANCE',
        self::documentRouteNote('submit', $doc),
        $actorId
      );
      Notification::add($pdo, (int)$assignment['chief_user_id'], 'Routed file awaiting section review', (string)($doc['name'] ?? ''), '/documents/view?id=' . $docId);
      AuditLog::add($pdo, $actorId, 'Submitted routed file for section review', $docId, 'division_id=' . $divisionId . ',section_id=' . (int)$assignment['section_id']);
    }

    return [
      'document_id' => $docId,
      'division_id' => $divisionId,
      'section_id' => (int)$assignment['section_id'],
      'chief_user_id' => (int)$assignment['chief_user_id'],
    ];
  }

  public static function escalateToDivisionChief(PDO $pdo, array $doc, int $actorId, ?string $note = null): array {
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) {
      throw new RuntimeException('not_found');
    }

    $assignedReviewerId = (int)($doc['assigned_reviewer_id'] ?? 0);
    if ($assignedReviewerId > 0 && $assignedReviewerId !== $actorId && strtoupper((string)($_SESSION['user']['role'] ?? '')) !== 'ADMIN') {
      throw new RuntimeException('forbidden');
    }

    $divisionId = (int)($doc['division_id'] ?? 0);
    if ($divisionId <= 0) {
      throw new RuntimeException('division_required');
    }

    $division = Division::find($pdo, $divisionId);
    $divisionChiefId = (int)($division['chief_user_id'] ?? 0);
    if ($divisionChiefId <= 0) {
      throw new RuntimeException('escalation_not_available');
    }

    // Persist escalation on the document
    Document::escalateReview($pdo, $docId, $divisionChiefId, $note);
    Document::updateTrackingState($pdo, $docId, 'Division Chief Review Queue', 'PENDING_REVIEW_ACCEPTANCE');
    Document::markRouteActive($pdo, $docId);

    DocumentRoute::add(
      $pdo,
      $docId,
      (string)($doc['current_location'] ?? ''),
      'Division Chief Review Queue',
      'PENDING_REVIEW_ACCEPTANCE',
      trim((string)($note ?? '')) !== '' ? trim((string)$note) : 'Escalated to division chief for final review',
      $actorId
    );

    Notification::add($pdo, $divisionChiefId, 'Routed file awaiting division review', (string)($doc['name'] ?? ''), '/documents/view?id=' . $docId);
    AuditLog::add($pdo, $actorId, 'Escalated routed file to division chief', $docId, 'division_id=' . $divisionId);

    return ['document_id' => $docId, 'division_chief_id' => $divisionChiefId];
  }

  public static function finalizeDecision(PDO $pdo, array $doc, int $actorId, string $decision, ?string $note = null): array {
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) {
      throw new RuntimeException('not_found');
    }

    $decision = strtoupper(trim($decision));
    if (!in_array($decision, ['APPROVED', 'REJECTED'], true)) {
      throw new RuntimeException('decision_invalid');
    }
    $assignedReviewerId = (int)($doc['assigned_reviewer_id'] ?? 0);
    if ($assignedReviewerId > 0 && $assignedReviewerId !== $actorId && strtoupper((string)($_SESSION['user']['role'] ?? '')) !== 'ADMIN') {
      throw new RuntimeException('forbidden');
    }

    $cleanNote = trim((string)$note);
    if ($decision === 'REJECTED' && $cleanNote === '') {
      throw new RuntimeException('reject_note_required');
    }

    $stage = strtoupper((string)($doc['review_stage'] ?? 'SECTION_REVIEW'));
    if ($stage === 'NOT_SENT' && (int)($doc['assigned_reviewer_id'] ?? 0) <= 0) {
      $stage = 'SECTION_REVIEW';
    }
    if ($stage !== 'SECTION_REVIEW') {
      throw new RuntimeException('decision_already_final');
    }

    $storedNote = $cleanNote !== '' ? mb_substr($cleanNote, 0, 1000) : null;
    Document::finalizeReview($pdo, $docId, $decision, $storedNote, $actorId);
    $ownerFinalLocation = trim((string)($doc['owner_name'] ?? 'Original uploader'));
    $nextLocation = $decision === 'APPROVED' ? $ownerFinalLocation : 'Returned to Owner';
    $nextRouteStatus = $decision === 'APPROVED' ? 'APPROVED' : 'REJECTED';
    Document::updateTrackingState($pdo, $docId, $nextLocation, $nextRouteStatus);
    Document::closeRoute($pdo, $docId, $decision === 'APPROVED' ? 'APPROVED' : 'REJECTED');

    foreach (Permission::listForDoc($pdo, $docId) as $member) {
      $memberUserId = (int)($member['user_id'] ?? 0);
      if ($memberUserId > 0) {
        Permission::revoke($pdo, $docId, $memberUserId);
      }
    }

    DocumentRoute::add(
      $pdo,
      $docId,
      (string)($doc['current_location'] ?? 'Section Chief Review Queue'),
      $nextLocation,
      $nextRouteStatus,
      $storedNote ?: ($decision === 'APPROVED'
        ? self::reviewerLabel($stage) . ' approved the routed file and it was automatically returned to the original uploader as the final holder.'
        : self::documentRouteNote('reject', $doc)),
      $actorId
    );
    DocumentReview::add($pdo, $docId, $actorId, $decision, $storedNote);
    Notification::add(
      $pdo,
      (int)($doc['owner_id'] ?? 0),
      $decision === 'APPROVED' ? 'Routed file approved' : 'Routed file rejected',
      $storedNote ?: ($decision === 'APPROVED'
        ? 'Approved by the ' . strtolower(self::reviewerLabel($stage)) . ' and automatically returned to you.'
        : (string)($doc['name'] ?? '')),
      '/documents/view?id=' . $docId
    );
    AuditLog::add($pdo, $actorId, $decision === 'APPROVED' ? 'Approved routed file' : 'Rejected routed file', $docId, $storedNote);

    return ['document_id' => $docId, 'decision' => $decision];
  }

  private static function reviewerLabel(string $stage): string {
    return 'Section chief';
  }

  private static function canSubmit(array $doc): bool {
    $status = strtolower(trim((string)($doc['status'] ?? 'Draft')));
    if (in_array($status, ['approved', 'to be reviewed'], true)) {
      return false;
    }

    return (int)($doc['approval_locked'] ?? 0) !== 1;
  }

  private static function documentRouteNote(string $action, array $tracking): string {
    $title = trim((string)($tracking['title'] ?? ''));
    $label = $title !== '' ? $title : (string)($tracking['name'] ?? '');
    return match ($action) {
      'submit' => 'Document submitted for section chief review.',
      'reject' => 'Document review rejected.',
      default => 'Document route updated: ' . $label,
    };
  }
}
