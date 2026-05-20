<?php
require_once __DIR__ . "/../services/DocumentReviewService.php";

function submit_document_for_review(): void {
  global $pdo;
  csrf_verify();
  // Only admins may submit documents for routing/review initiation
  require_once __DIR__ . "/../middleware/require_role.php";
  require_role('ADMIN');

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }

  try {
    DocumentReviewService::submitForReview($pdo, $doc, $uid);
  } catch (RuntimeException $e) {
    $error = $e->getMessage();
    if ($error === 'forbidden') {
      http_response_code(403);
      die("403 owner only");
    }
    redirect('/documents/view?id='.$docId.'&err=' . urlencode($error) . '&user_id='.(int)$doc['owner_id']);
  }

  redirect('/documents/view?id='.$docId.'&msg=submitted_for_review');
}

function accept_review_assignment(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }
  if (!can_review_document($doc, $uid)) {
    http_response_code(403);
    die("403 reviewer only");
  }

  $stageLabel = 'Section Chief';
  Document::acceptReviewAssignment($pdo, $docId);
  Document::updateTrackingState($pdo, $docId, $stageLabel . ' Review Workspace', 'IN_REVIEW');
  Document::markRouteActive($pdo, $docId);
  DocumentRoute::add($pdo, $docId, $stageLabel . ' Review Queue', $stageLabel . ' Review Workspace', 'IN_REVIEW', strtolower($stageLabel) . ' accepted the routed document for review.', $uid);
  Notification::add($pdo, (int)$doc['owner_id'], $stageLabel . " accepted your document", (string)($doc['title'] ?? $doc['name'] ?? ''), "/documents/view?id=".$docId);
  AuditLog::add($pdo, $uid, "Accepted review assignment", $docId, null);
  redirect('/documents/view?id='.$docId.'&msg=review_assignment_accepted');
}

function decline_review_assignment(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);
  $note = trim(req_str('response_note', ''));
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }
  if (!can_review_document($doc, $uid)) {
    http_response_code(403);
    die("403 reviewer only");
  }
  if ($note === '') {
    redirect('/documents/view?id='.$docId.'&err=response_note_required');
  }

  Document::declineReviewAssignment($pdo, $docId, $note);
  $stageLabel = 'Section chief';
  Document::updateTrackingState($pdo, $docId, $stageLabel . ' review declined', 'REVIEW_ASSIGNMENT_DECLINED');
  Document::closeRoute($pdo, $docId, 'RETURNED');
  DocumentRoute::add($pdo, $docId, ucwords($stageLabel) . ' Review Queue', $stageLabel . ' review declined', 'REVIEW_ASSIGNMENT_DECLINED', $note, $uid);
  Notification::add($pdo, (int)$doc['owner_id'], ucfirst($stageLabel) . " did not accept the document yet", $note, "/documents/view?id=".$docId);
  AuditLog::add($pdo, $uid, "Declined review assignment", $docId, $note);
  redirect('/admin/dashboard?msg=review_assignment_declined');
}

function escalate_review_to_division_chief(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);
  $note = trim(req_str('escalation_note', ''));
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }
  if (!can_review_document($doc, $uid)) {
    http_response_code(403);
    die("403 reviewer only");
  }

  redirect('/documents/view?id='.$docId.'&err=' . urlencode('escalation_not_available'));
}

function review_document_decision(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);
  $doc = Document::get($pdo, $docId);
  if (!$doc) { redirect('/documents?err=not_found'); }
  $sharedReviewerRow = Permission::findRowForUser($pdo, $docId, $uid);
  $isDirectSharedChiefReview = current_role() === 'DIVISION_CHIEF'
    && !empty($sharedReviewerRow['accepted_at'])
    && strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE')) === 'SHARE_ACCEPTED';
  if (!can_review_document($doc, $uid) && !$isDirectSharedChiefReview) { http_response_code(403); die("403 reviewer only"); }
  if (!$isDirectSharedChiefReview && (string)($doc['status'] ?? 'Draft') !== 'To be reviewed') {
    redirect('/documents/view?id='.$docId.'&err=decision_already_final');
  }
  if (!$isDirectSharedChiefReview && strtoupper((string)($doc['review_acceptance_status'] ?? 'NOT_SENT')) !== 'ACCEPTED') {
    redirect('/documents/view?id='.$docId.'&err=review_acceptance_required');
  }

  try {
    $result = DocumentReviewService::finalizeDecision($pdo, $doc, $uid, strtoupper(req_str('decision', '')), trim(req_str('reject_note', '')));
  } catch (RuntimeException $e) {
    redirect('/documents/view?id='.$docId.'&err=' . urlencode($e->getMessage()));
  }

  redirect('/documents/view?id='.$docId.'&msg=' . (($result['decision'] ?? '') === 'APPROVED' ? 'document_approved' : 'document_rejected'));
}
