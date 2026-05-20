<?php require_once __DIR__ . "/../../helpers/csrf.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
$currentUserId = (int)($_SESSION['user']['id'] ?? 0);
$role = strtoupper((string)($_SESSION['user']['role'] ?? 'EMPLOYEE'));
$isOwner = (int)$doc['owner_id'] === $currentUserId;
$isReviewer = false;
$canEditFile = false;
$isPendingSharedRecipient = false;
$isDeclinedSharedRecipient = false;
$isPendingReviewer = false;
$isDeclinedReviewer = false;
$canForwardFile = false;
$backTab = 'routed';
$categoryLabel = trim((string)($doc['category'] ?? '')) !== '' ? (string)$doc['category'] : 'No category';
$preview = $preview ?? ['kind' => 'none', 'message' => 'No preview available.'];
$statusLabel = trim((string)($doc['status'] ?? 'Draft'));
$docExt = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
$isPdfDoc = $docExt === 'pdf';
$isWordDoc = in_array($docExt, ['doc', 'docx'], true);
$isSpreadsheetDoc = in_array($docExt, ['xls', 'xlsx'], true);
$editorLabel = 'PDF Preview';
$signatoryLabel = trim((string)($doc['signatory'] ?? '')) !== '' ? (string)$doc['signatory'] : 'Not set';
$tagsLabel = trim((string)($doc['tags'] ?? '')) !== '' ? (string)$doc['tags'] : 'No tags';
$isMaintenanceDoc = !$isPdfDoc;
$googleDocsEligible = $isWordDoc;
$publicFileUrl = app_url('/documents/file?id=' . (int)$doc['id'] . '&sig=' . DocumentService::signedDocumentToken((int)$doc['id']));
$googleDocsAvailable = false;
$googleDocsUrl = '';
$spreadsheetLaunchToken = $isSpreadsheetDoc && $currentUserId > 0
  ? DocumentService::createSpreadsheetLaunchToken((int)$doc['id'], $currentUserId)
  : '';
$wordLaunchToken = $isWordDoc && $currentUserId > 0
  ? DocumentService::createWordLaunchToken((int)$doc['id'], $currentUserId)
  : '';
$buildEditorDocumentName = static function (string $fallbackFileName, string $title): string {
  $fallback = trim($fallbackFileName);
  $ext = strtolower((string)pathinfo($fallback, PATHINFO_EXTENSION));
  $candidate = trim($title);
  if ($candidate === '') {
    return $fallback !== '' ? $fallback : ($ext !== '' ? 'document.' . $ext : 'document');
  }
  if ($ext !== '' && !preg_match('/\.' . preg_quote($ext, '/') . '$/i', $candidate)) {
    return $candidate . '.' . $ext;
  }
  return $candidate;
};
$editorDocumentName = $buildEditorDocumentName((string)($doc['name'] ?? ''), trim((string)($doc['title'] ?? '')));
$spreadsheetEditorUrl = BASE_URL . '/tools/spreadsheet/index.html'
  . '?apiBase=' . rawurlencode((string)BASE_URL . '/api')
  . '&cddftsUserId=' . rawurlencode((string)($currentUserId > 0 ? $currentUserId : 'CDD-File-Tracking-System-user'))
  . '&cddftsUserName=' . rawurlencode((string)($_SESSION['user']['name'] ?? 'CDD-File-Tracking-System User'))
  . '&cddftsUserEmail=' . rawurlencode((string)($_SESSION['user']['email'] ?? 'CDD-File-Tracking-System-user@local'))
  . '&documentId=' . (int)$doc['id']
  . '&documentName=' . rawurlencode($editorDocumentName)
  . '&fileUrl=' . rawurlencode((string)$publicFileUrl)
  . '&launchToken=' . rawurlencode($spreadsheetLaunchToken)
  . '&uiVersion=20260423a';
$wordEditorUrl = BASE_URL . '/tools/word/index.html'
  . '?apiBase=' . rawurlencode((string)BASE_URL . '/api')
  . '&cddftsUserId=' . rawurlencode((string)($currentUserId > 0 ? $currentUserId : 'CDD-File-Tracking-System-user'))
  . '&cddftsUserName=' . rawurlencode((string)($_SESSION['user']['name'] ?? 'CDD-File-Tracking-System User'))
  . '&cddftsUserEmail=' . rawurlencode((string)($_SESSION['user']['email'] ?? 'CDD-File-Tracking-System-user@local'))
  . '&documentId=' . (int)$doc['id']
  . '&documentName=' . rawurlencode($editorDocumentName)
  . '&fileUrl=' . rawurlencode((string)$publicFileUrl)
  . '&launchToken=' . rawurlencode($wordLaunchToken)
  . '&uiVersion=20260423b';
$externalEditorRequested = req_str('open_editor', '') === '1';
$shouldOpenExternalEditor = false;
$hasEmbeddedEditor = false;
$docTitle = trim((string)($doc['title'] ?? '')) !== '' ? (string)$doc['title'] : (string)$doc['name'];
$docCode = trim((string)($doc['document_code'] ?? '')) !== '' ? (string)$doc['document_code'] : 'Uncoded';
$directionLabel = strtoupper((string)($doc['document_type'] ?? 'INCOMING')) === 'OUTGOING' ? 'Outgoing' : 'Incoming';
$routingLabel = strtoupper((string)($doc['routing_status'] ?? 'NOT_ROUTED')) === 'ROUTED' ? 'Routed' : 'Not routed';
$routingLabel = match (strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'))) {
  'PENDING_SHARE_ACCEPTANCE' => 'Shared with recipient',
  'SHARE_ACCEPTED' => 'Shared and active',
  'SHARE_DECLINED' => 'Returned to owner',
  'PENDING_REVIEW_ACCEPTANCE' => 'Pending section chief acceptance',
  'IN_REVIEW' => 'In section chief review',
  'REVIEW_ASSIGNMENT_DECLINED' => 'Returned to owner',
  'APPROVED' => 'Approved',
  'REJECTED' => 'Rejected',
  'COMPLETED' => 'Routing completed',
  default => 'Available with owner',
};
$priorityLabel = match (strtoupper((string)($doc['priority_level'] ?? 'MODERATE'))) {
  'LOW' => 'Low',
  'MODERATE', 'NORMAL' => 'Moderate',
  'HIGH' => 'High',
  'RUSH', 'URGENT' => 'Rush',
  default => 'Moderate',
};
$documentDateLabel = trim((string)($doc['document_date'] ?? '')) !== '' ? (string)$doc['document_date'] : 'Not set';
$shareRecipients = $shareRecipients ?? [];
$routeAssignmentRoleLabel = in_array($role, ['SECTION_ADMIN', 'DIVISION_CHIEF'], true) ? 'Section Staff' : 'Section Admin';
$routeAssignmentGroupLabel = $routeAssignmentRoleLabel === 'Section Staff' ? 'Section Staff' : 'Section Admins';
$routeAssignmentDescription = $routeAssignmentRoleLabel === 'Section Staff'
  ? 'Assign this routed file to a Section Staff member in your section.'
  : 'Assign this routed file to a Section Admin for the appropriate section.';
$shareRecipientGroups = [];
foreach ($shareRecipients as $recipient) {
  $groupLabel = trim((string)($recipient['division_name'] ?? '')) !== '' ? (string)$recipient['division_name'] : 'No division';
  $chiefLabel = trim((string)($recipient['chief_name'] ?? '')) !== '' ? (string)$recipient['chief_name'] : 'No section chief assigned';
  $groupKey = $groupLabel . '||' . $chiefLabel;
  if (!isset($shareRecipientGroups[$groupKey])) {
    $shareRecipientGroups[$groupKey] = [
      'division_name' => $groupLabel,
      'chief_name' => $chiefLabel,
      'items' => [],
    ];
  }
  $shareRecipientGroups[$groupKey]['items'][] = $recipient;
}
$roleLabels = [
  'SUPER_ADMIN' => 'CDD Super Admin',
  'ADMIN' => 'CDD Super Admin',
  'SECTION_ADMIN' => 'Section Admin',
  'DIVISION_CHIEF' => 'Section Admin',
  'SECTION_STAFF' => 'Section Staff',
  'EMPLOYEE' => 'Section Staff',
];
$currentShareRow = null;
foreach (($shared ?? []) as $sharedRow) {
  if ((int)($sharedRow['user_id'] ?? 0) === $currentUserId) {
    $currentShareRow = $sharedRow;
    break;
  }
}
$shareDeclineNote = trim((string)($currentShareRow['response_note'] ?? ''));
$reviewDeclineNote = trim((string)($doc['review_acceptance_note'] ?? ''));
$routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
$reviewStage = 'SECTION_REVIEW';
$reviewerTitle = 'Section Admin';
$isAcceptedAssignedReviewer = $isReviewer
  && (int)($doc['assigned_reviewer_id'] ?? 0) === $currentUserId
  && strtoupper((string)($doc['review_acceptance_status'] ?? 'NOT_SENT')) === 'ACCEPTED'
  && $reviewStage === 'SECTION_REVIEW';
$canEscalateReview = false;
$isAcceptedSharedChiefReviewer = $role === 'DIVISION_CHIEF'
  && !empty($currentShareRow['accepted_at'])
  && $routingStatus === 'SHARE_ACCEPTED';
$routeOutcomeLabel = match (strtoupper(trim((string)($doc['route_outcome'] ?? 'ACTIVE')))) {
  'APPROVED' => 'Approved',
  'RETURNED' => 'Returned',
  'REJECTED' => 'Rejected',
  'COMPLETED' => 'Completed',
  'ARCHIVED' => 'Archived',
  default => 'Active',
};
$routeClosedAt = trim((string)($doc['route_closed_at'] ?? ''));
$isClosedRoute = strtoupper(trim((string)($doc['route_outcome'] ?? 'ACTIVE'))) !== 'ACTIVE' || in_array($routingStatus, ['APPROVED', 'REJECTED', 'COMPLETED'], true);
$isShareLocked = match ($routingStatus) {
  'APPROVED', 'REJECTED', 'COMPLETED' => true,
  'PENDING_SHARE_ACCEPTANCE', 'PENDING_REVIEW_ACCEPTANCE' => true,
  'SHARE_ACCEPTED' => !in_array($level, ['admin', 'editor', 'viewer'], true),
  'IN_REVIEW' => !in_array($level, ['admin', 'division_chief'], true),
  default => false,
} || $isClosedRoute;
$isSectionAdminRelayLocked = in_array($role, ['SECTION_ADMIN', 'DIVISION_CHIEF'], true) && $routingStatus === 'SHARE_ACCEPTED' && !$isClosedRoute;
$isShareLocked = $isShareLocked || $isSectionAdminRelayLocked;
$shareLockMessage = match (true) {
  in_array($routingStatus, ['COMPLETED', 'APPROVED', 'REJECTED'], true) || $isClosedRoute
    => 'This route is already finalized, so this file cannot be shared again from this route panel.',
  $isSectionAdminRelayLocked
    => 'This routed file is already with the assigned section staff, so the section admin cannot forward it to another staff member from this route panel.',
  in_array($routingStatus, ['PENDING_SHARE_ACCEPTANCE', 'PENDING_REVIEW_ACCEPTANCE', 'SHARE_ACCEPTED', 'IN_REVIEW'], true)
    => 'Routing is active right now, so this file cannot be shared again until it returns to the owner or reaches a final decision.',
  default
    => 'This file cannot be shared again right now.',
};
$canCompleteRoute = in_array($level, ['editor', 'viewer', 'division_chief'], true)
  && !in_array($role, ['SECTION_ADMIN', 'DIVISION_CHIEF'], true)
  && !$isClosedRoute;
$routes = $routes ?? [];
$routesChronological = array_reverse($routes);
$latestRouteNote = trim((string)($routes[0]['note'] ?? ''));
$routeActionInstruction = '';
$instructionPrefix = 'Actions to be taken:';
if ($latestRouteNote !== '') {
  $prefixPos = stripos($latestRouteNote, $instructionPrefix);
  if ($prefixPos !== false) {
    $routeActionInstruction = trim(substr($latestRouteNote, $prefixPos + strlen($instructionPrefix)));
  }
}
$routeStops = [];
$routeStopKeySet = [];
$pushRouteStop = static function (?string $label) use (&$routeStops, &$routeStopKeySet): void {
  $clean = trim((string)$label);
  if ($clean === '') {
    return;
  }
  $key = strtolower($clean);
  if (isset($routeStopKeySet[$key])) {
    return;
  }
  $routeStopKeySet[$key] = true;
  $routeStops[] = $clean;
};
foreach ($routesChronological as $routeRow) {
  $pushRouteStop((string)($routeRow['from_location'] ?? ''));
  $pushRouteStop((string)($routeRow['to_location'] ?? ''));
}
if (empty($routeStops)) {
  $pushRouteStop((string)($doc['current_location'] ?? ''));
}
if (empty($routeStops)) {
  $pushRouteStop((string)($doc['owner_name'] ?? 'Owner'));
}
$routeVisualStops = array_slice($routeStops, 0, 4);
$routeHeadline = $routeClosedAt !== ''
  ? 'Closed: ' . $routeOutcomeLabel
  : ($routingStatus === 'AVAILABLE' ? 'Available with owner' : 'Active route');
$routeStatusTone = $routeClosedAt !== ''
  ? 'finished'
  : (count($routeVisualStops) > 1 ? 'moving' : 'idle');
$routeFileKind = match (true) {
  $isSpreadsheetDoc => 'spreadsheet',
  $isWordDoc => 'document',
  $isPdfDoc => 'pdf',
  in_array($docExt, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg'], true) => 'image',
  in_array($docExt, ['mp4', 'webm', 'mov'], true) => 'video',
  default => 'file',
};
$routeFileKindLabel = strtoupper($docExt !== '' ? $docExt : $routeFileKind);
$routeLastIndex = max(0, count($routeVisualStops) - 1);
$routeStatusLabel = $routeClosedAt !== '' ? $routeOutcomeLabel : $routingLabel;
$routeCurrentStop = $routeVisualStops[$routeLastIndex] ?? ((string)($doc['current_location'] ?? 'Owner'));
$routeClosedAtLabel = $routeClosedAt;
if ($routeClosedAt !== '') {
  $routeClosedTimestamp = strtotime($routeClosedAt);
  if ($routeClosedTimestamp !== false) {
    $routeClosedAtLabel = date('M j, Y g:i A', $routeClosedTimestamp);
  }
}
$routeCompletedByLabel = '';
if ($routingStatus === 'COMPLETED' || strtoupper(trim((string)($doc['route_outcome'] ?? 'ACTIVE'))) === 'COMPLETED') {
  $routeCompletedByLabel = $routeCurrentStop;
  if (str_starts_with($routeCompletedByLabel, 'Shared with ')) {
    $routeCompletedByLabel = substr($routeCompletedByLabel, strlen('Shared with '));
  }
}
$shared = $shared ?? [];
$shareStatusCounts = [
  'accepted' => 0,
  'declined' => 0,
  'locked' => 0,
];
$shareStatusItems = [];
foreach ($shared as $sharedRow) {
  $shareName = trim((string)($sharedRow['name'] ?? $sharedRow['email'] ?? 'Shared user'));
  $shareState = 'active';
  $shareLabel = '';
  if ($isShareLocked) {
    $shareState = 'locked';
    $shareLabel = '';
  } elseif (!empty($sharedRow['declined_at'])) {
    $shareState = 'active';
    $shareLabel = '';
  }
  $shareStatusItems[] = [
    'name' => $shareName,
    'state' => $shareState,
    'label' => $shareLabel,
  ];
}
$shareStatusPreview = array_slice($shareStatusItems, 0, 4);
$shareStatusOverflow = max(0, count($shareStatusItems) - count($shareStatusPreview));
$shareSummaryText = '';
$hasShareRecipients = !empty($shareRecipients);
$canShareForward = in_array($role, ['SUPER_ADMIN', 'ADMIN', 'SECTION_ADMIN', 'DIVISION_CHIEF'], true) && $hasShareRecipients;
$wordEditorReadOnly = false;
$wordEditorLockReason = '';

require __DIR__ . "/../layouts/header.php";

if ($isMaintenanceDoc):
  $maintenanceLabel = 'Routing Preview';
  $maintenanceCopy = 'Editing is disabled. This file is available for routing and preview only.';
?>
<style>
  .document-maintenance {
    min-height: calc(100vh - 120px);
    display: grid;
    place-items: center;
    padding: 2rem 1rem 3rem;
  }

  .document-maintenance__card {
    width: min(100%, 720px);
    padding: 3rem 2rem;
    border: 1px solid rgba(72, 112, 137, 0.18);
    border-radius: 24px;
    background: linear-gradient(180deg, rgba(255, 255, 255, 0.96), rgba(244, 249, 253, 0.92));
    box-shadow: 0 20px 48px rgba(20, 45, 71, 0.10);
    text-align: center;
  }

  .document-maintenance__eyebrow {
    margin-bottom: 0.75rem;
    color: #5d7a91;
    font-size: 0.82rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
  }

  .document-maintenance__title {
    margin: 0;
    color: #18354d;
    font-size: clamp(2rem, 4vw, 3.25rem);
    font-weight: 700;
    letter-spacing: -0.04em;
  }

  .document-maintenance__copy {
    max-width: 520px;
    margin: 1rem auto 0;
    color: #587189;
    font-size: 1rem;
    line-height: 1.7;
  }

  .document-maintenance__actions {
    display: flex;
    justify-content: center;
    gap: 0.75rem;
    flex-wrap: wrap;
    margin-top: 1.5rem;
  }

  @media (max-width: 576px) {
    .document-maintenance__card {
      padding: 2.25rem 1.25rem;
      border-radius: 20px;
    }
  }
</style>

<section class="document-maintenance container-fluid px-4 px-lg-5">
  <div class="document-maintenance__card">
    <div class="document-maintenance__eyebrow"><?= e($maintenanceLabel) ?></div>
    <h1 class="document-maintenance__title">Under Construction</h1>
    <p class="document-maintenance__copy">
      <?= e($maintenanceCopy) ?>
    </p>
    <div class="document-maintenance__actions">
      <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/admin/dashboard">Back</a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/documents/download?id=<?= (int)$doc['id'] ?>">Download</a>
    </div>
  </div>
</section>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
<?php return; endif; ?>

<?php if($isWordDoc && $canViewFile): ?>
<style>
  html,
  body {
    height: 100%;
    overflow: hidden;
  }

  body {
    overscroll-behavior: none;
  }

  .word-editor-page {
    position: relative;
    width: 100vw;
    margin-left: calc(50% - 50vw);
    margin-right: calc(50% - 50vw);
    margin-top: -1rem;
    min-height: calc(100vh - 76px);
    background: linear-gradient(180deg, #eef4fb 0%, #f8fbff 100%);
  }

  .word-editor-page__frame-shell {
    display: flex;
    flex-direction: column;
    height: calc(100vh - 76px);
    border-top: 1px solid rgba(93, 128, 161, 0.12);
    border-bottom: 1px solid rgba(93, 128, 161, 0.12);
    overflow: hidden;
    background: #f6f9fc;
  }

  .word-editor-page__frame-head {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    align-items: center;
    gap: 1rem;
    padding: 0.85rem 1rem;
    border-bottom: 1px solid rgba(93, 128, 161, 0.14);
    background: rgba(246, 250, 254, 0.94);
    backdrop-filter: blur(12px);
  }

  .word-editor-page__toolbar {
    display: flex;
    align-items: center;
    gap: 0.75rem;
    min-width: 0;
    flex-wrap: wrap;
  }

  .word-editor-page__frame-note {
    color: #1d3b5a;
    font-size: 0.92rem;
    font-weight: 600;
  }

  .word-editor-page__title {
    color: #54708a;
    font-size: 0.78rem;
    font-weight: 700;
    letter-spacing: 0.12em;
    text-transform: uppercase;
  }

  .word-editor-page__frame {
    flex: 1 1 auto;
    min-height: 0;
    display: block;
    width: 100%;
    height: 100%;
    border: 0;
    background: #f6f9fc;
  }

  .word-editor-page__lock {
    margin: 0;
    padding: 0.45rem 0.75rem;
    border-radius: 18px;
    background: rgba(29, 59, 90, 0.08);
    color: #1d3b5a;
    font-size: 0.88rem;
    line-height: 1.5;
  }

  .word-editor-page__footer {
    z-index: 11;
    display: flex;
    align-items: center;
    gap: 1rem;
    flex-wrap: wrap;
    flex: 0 0 auto;
    padding: 0.7rem 1rem calc(0.7rem + env(safe-area-inset-bottom));
    border-top: 1px solid rgba(93, 128, 161, 0.16);
    background: rgba(247, 250, 254, 0.96);
    backdrop-filter: blur(10px);
    color: #284766;
    font-size: 0.95rem;
    font-weight: 500;
  }

  .word-editor-page__footer-item {
    white-space: nowrap;
  }

  .word-editor-page__footer-status {
    margin-left: auto;
    color: #4d6883;
  }

  .word-editor-page__footer-pill {
    display: inline-flex;
    align-items: center;
    padding: 0.32rem 0.72rem;
    border-radius: 999px;
    font-weight: 700;
    letter-spacing: 0.02em;
  }

  .word-editor-page__footer-pill--warning {
    background: #fff2d6;
    color: #9a5b00;
  }

  .word-editor-page__footer-pill--success {
    background: #daf5e4;
    color: #166534;
  }

  .word-editor-page__footer-pill--danger {
    background: #fde1de;
    color: #b42318;
  }

  .word-editor-page__footer-pill--info {
    background: #deefff;
    color: #175b9c;
  }

  .word-editor-page__footer-pill--neutral {
    background: #e8eef5;
    color: #425c78;
  }

  @media (max-width: 991px) {
    .word-editor-page__frame-head {
      padding: 0.8rem 1rem;
    }

    .word-editor-page__footer-status {
      margin-left: 0;
    }
  }
</style>

<section class="word-editor-page">
  <section class="word-editor-page__frame-shell">
    <div class="word-editor-page__frame-head">
      <div class="word-editor-page__toolbar">
        <div>
          <div class="word-editor-page__title"><?= e($docTitle) ?></div>
          <div class="word-editor-page__frame-note"><?= $wordEditorReadOnly ? 'Viewing current routed version' : 'Editing current routed version' ?></div>
        </div>
      </div>
      <?php if(req_str('msg') !== ''): ?>
        <span class="word-editor-page__lock"><?= e(ui_message(req_str('msg'))) ?></span>
      <?php elseif(req_str('err') !== ''): ?>
        <span class="word-editor-page__lock"><?= e(ui_message(req_str('err'))) ?></span>
      <?php elseif($wordEditorReadOnly && $wordEditorLockReason !== ''): ?>
        <span class="word-editor-page__lock"><?= e($wordEditorLockReason) ?></span>
      <?php endif; ?>
    </div>
    <iframe
      id="word-editor-frame"
      class="word-editor-page__frame"
      src="<?= e($wordEditorUrl) ?>"
      title="Word editor"
    ></iframe>
    <footer class="word-editor-page__footer" id="word-editor-footer">
      <span class="word-editor-page__footer-item" data-word-footer="workbook">Workbook: <?= e((string)($doc['name'] ?? 'document.docx')) ?></span>
      <span class="word-editor-page__footer-item" data-word-footer="sheet">Sheet: Document</span>
      <span class="word-editor-page__footer-item" data-word-footer="pages">Pages: 1</span>
      <span class="word-editor-page__footer-item" data-word-footer="characters">Characters: 0</span>
      <span class="word-editor-page__footer-item" data-word-footer="words">Words: 0</span>
      <span class="word-editor-page__footer-pill word-editor-page__footer-pill--neutral" data-word-footer="route">Route: Available</span>
      <span class="word-editor-page__footer-item word-editor-page__footer-status" data-word-footer="status">Loading document...</span>
    </footer>
  </section>

  <?php if($isAcceptedSharedChiefReviewer || $isAcceptedAssignedReviewer): ?>
    <section class="details-card" style="margin: 1rem;">
      <div class="details-card__title"><?= e($isAcceptedAssignedReviewer ? $reviewerTitle : 'Section Admin') ?> Decision</div>
      <div class="drive-note drive-note--soft mb-3">
        You already accepted this file. You can approve it or reject it with a note.
      </div>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <form method="POST" action="<?= BASE_URL ?>/documents/review/decision">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <input type="hidden" name="decision" value="APPROVED">
          <button class="btn btn-primary" type="submit">Approve file</button>
        </form>
        <?php if($canEscalateReview): ?>
          <form method="POST" action="<?= BASE_URL ?>/documents/review/escalate">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
            <button class="btn btn-outline-primary" type="submit">Escalation unavailable for now</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if($canEscalateReview): ?>
        <form method="POST" action="<?= BASE_URL ?>/documents/review/escalate" class="drive-form-stack mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <label class="form-label" for="escalation-note-full-editor">Escalation note</label>
          <textarea class="form-control drive-input" id="escalation-note-full-editor" name="escalation_note" rows="3" maxlength="1000" placeholder="Optional context for the section chief."></textarea>
          <div>
            <button class="btn btn-outline-primary" type="submit">Escalate with note</button>
          </div>
        </form>
      <?php endif; ?>
      <form method="POST" action="<?= BASE_URL ?>/documents/review/decision" class="drive-form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <input type="hidden" name="decision" value="REJECTED">
        <label class="form-label" for="shared-chief-reject-note-full-editor">Reject note</label>
        <textarea class="form-control drive-input" id="shared-chief-reject-note-full-editor" name="reject_note" rows="4" maxlength="1000" placeholder="Explain what needs to be corrected before routing again."><?= e(req_str('err') === 'reject_note_required' ? req_str('reject_note', '') : '') ?></textarea>
        <div>
          <button class="btn btn-outline-danger" type="submit">Reject file</button>
        </div>
      </form>
    </section>
  <?php endif; ?>
</section>

<script>
  (function () {
    const frame = document.getElementById('word-editor-frame');
    const footer = document.getElementById('word-editor-footer');
    if (!frame || !footer) {
      return;
    }

    const fields = {
      workbook: footer.querySelector('[data-word-footer="workbook"]'),
      sheet: footer.querySelector('[data-word-footer="sheet"]'),
      pages: footer.querySelector('[data-word-footer="pages"]'),
      characters: footer.querySelector('[data-word-footer="characters"]'),
      words: footer.querySelector('[data-word-footer="words"]'),
      route: footer.querySelector('[data-word-footer="route"]'),
      status: footer.querySelector('[data-word-footer="status"]')
    };

    const allowedTones = ['warning', 'success', 'danger', 'info', 'neutral'];

    window.addEventListener('message', function (event) {
      if (event.source !== frame.contentWindow) {
        return;
      }

      const data = event.data || {};
      if (data.type !== 'cddfts-word-footer-update' || !data.payload) {
        return;
      }

      const payload = data.payload;
      if (fields.workbook) fields.workbook.textContent = 'Workbook: ' + String(payload.workbook || 'document.docx');
      if (fields.sheet) fields.sheet.textContent = 'Sheet: ' + String(payload.sheet || 'Document');
      if (fields.pages) fields.pages.textContent = 'Pages: ' + String(payload.pages ?? 1);
      if (fields.characters) fields.characters.textContent = 'Characters: ' + String(payload.characters ?? 0);
      if (fields.words) fields.words.textContent = 'Words: ' + String(payload.words ?? 0);
      if (fields.status) fields.status.textContent = String(payload.statusText || 'Ready');
      if (fields.route) {
        const tone = allowedTones.includes(String(payload.routeTone || 'neutral')) ? String(payload.routeTone) : 'neutral';
        fields.route.className = 'word-editor-page__footer-pill word-editor-page__footer-pill--' + tone;
        fields.route.textContent = 'Route: ' + String(payload.routeLabel || 'Available');
      }
    });
  })();
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
<?php return; endif; ?>

<div class="workspace-page document-workspace-page<?= $hasEmbeddedEditor ? ' workspace-page--spreadsheet' : '' ?>">
  <?php if(req_str('msg') !== ''): ?>
    <div class="alert alert-success auto-dismiss"><?= e(ui_message(req_str('msg'))) ?></div>
  <?php endif; ?>
  <?php if(req_str('err') !== ''): ?>
    <div class="alert alert-danger auto-dismiss"><?= e(ui_message(req_str('err'))) ?></div>
  <?php endif; ?>
  <div class="document-view-stage<?= ($preview['kind'] ?? '') === 'pdf' ? ' document-view-stage--pdf' : '' ?>">
    <section class="document-view-stage__panel document-view-stage__panel--route">
      <details class="drive-note drive-note--soft<?= $hasEmbeddedEditor ? ' drive-note--compact' : '' ?> route-lifecycle route-lifecycle--<?= e($routeStatusTone) ?>" open>
        <summary class="route-lifecycle__summary">
          <div class="route-lifecycle__header">
            <div class="route-lifecycle__eyebrow">Route lifecycle</div>
            <strong class="route-lifecycle__headline"><?= e($routeHeadline) ?></strong>
            <div class="route-lifecycle__subline">
              <?php if($routeClosedAt !== ''): ?>
                <?php if($routeCompletedByLabel !== ''): ?>
                  <span>Completed by: <?= e($routeCompletedByLabel) ?></span>
                <?php else: ?>
                  <span>Final stop: <?= e($routeCurrentStop) ?></span>
                <?php endif; ?>
                <span><?= e($routeClosedAtLabel) ?></span>
              <?php else: ?>
                <span>Current stop: <?= e($routeCurrentStop) ?></span>
                <span><?= e(count($routeVisualStops) > 1 ? (count($routeVisualStops) . ' route points tracked') : 'Waiting at current owner location') ?></span>
              <?php endif; ?>
            </div>
          </div>
        </summary>
        <div class="route-lifecycle__body">
          <div class="route-stepper">
            <div class="route-stepper__meta">
              <div class="route-stepper__file route-stepper__file--<?= e($routeFileKind) ?>">
                <span class="route-stepper__file-icon" aria-hidden="true">
                  <?php if($routeFileKind === 'spreadsheet'): ?>
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M6 3h9l3 3v15H6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 9h6M9 13h6M9 17h6M12 6v12" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round"/></svg>
                  <?php elseif($routeFileKind === 'document'): ?>
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M6 3h9l3 3v15H6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M9 10h6M9 14h6M9 18h4" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
                  <?php elseif($routeFileKind === 'pdf'): ?>
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M6 3h9l3 3v15H6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/><path d="M8.8 16.2c1.2-2 2-4.6 2.3-7.2.4 2 1.6 4.5 3.6 6.1-2-.2-4 .1-5.9 1.1Z" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
                  <?php elseif($routeFileKind === 'image'): ?>
                    <svg viewBox="0 0 24 24" focusable="false"><rect x="4" y="5" width="16" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="9" cy="10" r="1.6" fill="none" stroke="currentColor" stroke-width="1.6"/><path d="m7 16 3.5-3.5 2.5 2.5 2-2 2.5 3" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
                  <?php elseif($routeFileKind === 'video'): ?>
                    <svg viewBox="0 0 24 24" focusable="false"><rect x="4" y="5" width="12" height="14" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="m16 10 4-2v8l-4-2Z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  <?php else: ?>
                    <svg viewBox="0 0 24 24" focusable="false"><path d="M6 3h9l3 3v15H6z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>
                  <?php endif; ?>
                </span>
                <span class="route-stepper__file-label"><?= e($routeFileKindLabel) ?></span>
              </div>
              <div class="route-stepper__status route-stepper__status--<?= e($routeStatusTone) ?>">
                <span class="route-stepper__status-label">Status</span>
                <strong><?= e($routeStatusLabel) ?></strong>
              </div>
              <div class="route-stepper__actions">
                <?php if($canShareForward && !$isShareLocked): ?>
                  <button
                    type="button"
                    class="btn btn-primary"
                    data-bs-toggle="modal"
                    data-bs-target="#documentShareFileModal"
                    data-share-id="<?= (int)$doc['id'] ?>"
                    data-share-title="<?= e($docTitle) ?>"
                    data-share-routing="<?= e($routingLabel) ?>"
                    data-share-locked="<?= $isShareLocked ? '1' : '0' ?>"
                    data-share-locked-message="<?= e($shareLockMessage) ?>"
                  >Share to recipient</button>
                  <p class="route-stepper__actions-copy mb-0">
                    Choose the next person in this division and share the file forward.
                  </p>
                <?php elseif(!$isShareLocked && in_array($role, ['SUPER_ADMIN', 'ADMIN', 'SECTION_ADMIN', 'DIVISION_CHIEF'], true)): ?>
                  <button type="button" class="btn btn-outline-secondary" disabled>No recipients available</button>
                  <p class="route-stepper__actions-copy mb-0">
                    No eligible recipient is available in this division yet, so routing cannot continue from this page.
                  </p>
                <?php endif; ?>
                <?php if($canCompleteRoute): ?>
                  <form method="POST" action="<?= BASE_URL ?>/documents/route/complete" class="mt-2">
                    <?= csrf_field() ?>
                    <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
                    <button class="btn btn-outline-success" type="submit">Complete Route</button>
                  </form>
                  <p class="route-stepper__actions-copy mb-0 mt-2">
                    Finish your step and mark this route complete to keep the file with the final routed holder.
                  </p>
                <?php endif; ?>
              </div>
            </div>
          </div>
          <?php if($routeActionInstruction !== ''): ?>
            <div class="route-share-summary route-share-summary--instruction">
              <div class="route-share-summary__header">
                <span class="route-share-summary__title">Actions to be taken</span>
              </div>
              <div class="route-share-summary__instruction"><?= e($routeActionInstruction) ?></div>
            </div>
          <?php endif; ?>
          <div class="route-stepper__steps">
            <?php foreach ($routeVisualStops as $index => $stopLabel): ?>
              <div class="route-stepper__step<?= $index < $routeLastIndex ? ' is-complete' : '' ?><?= $index === $routeLastIndex ? ' is-current' : '' ?>">
                <span class="route-stepper__node">
                  <?php if($index === $routeLastIndex && $routeClosedAt !== ''): ?>
                    <span class="route-stepper__flag-icon" aria-hidden="true"></span>
                  <?php endif; ?>
                </span>
                <span class="route-stepper__label"><?= e($stopLabel) ?></span>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if(!empty($shareStatusItems)): ?>
            <div class="route-share-summary">
              <div class="route-share-summary__header">
                <span class="route-share-summary__title">Shared recipients</span>
                <?php if($shareSummaryText !== ''): ?>
                  <span class="route-share-summary__meta"><?= e($shareSummaryText) ?></span>
                <?php endif; ?>
              </div>
              <div class="route-share-summary__chips">
                <?php foreach ($shareStatusPreview as $shareItem): ?>
                  <span class="route-share-chip route-share-chip--<?= e($shareItem['state']) ?>">
                    <span class="route-share-chip__name"><?= e($shareItem['name']) ?></span>
                    <?php if($shareItem['label'] !== ''): ?>
                      <span class="route-share-chip__status"><?= e($shareItem['label']) ?></span>
                    <?php endif; ?>
                  </span>
                <?php endforeach; ?>
                <?php if($shareStatusOverflow > 0): ?>
                  <span class="route-share-chip route-share-chip--overflow">+<?= (int)$shareStatusOverflow ?> more</span>
                <?php endif; ?>
              </div>
              <?php if($isShareLocked): ?>
                <div class="route-share-summary__lock">Shared access is finalized for this routed file.</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>
      </details>
    </section>

    <section class="document-view-stage__panel document-view-stage__panel--preview">
      <section class="details-card document-preview-card<?= $hasEmbeddedEditor ? ' document-preview-card--spreadsheet document-preview-card--embedded' : '' ?><?= ($preview['kind'] ?? '') === 'pdf' ? ' document-preview-card--pdf' : '' ?>">
        <?php if(!$hasEmbeddedEditor): ?>
          <div class="details-card__title"><?= e($editorLabel) ?></div>
        <?php endif; ?>
        <?php if($isWordDoc && $canViewFile): ?>
          <iframe
            class="document-preview-frame document-preview-frame--spreadsheet"
            src="<?= e($wordEditorUrl) ?>"
            title="Word editor"
          ></iframe>
        <?php elseif($isSpreadsheetDoc && $canViewFile): ?>
          <iframe
            class="document-preview-frame document-preview-frame--spreadsheet"
            src="<?= e($spreadsheetEditorUrl) ?>"
            title="Spreadsheet editor"
          ></iframe>
        <?php elseif(($preview['kind'] ?? '') === 'pdf'): ?>
          <iframe
            class="document-preview-frame"
            src="<?= e((string)($preview['url'] ?? '')) ?>"
            title="PDF preview"
          ></iframe>
        <?php elseif(($preview['kind'] ?? '') === 'video'): ?>
          <div class="document-preview-media text-center">
            <video
              class="document-preview-video"
              src="<?= e((string)($preview['url'] ?? '')) ?>"
              controls
              preload="metadata"
            >
              Your browser does not support inline video playback.
            </video>
          </div>
        <?php elseif(($preview['kind'] ?? '') === 'image'): ?>
          <div class="document-preview-media text-center">
            <img
              class="document-preview-image"
              src="<?= e((string)($preview['url'] ?? '')) ?>"
              alt="<?= e((string)$doc['name']) ?>"
            >
          </div>
        <?php elseif(($preview['kind'] ?? '') === 'docx-html'): ?>
          <div class="document-preview-text document-preview-docx">
            <?= (string)($preview['html'] ?? '') ?>
          </div>
          <div class="text-muted small mt-2">DOCX preview is shown as a lightweight browser rendering of the document, including embedded images when available.</div>
        <?php elseif(in_array((string)($preview['kind'] ?? ''), ['text', 'docx-text'], true)): ?>
          <div class="document-preview-text">
            <pre><?= e((string)($preview['text'] ?? '')) ?></pre>
          </div>
          <?php if(($preview['kind'] ?? '') === 'docx-text'): ?>
            <div class="text-muted small mt-2">DOCX preview is shown as extracted text for reading before download.</div>
          <?php endif; ?>
        <?php else: ?>
          <div class="drive-note drive-note--soft"><?= e((string)($preview['message'] ?? 'Preview unavailable.')) ?></div>
        <?php endif; ?>
      </section>
    </section>
  </div>

  <?php if($isPendingReviewer || $isDeclinedReviewer): ?>
    <section class="details-card mt-3">
      <div class="details-card__title">Workflow Response</div>

      <?php if($isPendingReviewer): ?>
        <div class="drive-note drive-note--soft mb-3">
          This routed file was submitted to you for <?= e(strtolower($reviewerTitle)) ?> review. Accept the assignment to start review actions.
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
          <form method="POST" action="<?= BASE_URL ?>/documents/review/accept">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
            <button class="btn btn-primary" type="submit">Accept review</button>
          </form>
        </div>
        <form method="POST" action="<?= BASE_URL ?>/documents/review/decline" class="drive-form-stack">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <label class="form-label" for="review-response-note">Reason if not accepting</label>
          <textarea class="form-control drive-input" id="review-response-note" name="response_note" rows="4" maxlength="1000" placeholder="Explain why you are declining this review assignment."><?= e(req_str('err') === 'response_note_required' ? req_str('response_note', '') : '') ?></textarea>
          <div>
            <button class="btn btn-outline-danger" type="submit">Decline review</button>
          </div>
        </form>
      <?php elseif($isDeclinedReviewer): ?>
        <div class="drive-note drive-note--soft mb-3">
          You previously declined this review assignment. You can accept it now if you are ready to continue.
        </div>
        <?php if($reviewDeclineNote !== ''): ?>
          <div class="alert alert-secondary mb-3">
            <strong>Your last decline note:</strong> <?= e($reviewDeclineNote) ?>
          </div>
        <?php endif; ?>
        <form method="POST" action="<?= BASE_URL ?>/documents/review/accept">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <button class="btn btn-primary" type="submit">Accept review now</button>
        </form>
      <?php endif; ?>
    </section>
  <?php endif; ?>

  <?php if($isAcceptedSharedChiefReviewer || $isAcceptedAssignedReviewer): ?>
    <section class="details-card mt-3">
      <div class="details-card__title"><?= e($isAcceptedAssignedReviewer ? $reviewerTitle : 'Section Admin') ?> Decision</div>
      <div class="drive-note drive-note--soft mb-3">
        You already accepted this file. You can approve it or reject it with a note.
      </div>
      <div class="d-flex flex-wrap gap-2 mb-3">
        <form method="POST" action="<?= BASE_URL ?>/documents/review/decision">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <input type="hidden" name="decision" value="APPROVED">
          <button class="btn btn-primary" type="submit">Approve file</button>
        </form>
        <?php if($canEscalateReview): ?>
          <form method="POST" action="<?= BASE_URL ?>/documents/review/escalate">
            <?= csrf_field() ?>
            <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
            <button class="btn btn-outline-primary" type="submit">Escalation unavailable for now</button>
          </form>
        <?php endif; ?>
      </div>
      <?php if($canEscalateReview): ?>
        <form method="POST" action="<?= BASE_URL ?>/documents/review/escalate" class="drive-form-stack mb-3">
          <?= csrf_field() ?>
          <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
          <label class="form-label" for="escalation-note">Escalation note</label>
          <textarea class="form-control drive-input" id="escalation-note" name="escalation_note" rows="3" maxlength="1000" placeholder="Optional context for the section chief."></textarea>
          <div>
            <button class="btn btn-outline-primary" type="submit">Escalate with note</button>
          </div>
        </form>
      <?php endif; ?>
      <form method="POST" action="<?= BASE_URL ?>/documents/review/decision" class="drive-form-stack">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= (int)$doc['id'] ?>">
        <input type="hidden" name="decision" value="REJECTED">
        <label class="form-label" for="shared-chief-reject-note">Reject note</label>
        <textarea class="form-control drive-input" id="shared-chief-reject-note" name="reject_note" rows="4" maxlength="1000" placeholder="Explain what needs to be corrected before routing again."><?= e(req_str('err') === 'reject_note_required' ? req_str('reject_note', '') : '') ?></textarea>
        <div>
          <button class="btn btn-outline-danger" type="submit">Reject file</button>
        </div>
      </form>
    </section>
  <?php endif; ?>

</div>

<?php if($canShareForward): ?>
<div class="modal fade workspace-file-details-modal" id="documentShareFileModal" tabindex="-1" aria-labelledby="documentShareFileModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content workspace-file-details-modal__content">
      <form id="document-share-file-form" method="POST" action="<?= BASE_URL ?>/documents/share" class="drive-form-stack">
        <?= csrf_field() ?>
        <div class="modal-header border-0 pb-0">
          <div>
            <h5 class="modal-title" id="documentShareFileModalLabel">Route and share file</h5>
            <p class="workspace-filter-modal__intro mb-0" data-share-field="title"><?= e($routeAssignmentDescription) ?></p>
          </div>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body pt-3">
          <input type="hidden" name="document_id" value="">
          <div class="drive-note drive-note--soft mb-3">
            <strong>Current route status:</strong> <span data-share-field="routing">Available with owner</span>
          </div>
          <div class="drive-note drive-note--soft mb-3 d-none" data-share-field="locked-note">
            This file cannot be shared again right now.
          </div>
          <div data-share-field="form-fields">
            <input type="hidden" name="permission" value="editor">
            <div class="share-combobox__label-row">
              <span class="share-combobox__eyebrow">Assign <?= e($routeAssignmentRoleLabel) ?></span>
              <span class="share-combobox__hint">Each option includes the account's section and contact email.</span>
            </div>
            <div class="share-combobox mb-2" id="document-share-recipient-combobox">
              <input class="form-control drive-input share-combobox__input" type="search" id="document-share-recipient-search" placeholder="Search <?= e($routeAssignmentRoleLabel) ?> by name, section, or email" autocomplete="off">
              <div class="share-combobox__panel d-none" id="document-share-recipient-panel">
                <?php foreach($shareRecipientGroups as $group): ?>
                  <?php foreach($group['items'] as $recipient): ?>
                    <?php $recipientRole = $roleLabels[strtoupper((string)($recipient['role'] ?? 'EMPLOYEE'))] ?? 'User'; ?>
                    <?php $recipientSection = trim((string)($recipient['division_name'] ?? '')) !== '' ? (string)$recipient['division_name'] : 'No section assigned'; ?>
                    <?php $recipientLabel = (string)$recipient['name'] . ' - ' . $recipientSection . ' - ' . $recipientRole; ?>
                    <button
                      type="button"
                      class="share-combobox__option"
                      data-value="<?= (int)$recipient['id'] ?>"
                      data-search="<?= e(strtolower(trim((string)($recipient['name'] ?? '') . ' ' . (string)($recipient['email'] ?? '') . ' ' . $recipientRole . ' ' . (string)($recipient['division_name'] ?? '')))) ?>"
                      data-label="<?= e($recipientLabel) ?>"
                    >
                      <span class="share-combobox__option-name"><?= e((string)$recipient['name']) ?></span>
                      <span class="share-combobox__option-meta">
                        <span class="share-combobox__option-pill"><?= e($recipientRole) ?></span>
                        <span><?= e($recipientSection) ?></span>
                      </span>
                      <span class="share-combobox__option-email"><?= e((string)$recipient['email']) ?></span>
                    </button>
                  <?php endforeach; ?>
                <?php endforeach; ?>
              </div>
            </div>
            <select class="form-select drive-input mb-2 d-none" name="target_user_id" id="document-share-recipient-select" required>
              <option value="">Select <?= e($routeAssignmentRoleLabel) ?></option>
              <?php foreach($shareRecipientGroups as $group): ?>
                <optgroup label="<?= e($group['division_name']) ?> | <?= e($routeAssignmentGroupLabel) ?>">
                  <?php foreach($group['items'] as $recipient): ?>
                    <?php $recipientRole = $roleLabels[strtoupper((string)($recipient['role'] ?? 'EMPLOYEE'))] ?? 'User'; ?>
                    <?php $recipientSection = trim((string)($recipient['division_name'] ?? '')) !== '' ? (string)$recipient['division_name'] : 'No section assigned'; ?>
                    <option
                      value="<?= (int)$recipient['id'] ?>"
                      data-search="<?= e(strtolower(trim((string)($recipient['name'] ?? '') . ' ' . (string)($recipient['email'] ?? '') . ' ' . $recipientRole . ' ' . (string)($recipient['division_name'] ?? '')))) ?>"
                    >
                      <?= e((string)$recipient['name']) ?> | <?= e($recipientSection) ?> | <?= e((string)$recipient['email']) ?>
                    </option>
                  <?php endforeach; ?>
                </optgroup>
              <?php endforeach; ?>
            </select>
            <?php if(in_array($role, ['SECTION_ADMIN', 'DIVISION_CHIEF'], true)): ?>
              <label class="form-label mt-3" for="document-share-action-instruction">Actions to be taken</label>
              <textarea
                class="form-control drive-input"
                id="document-share-action-instruction"
                name="action_instruction"
                rows="4"
                maxlength="1000"
                placeholder="Add the instructions that Section Staff should follow for this routed file."
              ></textarea>
              <div class="form-text">This note will appear in the routed file's active route panel for the assigned Section Staff.</div>
            <?php endif; ?>
          </div>
        </div>
        <div class="modal-footer border-0 pt-0">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
          <button class="btn btn-outline-primary" type="submit" data-share-field="submit">Share</button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<script>
  (function () {
    const initShareCombobox = function () {
      const searchInput = document.getElementById('document-share-recipient-search');
      const recipientSelect = document.getElementById('document-share-recipient-select');
      const panel = document.getElementById('document-share-recipient-panel');
      if (!searchInput || !recipientSelect || !panel) {
        return { reset: function () {} };
      }
      const optionButtons = Array.from(panel.querySelectorAll('.share-combobox__option'));
      let emptyState = panel.querySelector('.share-combobox__empty');
      if (!emptyState) {
        emptyState = document.createElement('div');
        emptyState.className = 'share-combobox__empty d-none';
        emptyState.textContent = 'No matching <?= e($routeAssignmentRoleLabel) ?> found.';
        panel.appendChild(emptyState);
      }
      const closePanel = function () {
        panel.classList.add('d-none');
      };
      const openPanel = function () {
        panel.classList.remove('d-none');
      };
      panel.addEventListener('mousedown', function (event) {
        event.preventDefault();
      });
      const renderOptions = function () {
        const query = String(searchInput.value || '').trim().toLowerCase();
        let visibleCount = 0;
        optionButtons.forEach(function (button) {
          const haystack = String(button.getAttribute('data-search') || '').toLowerCase();
          const matched = query === '' || haystack.indexOf(query) !== -1;
          button.classList.toggle('d-none', !matched);
          if (matched) {
            visibleCount += 1;
          }
        });
        emptyState.classList.toggle('d-none', visibleCount !== 0);
        openPanel();
      };
      optionButtons.forEach(function (button) {
        button.addEventListener('click', function () {
          const value = this.getAttribute('data-value') || '';
          const label = this.getAttribute('data-label') || this.textContent || '';
          recipientSelect.value = value;
          searchInput.value = label.trim();
          closePanel();
        });
      });
      searchInput.addEventListener('focus', renderOptions);
      searchInput.addEventListener('click', renderOptions);
      searchInput.addEventListener('input', function () {
        recipientSelect.value = '';
        renderOptions();
      });
      searchInput.addEventListener('blur', function () {
        window.setTimeout(closePanel, 180);
      });
      return {
        open: function () {
          renderOptions();
        },
        reset: function () {
          recipientSelect.value = '';
          searchInput.value = '';
          closePanel();
        },
      };
    };

    const modal = document.getElementById('documentShareFileModal');
    const form = document.getElementById('document-share-file-form');
    if (!modal || !form) return;

    const titleTarget = modal.querySelector('[data-share-field="title"]');
    const routingTarget = modal.querySelector('[data-share-field="routing"]');
    const lockedNote = modal.querySelector('[data-share-field="locked-note"]');
    const fieldsWrap = modal.querySelector('[data-share-field="form-fields"]');
    const submitButton = modal.querySelector('[data-share-field="submit"]');
    const docIdInput = form.querySelector('input[name="document_id"]');
    const actionInstructionInput = form.querySelector('textarea[name="action_instruction"]');
    const shareCombobox = initShareCombobox();
    modal.addEventListener('show.bs.modal', function (event) {
      const trigger = event.relatedTarget;
      const docId = trigger ? (trigger.getAttribute('data-share-id') || '') : '';
      const title = trigger ? (trigger.getAttribute('data-share-title') || 'Selected file') : 'Selected file';
      const routing = trigger ? (trigger.getAttribute('data-share-routing') || 'Available with owner') : 'Available with owner';
      const locked = trigger ? (trigger.getAttribute('data-share-locked') === '1') : false;
      const lockedMessage = trigger ? (trigger.getAttribute('data-share-locked-message') || 'This file cannot be shared again right now.') : 'This file cannot be shared again right now.';
      if (docIdInput) docIdInput.value = docId;
      if (titleTarget) titleTarget.textContent = title;
      if (routingTarget) routingTarget.textContent = routing;
      if (lockedNote) lockedNote.classList.toggle('d-none', !locked);
      if (lockedNote) lockedNote.textContent = lockedMessage;
      if (fieldsWrap) fieldsWrap.classList.toggle('d-none', locked);
      if (submitButton) submitButton.disabled = locked;
      if (actionInstructionInput) actionInstructionInput.value = '';
      shareCombobox.reset();
    });
    modal.addEventListener('shown.bs.modal', function () {
      const searchInput = document.getElementById('document-share-recipient-search');
      if (searchInput && !submitButton.disabled) {
        searchInput.focus();
        shareCombobox.open();
      }
    });
  })();
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
