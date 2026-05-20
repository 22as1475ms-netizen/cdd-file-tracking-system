<?php

function share_doc(): void {
  global $pdo;
  csrf_verify();
  // Super admins route to section admins; section admins route to section staff in their own division.
  require_once __DIR__ . "/../middleware/require_role.php";
  require_role('ADMIN', 'SECTION_ADMIN');

  $uid = (int)$_SESSION['user']['id'];
  $actorRole = strtoupper((string)($_SESSION['user']['role'] ?? ''));
  $docId = req_int('document_id', 0);
  $doc = Document::get($pdo, $docId);
  if (!$doc) { redirect('/documents?err=not_found'); }
  $targetUserId = req_int('target_user_id', 0);
  $targetEmail = req_str('target_email', '');
  $target = $targetUserId > 0 ? User::findById($pdo, $targetUserId) : User::findByEmail($pdo, $targetEmail);
  if (!$target) {
    redirect('/documents/view?id='.$docId.'&err=user_not_found');
  }
  $targetRole = strtoupper((string)($target['role'] ?? ''));
  if ($actorRole === 'SECTION_ADMIN') {
    $actorDivisionId = (int)($_SESSION['user']['division_id'] ?? 0);
    if ($targetRole !== 'SECTION_STAFF' || (int)($target['division_id'] ?? 0) !== $actorDivisionId) {
      redirect('/documents/view?id='.$docId.'&err=user_not_found');
    }
  } elseif ($targetRole !== 'SECTION_ADMIN') {
    redirect('/documents/view?id='.$docId.'&err=user_not_found');
  }

  try {
    $actionInstruction = trim(req_str('action_instruction', ''));
    $shareOptions = [];
    if ($actorRole === 'SECTION_ADMIN' && $actionInstruction !== '') {
      $shareOptions['note_suffix'] = 'Actions to be taken: ' . $actionInstruction;
    }

    DocumentShareService::shareDocument($pdo, $doc, $uid, $target, 'editor', $shareOptions);
  } catch (RuntimeException $e) {
    $error = $e->getMessage();
    if ($error === 'forbidden') {
      http_response_code(403);
      die("403 current holder only");
    }
    $separator = str_contains('/documents/view?id=' . $docId, '?') ? '&' : '?';
    redirect('/documents/view?id='.$docId . $separator . 'err=' . urlencode($error) . '&user_id='.(int)$doc['owner_id']);
  }

  redirect('/documents/view?id=' . $docId . '&msg=shared&user_id=' . (int)$doc['owner_id']);
}

function share_folder(): void {
  global $pdo;
  csrf_verify();
  // Only admins may initiate routing/share actions
  require_once __DIR__ . "/../middleware/require_role.php";
  require_role('ADMIN');

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $folderId = req_int('folder_id', 0);
  $ownerId = selected_owner_id($pdo, $uid);
  $folder = Folder::getForUser($pdo, $folderId, $ownerId, 'OFFICIAL');
  if (!$folder) {
    redirect('/admin/dashboard');
  }

  $targetUserId = req_int('target_user_id', 0);
  $target = $targetUserId > 0 ? User::findById($pdo, $targetUserId) : null;
  // Allow sharing to section admins as well as employees during routing.
  if (!$target || !in_array(strtoupper((string)($target['role'] ?? '')), ['SECTION_STAFF', 'SECTION_ADMIN', 'EMPLOYEE', 'DIVISION_CHIEF', 'ADMIN', 'SUPER_ADMIN'], true)) {
    redirect('/admin/dashboard');
  }
  if ((int)$target['id'] === $uid) {
    redirect('/admin/dashboard');
  }

  $owner = User::findById($pdo, $ownerId);
  $divisionId = (int)($owner['division_id'] ?? 0);
  if ($divisionId > 0 && (int)($target['division_id'] ?? 0) !== $divisionId) {
    redirect('/admin/dashboard');
  }

  $tree = Folder::listTreeForUser($pdo, $ownerId, (string)$folder['name'], 'OFFICIAL');
  $folderIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $tree)));
  if (empty($folderIds)) {
    $folderIds = [$folderId];
  }

  $docs = array_values(array_filter(Document::listActiveForOwnerInStorage($pdo, $ownerId, 'OFFICIAL'), static function (array $doc) use ($folderIds): bool {
    return in_array((int)($doc['folder_id'] ?? 0), $folderIds, true);
  }));
  if (empty($docs)) {
    redirect('/admin/dashboard');
  }

  foreach ($docs as $doc) {
    if (!can_manage_document($doc, $uid)) {
      http_response_code(403);
      die("403 owner only");
    }
    $routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
    $routeOutcome = strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE'));
    if ($routeOutcome !== 'ACTIVE' || in_array($routingStatus, ['APPROVED', 'REJECTED'], true)) {
      redirect('/admin/dashboard');
    }
  }

  $perm = 'editor';
  $division = (int)($target['division_id'] ?? 0) > 0 ? Division::find($pdo, (int)$target['division_id']) : null;

  foreach ($docs as $doc) {
    $docId = (int)($doc['id'] ?? 0);
    // Add share permission for the target without removing other recipients.
    Permission::upsert($pdo, $docId, (int)$target['id'], $perm, $uid);
    Permission::accept($pdo, $docId, (int)$target['id']);
    $recipientName = trim((string)($target['name'] ?? 'Recipient'));
    Document::updateTrackingState($pdo, $docId, 'Shared with ' . $recipientName, 'SHARE_ACCEPTED');
    Document::markRouteActive($pdo, $docId);
    DocumentRoute::add(
      $pdo,
      $docId,
      (string)($doc['current_location'] ?? ''),
      'Shared with ' . $recipientName,
      'SHARE_ACCEPTED',
      document_share_route_note($target, $division) . ' Folder: ' . Folder::basename((string)$folder['name']),
      $uid
    );
  }

  Notification::add($pdo, (int)$target['id'], "A routed folder was shared with you", Folder::basename((string)$folder['name']), "/documents?tab=shared");
  AuditLog::add($pdo, $uid, "Shared folder", null, "folder_id=" . $folderId . ", to=" . (string)($target['email'] ?? '') . ", docs=" . count($docs));
  redirect('/admin/dashboard?msg=shared');
}

function respond_to_share(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('document_id', 0);
  $decision = strtoupper(req_str('decision', ''));
  $note = trim(req_str('response_note', ''));
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }

  $permissionRow = Permission::findRowForUser($pdo, $docId, $uid);
  if (!$permissionRow) {
    http_response_code(403);
    die("403 share recipient only");
  }

  try {
    $result = DocumentShareService::respondToShare($pdo, $doc, $permissionRow, $uid, $decision, $note);
  } catch (RuntimeException $e) {
    $error = $e->getMessage();
    redirect('/documents/view?id='.$docId.'&err=' . urlencode($error));
  }

  if (($result['status'] ?? '') === 'accepted') {
    redirect('/documents/view?id='.$docId.'&msg=share_accepted');
  }
  redirect('/admin/dashboard?msg=share_declined');
}

function revoke_share(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)$_SESSION['user']['id'];
  $docId = req_int('document_id', 0);
  $doc = Document::get($pdo, $docId);
  if (!$doc) { redirect('/documents?err=not_found'); }
  if (!can_manage_document($doc, $uid)) { http_response_code(403); die("403 owner only"); }
  try {
    DocumentShareService::revokeShare($pdo, $doc, $uid, (string)($_SESSION['user']['name'] ?? ($doc['owner_name'] ?? 'Owner')));
  } catch (RuntimeException $e) {
    redirect('/admin/dashboard');
  }
  redirect('/admin/dashboard?msg=share_cancelled');
}
