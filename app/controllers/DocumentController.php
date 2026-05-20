<?php
require_once __DIR__ . "/../helpers/csrf.php";
require_once __DIR__ . "/../helpers/http.php";
require_once __DIR__ . "/../helpers/redirect.php";
require_once __DIR__ . "/../models/Division.php";
require_once __DIR__ . "/../models/Folder.php";
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/DocumentReview.php";
require_once __DIR__ . "/../models/DocumentRoute.php";
require_once __DIR__ . "/../models/Version.php";
require_once __DIR__ . "/../models/Permission.php";
require_once __DIR__ . "/../models/User.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/../models/Notification.php";
require_once __DIR__ . "/../models/Organization.php";
require_once __DIR__ . "/../services/DocumentService.php";
require_once __DIR__ . "/../services/DocumentShareService.php";
require_once __DIR__ . "/../services/AccessService.php";
require_once __DIR__ . "/DocumentFolderController.php";
require_once __DIR__ . "/DocumentShareController.php";
require_once __DIR__ . "/DocumentReviewController.php";

function current_role(): string {
  return strtoupper((string)($_SESSION['user']['role'] ?? 'EMPLOYEE'));
}

function document_tracking_date_default(): string {
  return date('Y-m-d');
}

function document_tracking_normalize_date(string $value): string {
  $value = trim($value);
  if ($value === '') {
    return '';
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
    return $value;
  }

  if (preg_match('/^(?<month>\d{1,2})\/(?<day>\d{1,2})\/(?<year>\d{2}|\d{4})$/', $value, $matches)) {
    $month = (int)$matches['month'];
    $day = (int)$matches['day'];
    $year = (int)$matches['year'];

    if (strlen((string)$matches['year']) === 2) {
      $year += $year >= 70 ? 1900 : 2000;
    }

    if (checkdate($month, $day, $year)) {
      return sprintf('%04d-%02d-%02d', $year, $month, $day);
    }
  }

  return '';
}

function document_tracking_location_default(string $storageArea): string {
  $currentUserName = trim((string)($_SESSION['user']['name'] ?? ''));
  return $currentUserName !== '' ? $currentUserName : 'Current holder';
}

function document_tracking_title_default(string $filename): string {
  $base = pathinfo($filename, PATHINFO_FILENAME);
  $clean = trim((string)$base);
  return $clean !== '' ? $clean : $filename;
}

function document_tracking_payload(array $input, string $filename, string $storageArea, int $index = 0): array {
  $rawCode = trim((string)($input['document_code'] ?? ''));
  $rawTitle = trim((string)($input['title'] ?? ''));
  $rawLocation = trim((string)($input['current_location'] ?? ''));
  $rawCategory = trim((string)($input['category'] ?? ''));
  $rawSignatory = trim((string)($input['signatory'] ?? ''));
  $rawDate = trim((string)($input['document_date'] ?? ''));
  $resolvedCategory = $rawCategory !== '' ? $rawCategory : 'Incoming PDF';

  return [
    'document_code' => $rawCode !== '' ? mb_substr($rawCode, 0, 80) : '',
    'title' => mb_substr($rawTitle !== '' ? $rawTitle : document_tracking_title_default($filename), 0, 255),
    'document_type' => 'INCOMING',
    'signatory' => $rawSignatory !== '' ? mb_substr($rawSignatory, 0, 150) : '',
    'current_location' => mb_substr($rawLocation !== '' ? $rawLocation : document_tracking_location_default($storageArea), 0, 180),
    'routing_status' => 'NOT_ROUTED',
    'priority_level' => Document::normalizePriorityLevel((string)($input['priority_level'] ?? 'MODERATE')),
    'document_date' => document_tracking_normalize_date($rawDate),
    'category' => mb_substr($resolvedCategory, 0, 100),
    'tags' => mb_substr(trim((string)($input['tags'] ?? '')), 0, 255),
    'status' => mb_substr(trim((string)($input['status'] ?? 'Draft')), 0, 20),
    'retention_until' => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($input['retention_until'] ?? ''))
      ? (string)$input['retention_until']
      : null,
    'storage_area' => Document::normalizeStorageArea($storageArea),
  ];
}

function document_tracking_apply_batch_rules(array $tracking, int $entryIndex, int $totalEntries, bool $customTitleProvided): array {
  if ($totalEntries <= 1) {
    return $tracking;
  }

  $suffixWidth = max(2, strlen((string)$totalEntries));
  $suffix = '-' . str_pad((string)$entryIndex, $suffixWidth, '0', STR_PAD_LEFT);
  $tracking['document_code'] = mb_substr(trim((string)($tracking['document_code'] ?? '')) . $suffix, 0, 80);

  if ($customTitleProvided) {
    $tracking['title'] = mb_substr(trim((string)($tracking['title'] ?? '')) . ' (' . $entryIndex . '/' . $totalEntries . ')', 0, 255);
  }

  return $tracking;
}

function document_tracking_required_error(array $tracking): ?string {
  if (trim((string)($tracking['title'] ?? '')) === '') {
    return 'document_title_required';
  }
  if (trim((string)($tracking['signatory'] ?? '')) === '') {
    return 'signatory_required';
  }
  if (trim((string)($tracking['document_date'] ?? '')) === '') {
    return 'document_date_required';
  }
  return null;
}

function document_route_note(string $action, array $tracking, string $filename = ''): string {
  $title = trim((string)($tracking['title'] ?? ''));
  $label = $title !== '' ? $title : $filename;
  return match ($action) {
    'upload' => 'Document logged during upload: ' . $label,
    'create' => 'Blank document created: ' . $label,
    'submit' => 'Document submitted for section chief review.',
    'approve' => 'Document review approved.',
    'reject' => 'Document review rejected.',
    default => 'Document route updated.',
  };
}

function documents_context_query_suffix(?int $folderId = null, ?int $userId = null): string {
  $parts = [];
  if ($folderId !== null && $folderId > 0) {
    $parts[] = 'folder=' . $folderId;
  }
  if ($userId !== null && $userId > 0) {
    $parts[] = 'user_id=' . $userId;
  }
  return $parts ? '&' . implode('&', $parts) : '';
}

function document_share_route_note(array $target, ?array $division = null): string {
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

  return implode(' ', $parts) . ' and is now active in the routed workflow.';
}

function is_admin_user(): bool {
  return in_array(current_role(), ['SUPER_ADMIN', 'ADMIN'], true);
}

function is_super_admin_user(): bool {
  return current_role() === 'SUPER_ADMIN';
}

function is_division_chief_user(): bool {
  if (current_role() === 'DIVISION_CHIEF') {
    return true;
  }
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  return $uid > 0 && !empty(TeamMember::chiefSectionIds($GLOBALS['pdo'], $uid));
}

function selected_owner_id(PDO $pdo, int $sessionUserId): int {
  if (!is_admin_user()) {
    return $sessionUserId;
  }

  $targetUserId = max(1, req_int('user_id', req_int('target_user_id', $sessionUserId)));
  return User::findById($pdo, $targetUserId) ? $targetUserId : $sessionUserId;
}

function request_document_id(): int {
  $docId = (int)($_POST['id'] ?? $_POST['document_id'] ?? 0);
  if ($docId <= 0) {
    $docId = req_int('id', req_int('document_id', 0));
  }
  if ($docId > 0) {
    return $docId;
  }
  $documentIds = $_POST['document_ids'] ?? $_REQUEST['document_ids'] ?? [];
  if (is_array($documentIds) && !empty($documentIds)) {
    return (int)reset($documentIds);
  }
  return 0;
}

function request_folder_id(): int {
  $folderId = req_int('id', req_int('folder_id', 0));
  if ($folderId > 0) {
    return $folderId;
  }
  $folderIds = $_POST['folder_ids'] ?? $_REQUEST['folder_ids'] ?? [];
  if (is_array($folderIds) && !empty($folderIds)) {
    return (int)reset($folderIds);
  }
  return 0;
}

function complete_route_redirect_path(PDO $pdo, int $docId, int $userId, int $ownerId): string {
  $level = AccessService::level($pdo, $docId, $userId);
  if (in_array($level, ['admin', 'owner', 'editor', 'viewer', 'division_chief'], true)) {
    return '/documents/view?id=' . $docId . '&msg=route_lifecycle_completed&user_id=' . $ownerId;
  }

  return workspace_home_path() . '?msg=route_lifecycle_completed';
}

function document_route_recipients(PDO $pdo, array $doc, int $userId): array {
  if (is_admin_user()) {
    return array_values(array_filter(
      User::listShareRecipients($pdo, $userId, (int)($doc['division_id'] ?? 0)),
      static function (array $recipient): bool {
        return strtoupper((string)($recipient['role'] ?? '')) === 'SECTION_ADMIN'
          && strtoupper((string)($recipient['status'] ?? 'ACTIVE')) === 'ACTIVE';
      }
    ));
  }

  if (current_role() === 'SECTION_ADMIN') {
    $recipientDivisionId = (int)($doc['division_id'] ?? 0);
    if ($recipientDivisionId <= 0) {
      $recipientDivisionId = (int)($_SESSION['user']['division_id'] ?? 0);
    }
    return array_values(array_filter(
      User::listEmployeesByDivision($pdo, $recipientDivisionId),
      static function (array $recipient) use ($userId): bool {
        return (int)($recipient['id'] ?? 0) !== $userId
          && strtoupper((string)($recipient['status'] ?? 'ACTIVE')) === 'ACTIVE';
      }
    ));
  }

  $memberships = TeamMember::findByUser($pdo, $userId);
  if (empty($memberships)) {
    return User::listShareRecipients($pdo, $userId, (int)($doc['division_id'] ?? 0));
  }

  $sectionId = (int)($memberships[0]['section_id'] ?? 0);
  if ($sectionId <= 0) {
    return User::listShareRecipients($pdo, $userId, (int)($doc['division_id'] ?? 0));
  }

  $sectionMembers = TeamMember::findBySection($pdo, $sectionId);
  $recipients = [];
  foreach ($sectionMembers as $member) {
    $memberUserId = (int)($member['user_id'] ?? 0);
    if ($memberUserId <= 0 || $memberUserId === $userId) {
      continue;
    }
    if (strtoupper((string)($member['status'] ?? 'ACTIVE')) !== 'ACTIVE') {
      continue;
    }
    if (strtoupper((string)($member['role'] ?? 'MEMBER')) === 'SECTION_CHIEF') {
      $userRole = 'SECTION_ADMIN';
    } else {
      $userRole = 'EMPLOYEE';
    }
    $recipients[] = [
      'id' => $memberUserId,
      'name' => (string)($member['name'] ?? ''),
      'email' => (string)($member['email'] ?? ''),
      'role' => $userRole,
      'status' => (string)($member['status'] ?? 'ACTIVE'),
      'availability_status' => (string)($member['availability_status'] ?? 'ACTIVE'),
      'availability_note' => (string)($member['availability_note'] ?? ''),
      'division_id' => (int)($doc['division_id'] ?? 0),
      'created_at' => '',
      'avatar_photo' => null,
      'avatar_preset' => null,
      'division_name' => (string)($doc['division_name'] ?? 'No division'),
      'chief_user_id' => 0,
      'chief_name' => (string)($memberships[0]['section_name'] ?? 'Section'),
      'chief_email' => '',
    ];
  }

  return $recipients;
}

function can_manage_document(array $doc, int $uid): bool {
  return is_admin_user() || (int)$doc['owner_id'] === $uid;
}

function can_forward_document(array $doc, int $uid): bool {
  $level = AccessService::level($GLOBALS['pdo'], (int)($doc['id'] ?? 0), $uid);
  return in_array($level, ['admin', 'owner', 'editor', 'viewer', 'division_chief'], true);
}

function document_share_locked_for_user(array $doc, int $uid): bool {
  $level = AccessService::level($GLOBALS['pdo'], (int)($doc['id'] ?? 0), $uid);
  $routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
  $routeOutcome = strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE'));
  if ($routeOutcome !== 'ACTIVE' || in_array($routingStatus, ['APPROVED', 'REJECTED', 'COMPLETED'], true)) {
    return true;
  }

  return match ($routingStatus) {
    'PENDING_SHARE_ACCEPTANCE', 'PENDING_REVIEW_ACCEPTANCE' => true,
    'SHARE_ACCEPTED' => !in_array($level, ['admin', 'editor', 'viewer'], true),
    'IN_REVIEW' => !in_array($level, ['admin', 'division_chief'], true),
    default => false,
  };
}

function document_is_finalized(array $doc): bool {
  $routeOutcome = strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE'));
  $routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
  return $routeOutcome !== 'ACTIVE' || in_array($routingStatus, ['APPROVED', 'REJECTED', 'COMPLETED'], true);
}

function can_mutate_document(array $doc, int $uid): bool {
  if (!can_manage_document($doc, $uid)) {
    return false;
  }
  if (is_admin_user()) {
    return true;
  }
  if (document_is_finalized($doc)) {
    return false;
  }
  return !is_approval_locked($doc);
}

function document_admin_reauth_ok(PDO $pdo, string $password): bool {
  if ($password === '' || !is_super_admin_user()) {
    return false;
  }

  $adminId = (int)($_SESSION['user']['id'] ?? 0);
  if ($adminId <= 0) {
    return false;
  }

  $admin = User::findById($pdo, $adminId);
  if (!$admin) {
    return false;
  }

  return password_verify($password, (string)($admin['password'] ?? ''));
}

function permanently_delete_document(PDO $pdo, int $docId, int $actorId): bool {
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    return false;
  }

  $deletedCount = purge_trash_items($pdo, (int)($doc['owner_id'] ?? 0), [], [$docId]);
  if ($deletedCount < 1) {
    return false;
  }

  AuditLog::add($pdo, $actorId, "Permanently deleted document", $docId, "owner_id=" . (int)($doc['owner_id'] ?? 0));
  return true;
}

function can_submit_document_for_review(array $doc): bool {
  $status = strtolower(trim((string)($doc['status'] ?? 'Draft')));
  if (in_array($status, ['approved', 'to be reviewed'], true)) {
    return false;
  }

  return !is_approval_locked($doc);
}

function can_review_document(array $doc, int $uid): bool {
  if (is_admin_user()) {
    return true;
  }
  if ((int)($doc['assigned_reviewer_id'] ?? 0) === $uid) {
    return true;
  }
  $level = AccessService::level($GLOBALS['pdo'], (int)($doc['id'] ?? 0), $uid);
  if (in_array($level, ['division_chief', 'division_chief_pending', 'division_chief_declined'], true)) {
    return true;
  }

  if (current_role() !== 'DIVISION_CHIEF') {
    return false;
  }

  $permissionRow = Permission::findRowForUser($GLOBALS['pdo'], (int)($doc['id'] ?? 0), $uid);
  return !empty($permissionRow['accepted_at']);
}

function folder_path_join(string ...$segments): string {
  $parts = [];
  foreach ($segments as $segment) {
    foreach (Folder::pathSegments($segment) as $part) {
      $parts[] = $part;
    }
  }
  return implode(Folder::PATH_SEPARATOR, $parts);
}

function folder_visible_children(array $folders, string $parentPath = ''): array {
  $visible = [];
  foreach ($folders as $folder) {
    $name = (string)($folder['name'] ?? '');
    if (Folder::parentPath($name) !== $parentPath) {
      continue;
    }
    $folder['display_name'] = Folder::basename($name);
    $folder['path_name'] = Folder::normalizePath($name);
    $visible[] = $folder;
  }
  return $visible;
}

function folder_breadcrumbs(?array $currentFolder): array {
  if (!$currentFolder) {
    return [];
  }
  $segments = Folder::pathSegments((string)($currentFolder['name'] ?? ''));
  $crumbs = [];
  $path = '';
  foreach ($segments as $segment) {
    $path = folder_path_join($path, $segment);
    $crumbs[] = [
      'label' => $segment,
      'path' => $path,
    ];
  }
  return $crumbs;
}

function folder_tree_contains_path(array $trashedPaths, string $folderPath): bool {
  $normalizedFolderPath = Folder::normalizePath($folderPath);
  if ($normalizedFolderPath === '') {
    return false;
  }

  foreach ($trashedPaths as $trashedPath) {
    $normalizedTrashedPath = Folder::normalizePath((string)$trashedPath);
    if ($normalizedTrashedPath === '') {
      continue;
    }
    if ($normalizedFolderPath === $normalizedTrashedPath) {
      return true;
    }
    if (str_starts_with($normalizedFolderPath, $normalizedTrashedPath . Folder::PATH_SEPARATOR)) {
      return true;
    }
  }

  return false;
}

function visible_trash_documents(array $documents, array $trashedFolders): array {
  $trashedPaths = array_values(array_filter(array_map(
    static fn(array $folder): string => (string)($folder['path_name'] ?? $folder['name'] ?? ''),
    $trashedFolders
  )));

  return array_values(array_filter($documents, static function (array $document) use ($trashedPaths): bool {
    $folderName = trim((string)($document['folder_name'] ?? ''));
    return !folder_tree_contains_path($trashedPaths, $folderName);
  }));
}

function purge_trash_items(PDO $pdo, int $ownerId, array $folderIds, array $documentIds): int {
  $folderIds = array_values(array_unique(array_map('intval', $folderIds)));
  $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

  if (!empty($folderIds)) {
    $folderDocs = Document::idsByFolderIds($pdo, $folderIds, $ownerId);
    $documentIds = array_values(array_unique(array_merge($documentIds, $folderDocs)));
  }

  if (empty($folderIds) && empty($documentIds)) {
    return 0;
  }

  $filePaths = Version::filePathsByDocumentIds($pdo, $documentIds);
  $deletedCount = count($folderIds) + count($documentIds);

  $pdo->beginTransaction();
  try {
    Permission::deleteByDocumentIds($pdo, $documentIds);
    Version::deleteByDocumentIds($pdo, $documentIds);
    Document::hardDeleteByIds($pdo, $documentIds);
    Folder::hardDeleteByIds($pdo, $folderIds, $ownerId);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }

  foreach ($filePaths as $path) {
    StorageService::delete($pdo, $path);
  }

  return $deletedCount;
}

function purge_permanent_items(PDO $pdo, int $ownerId, array $folderIds, array $documentIds): int {
  $folderIds = array_values(array_unique(array_map('intval', $folderIds)));
  $documentIds = array_values(array_unique(array_map('intval', $documentIds)));

  $eligibleDocumentIds = [];
  foreach ($documentIds as $docId) {
    $doc = Document::get($pdo, $docId);
    if (!$doc || (int)($doc['owner_id'] ?? 0) !== $ownerId) {
      continue;
    }
    if ((string)($doc['deleted_at'] ?? '') !== '' || document_is_finalized($doc)) {
      $eligibleDocumentIds[] = $docId;
    }
  }

  if (empty($folderIds) && empty($eligibleDocumentIds)) {
    return 0;
  }

  return purge_trash_items($pdo, $ownerId, $folderIds, $eligibleDocumentIds);
}

function storage_area_tab(string $storageArea): string {
  return 'routed';
}

function opposite_storage_area(string $storageArea): string {
  return 'OFFICIAL';
}

function document_target_folder_id(PDO $pdo, array $doc, string $targetStorageArea): ?int {
  $ownerId = (int)($doc['owner_id'] ?? 0);
  $sourceStorageArea = Document::normalizeStorageArea((string)($doc['storage_area'] ?? 'PRIVATE'));
  $sourceFolderId = (int)($doc['folder_id'] ?? 0);
  if ($sourceFolderId <= 0) {
    return null;
  }

  $sourceFolder = Folder::getForUser($pdo, $sourceFolderId, $ownerId, $sourceStorageArea);
  if (!$sourceFolder) {
    return null;
  }

  return Folder::firstOrCreateForUser($pdo, $ownerId, (string)$sourceFolder['name'], $targetStorageArea);
}

function assert_document_move_conflict_free(PDO $pdo, array $doc, string $targetStorageArea, ?int $targetFolderId): void {
  $ownerId = (int)($doc['owner_id'] ?? 0);
  $existing = Document::findActiveByOwnerAndNameInFolder(
    $pdo,
    $ownerId,
    (string)($doc['name'] ?? ''),
    $targetFolderId,
    $targetStorageArea
  );
  if ($existing && (int)($existing['id'] ?? 0) !== (int)($doc['id'] ?? 0)) {
    throw new RuntimeException('name_conflict');
  }
}

function collect_folder_move_targets(PDO $pdo, int $ownerId, string $folderPath, string $sourceStorageArea, string $targetStorageArea): array {
  $tree = Folder::listTreeForUser($pdo, $ownerId, $folderPath, $sourceStorageArea);
  $folderIdMap = [];
  foreach ($tree as $folder) {
    $folderIdMap[(int)$folder['id']] = Folder::firstOrCreateForUser($pdo, $ownerId, (string)$folder['name'], $targetStorageArea);
  }
  return $folderIdMap;
}

function assert_folder_move_conflict_free(PDO $pdo, int $ownerId, array $folderIdMap, string $sourceStorageArea, string $targetStorageArea): void {
  if (empty($folderIdMap)) {
    return;
  }

  $documents = Document::listActiveForOwnerInStorage($pdo, $ownerId, $sourceStorageArea);
  foreach ($documents as $doc) {
    $sourceFolderId = (int)($doc['folder_id'] ?? 0);
    if (!isset($folderIdMap[$sourceFolderId])) {
      continue;
    }
    assert_document_move_conflict_free($pdo, $doc, $targetStorageArea, (int)$folderIdMap[$sourceFolderId]);
  }
}

function move_document_between_storage(PDO $pdo, array $doc, string $targetStorageArea): ?int {
  $ownerId = (int)($doc['owner_id'] ?? 0);
  $owner = User::findById($pdo, $ownerId);
  $divisionId = (int)($owner['division_id'] ?? 0);
  $targetFolderId = document_target_folder_id($pdo, $doc, $targetStorageArea);
  assert_document_move_conflict_free($pdo, $doc, $targetStorageArea, $targetFolderId);
  Document::moveToStorageArea($pdo, (int)$doc['id'], $targetStorageArea, $targetFolderId, $targetStorageArea === 'OFFICIAL' && $divisionId > 0 ? $divisionId : null);
  return $targetFolderId;
}

function folder_tree_locked_document_exists(PDO $pdo, int $ownerId, string $folderPath, string $storageArea): bool {
  $folderTree = Folder::listTreeForUser($pdo, $ownerId, $folderPath, $storageArea);
  if (empty($folderTree)) {
    return false;
  }

  $folderIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $folderTree)));
  if (empty($folderIds)) {
    return false;
  }

  foreach (Document::listActiveForOwnerInStorage($pdo, $ownerId, $storageArea) as $doc) {
    if (in_array((int)($doc['folder_id'] ?? 0), $folderIds, true) && is_approval_locked($doc)) {
      return true;
    }
  }

  return false;
}

function folder_tree_submittable_document_exists(PDO $pdo, int $ownerId, string $folderPath): bool {
  $folderTree = Folder::listTreeForUser($pdo, $ownerId, $folderPath, 'OFFICIAL');
  if (empty($folderTree)) {
    return false;
  }

  $folderIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $folderTree)));
  if (empty($folderIds)) {
    return false;
  }

  foreach (Document::listActiveForOwnerInStorage($pdo, $ownerId, 'OFFICIAL') as $doc) {
    if (in_array((int)($doc['folder_id'] ?? 0), $folderIds, true) && can_submit_document_for_review($doc)) {
      return true;
    }
  }

  return false;
}

function workspace_sort(string $value): string {
  $allowed = ['modified_desc', 'modified_asc', 'name_asc', 'name_desc'];
  return in_array($value, $allowed, true) ? $value : 'modified_desc';
}

function folder_sort_value(array $folder, string $sort): int|string {
  if (str_starts_with($sort, 'name_')) {
    return mb_strtolower((string)($folder['display_name'] ?? $folder['name'] ?? ''));
  }
  $timestamp = strtotime((string)($folder['updated_at'] ?? $folder['created_at'] ?? ''));
  return $timestamp ?: (int)($folder['id'] ?? 0);
}

function sort_workspace_folders(array $folders, string $sort): array {
  usort($folders, static function (array $left, array $right) use ($sort): int {
    $leftValue = folder_sort_value($left, $sort);
    $rightValue = folder_sort_value($right, $sort);
    $direction = in_array($sort, ['name_desc', 'modified_desc'], true) ? -1 : 1;
    if ($leftValue === $rightValue) {
      return strcasecmp((string)($left['display_name'] ?? $left['name'] ?? ''), (string)($right['display_name'] ?? $right['name'] ?? '')) * $direction;
    }
    return ($leftValue <=> $rightValue) * $direction;
  });

  return $folders;
}

function workspace_search_matches(string $query, string ...$haystacks): bool {
  $needle = mb_strtolower(trim($query));
  if ($needle === '') {
    return false;
  }

  foreach ($haystacks as $haystack) {
    if (str_contains(mb_strtolower($haystack), $needle)) {
      return true;
    }
  }

  return false;
}

function workspace_global_search(PDO $pdo, int $uid, int $targetUserId, string $query, bool $isAdmin, bool $isDivisionChief): array {
  $query = trim($query);
  if ($query === '') {
    return [];
  }

  $results = [];

  $pages = [
    [
      'title' => 'Routed Files',
      'meta' => 'Tracked routed records workspace',
      'href' => '/documents?tab=routed' . ($isAdmin ? '&user_id=' . $targetUserId : ''),
      'icon' => 'bi-folder2-open',
      'haystack' => 'routed files routing records tracking approvals review workspace',
    ],
    [
      'title' => 'Shared',
      'meta' => 'Shared records',
      'href' => '/documents?tab=shared',
      'icon' => 'bi-people',
      'haystack' => 'shared records collaboration access',
    ],
    [
      'title' => 'Trash',
      'meta' => 'Deleted files and folders',
      'href' => '/documents?tab=trash' . ($isAdmin ? '&user_id=' . $targetUserId : ''),
      'icon' => 'bi-trash3',
      'haystack' => 'trash deleted restore permanent delete',
    ],
    [
      'title' => 'Profile',
      'meta' => 'Profile and password settings',
      'href' => '/account/password',
      'icon' => 'bi-person-gear',
      'haystack' => 'profile settings account password avatar',
    ],
  ];

  if ($isDivisionChief) {
    $pages[] = [
      'title' => 'Employee Records',
      'meta' => 'Section chief employee records queue',
      'href' => '/documents?tab=division_queue',
      'icon' => 'bi-diagram-3',
      'haystack' => 'employee records division queue review pending approvals rejected employee uploads',
    ];
  }
  if ($isAdmin) {
    $pages[] = [
      'title' => 'Manage Users',
      'meta' => 'Admin user accounts and divisions',
      'href' => '/admin/users',
      'icon' => 'bi-people-fill',
      'haystack' => 'admin users accounts divisions roles passwords',
    ];
  }

  $pageHits = [];
  foreach ($pages as $page) {
    if (workspace_search_matches($query, (string)$page['title'], (string)$page['meta'], (string)$page['haystack'])) {
      $pageHits[] = $page;
    }
  }
  if (!empty($pageHits)) {
    $results['Navigate'] = array_slice($pageHits, 0, 8);
  }

  $folderHits = [];
  foreach (['OFFICIAL'] as $storageArea) {
    foreach (Folder::searchForUser($pdo, $targetUserId, $query, $storageArea, 8) as $folder) {
      $displayName = Folder::basename((string)($folder['name'] ?? ''));
      $folderHits[] = [
        'title' => $displayName,
        'meta' => 'Routed folder',
        'href' => '/documents?tab=routed&folder=' . (int)$folder['id'] . ($isAdmin ? '&user_id=' . $targetUserId : ''),
        'icon' => 'bi-folder-fill',
      ];
    }
  }
  if (!empty($folderHits)) {
    $results['Folders'] = array_slice($folderHits, 0, 8);
  }

  $documentHits = [];
  foreach (['OFFICIAL'] as $storageArea) {
    foreach (Document::searchActiveForOwnerInStorage($pdo, $targetUserId, $storageArea, $query, 10) as $doc) {
      $name = (string)($doc['name'] ?? '');
      $title = (string)($doc['title'] ?? '');
      $documentCode = (string)($doc['document_code'] ?? '');
      $category = (string)($doc['category'] ?? '');
      $status = (string)($doc['status'] ?? '');
      $location = (string)($doc['current_location'] ?? '');
      $documentHits[] = [
        'title' => $title !== '' ? $title : $name,
        'meta' => trim(($documentCode !== '' ? $documentCode . ' | ' : '') . ($location !== '' ? $location . ' | ' : '') . 'Routed' . ($status !== '' ? ' | ' . $status : '')),
        'href' => '/documents/view?id=' . (int)$doc['id'] . ($isAdmin ? '&user_id=' . $targetUserId : ''),
        'icon' => 'bi-file-earmark-text',
      ];
    }
  }
  if (!empty($documentHits)) {
    $results['Files'] = array_slice($documentHits, 0, 10);
  }

  $sharedHits = [];
  [$sharedDocs] = Document::listShared($pdo, $uid, $query, 1, 8, []);
  foreach ($sharedDocs as $doc) {
    $sharedTitle = trim((string)($doc['title'] ?? '')) !== '' ? (string)$doc['title'] : (string)($doc['name'] ?? 'Shared record');
    $sharedHits[] = [
      'title' => $sharedTitle,
      'meta' => trim((string)($doc['document_code'] ?? '')) !== ''
        ? (string)$doc['document_code'] . ' | Shared by ' . (string)($doc['owner_name'] ?? 'Unknown')
        : 'Shared by ' . (string)($doc['owner_name'] ?? 'Unknown'),
      'href' => '/documents/view?id=' . (int)$doc['id'],
      'icon' => 'bi-people',
    ];
  }
  if (!empty($sharedHits)) {
    $results['Shared'] = $sharedHits;
  }

  $notificationHits = [];
  foreach (Notification::recentAll($pdo, $uid, 20) as $notification) {
    $title = (string)($notification['title'] ?? '');
    $body = (string)($notification['body'] ?? '');
    if (!workspace_search_matches($query, $title, $body)) {
      continue;
    }
    $destination = Notification::resolveDestination($notification);
    $href = $destination !== '' ? $destination : '/documents';
    $notificationHits[] = [
      'title' => $title !== '' ? $title : 'Notification',
      'meta' => $body !== '' ? $body : 'Workspace notification',
      'href' => $href,
      'icon' => 'bi-bell',
    ];
  }
  if (!empty($notificationHits)) {
    $results['Notifications'] = array_slice($notificationHits, 0, 6);
  }

  return $results;
}

function upload(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)$_SESSION['user']['id'];
  // Only admin users may upload files via the UI
  if (!is_admin_user()) {
    redirect('/admin/dashboard');
  }
  // When an admin uploads, keep the file owned by the admin (staging area).
  $ownerId = is_admin_user() ? $uid : selected_owner_id($pdo, $uid);
  $folderId = req_int('folder_id', 0) ?: null;
  $storageArea = 'OFFICIAL';
  $owner = User::findById($pdo, $ownerId);
  $divisionId = (int)($owner['division_id'] ?? 0);
  $baseFolder = $folderId ? Folder::getForUser($pdo, $folderId, $ownerId, $storageArea) : null;
  $baseFolderPath = $baseFolder ? Folder::normalizePath((string)$baseFolder['name']) : '';
  $contextSuffix = documents_context_query_suffix($folderId, $ownerId);
  $routedRedirectBase = '/admin/dashboard?';

  if ($folderId && !$baseFolder) {
    redirect($routedRedirectBase . 'err=folder_not_found' . documents_context_query_suffix(null, $ownerId));
  }

  $uploadedEntries = array_merge(
    uploaded_entries_from_request($_FILES['file'] ?? null, $_POST['file_relative_paths'] ?? $_POST['relative_paths'] ?? []),
    uploaded_entries_from_request($_FILES['folder_upload'] ?? null, $_POST['file_relative_paths'] ?? $_POST['relative_paths'] ?? [])
  );
  if (request_exceeds_post_max_size()) {
    redirect($routedRedirectBase . 'err=upload_post_max_exceeded' . $contextSuffix);
  }
  $uploadError = upload_request_error_code($_FILES['file'] ?? null);
  if ($uploadError === null) {
    $uploadError = upload_request_error_code($_FILES['folder_upload'] ?? null);
  }
  if ($uploadError !== null) {
    redirect($routedRedirectBase . 'err=' . upload_error_message_key($uploadError) . $contextSuffix);
  }
  if (empty($uploadedEntries)) {
    redirect($routedRedirectBase . 'err=upload_failed' . $contextSuffix);
  }

  // Enforce PDF-only uploads
  foreach ($uploadedEntries as $entryCheck) {
    $entryNameCheck = (string)($entryCheck['file']['name'] ?? '');
    $extCheck = strtolower((string)pathinfo($entryNameCheck, PATHINFO_EXTENSION));
    if ($extCheck !== 'pdf') {
      redirect($routedRedirectBase . 'err=only_pdf_allowed' . $contextSuffix);
    }
  }

  try {
    $totalEntries = count($uploadedEntries);
    $customTitleProvided = trim((string)($_POST['title'] ?? '')) !== '';
    $entryIndex = 0;
    foreach ($uploadedEntries as $entry) {
      $entryIndex++;
      $entryFolderId = $folderId;
      $relativePath = trim((string)($entry['relative_path'] ?? ''));
      if ($relativePath !== '') {
        $segments = array_values(array_filter(explode('/', str_replace('\\', '/', $relativePath)), static fn(string $segment): bool => $segment !== ''));
        if (!empty($segments)) {
          $entryFolderId = Folder::firstOrCreateForUser($pdo, $ownerId, folder_path_join($baseFolderPath, implode('/', $segments)), $storageArea);
        }
      }

      $entryName = trim((string)($entry['file']['name'] ?? ''));
      $existing = $entryName !== '' ? Document::findActiveByOwnerAndNameInFolder($pdo, $ownerId, $entryName, $entryFolderId, $storageArea) : null;
      if ($existing && req_str('on_conflict', '') === 'override') {
        DocumentService::uploadNewVersion($pdo, (int)$existing['id'], $entry['file'], $uid);
        continue;
      }
      if ($existing) {
        redirect($routedRedirectBase . 'err=name_conflict' . $contextSuffix);
      }

      $tracking = document_tracking_payload($_POST, $entryName, $storageArea, $entryIndex);
      $tracking = document_tracking_apply_batch_rules($tracking, $entryIndex, $totalEntries, $customTitleProvided);
      // For admin uploads, do not accept a current_location from the form — set to Admin automatically
      $tracking['current_location'] = 'Admin';
      if (($trackingError = document_tracking_required_error($tracking)) !== null) {
        redirect($routedRedirectBase . 'err=' . $trackingError . $contextSuffix);
      }
      $pdo->beginTransaction();
      try {
        DocumentService::upload(
          $pdo,
          $entry['file'],
          $ownerId,
          $entryFolderId,
          $uid,
          $storageArea,
          $divisionId > 0 ? $divisionId : null,
          $tracking
        );
        $pdo->commit();
      } catch (Throwable $entryError) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $entryError;
      }
    }

  } catch (Throwable $e) {
    upload_debug_log($e);
    redirect($routedRedirectBase . 'err=upload_failed' . $contextSuffix);
  }

  redirect('/admin/dashboard?msg=uploaded');
}

function uploaded_entries_from_request(?array $fileBag, array $relativeOverrides = []): array {
  if (!$fileBag || !isset($fileBag['name'])) {
    return [];
  }

  if (!is_array($fileBag['name'])) {
    return [[
      'file' => $fileBag,
      'relative_path' => (string)($relativeOverrides[0] ?? ''),
    ]];
  }

  $entries = [];
  $total = count($fileBag['name']);
  $fullPaths = isset($fileBag['full_path']) && is_array($fileBag['full_path']) ? $fileBag['full_path'] : [];
  for ($i = 0; $i < $total; $i++) {
    $error = (int)($fileBag['error'][$i] ?? UPLOAD_ERR_NO_FILE);
    if ($error !== UPLOAD_ERR_OK) {
      continue;
    }
    $name = (string)($fileBag['name'][$i] ?? '');
    $override = (string)($relativeOverrides[$i] ?? '');
    $relativeSource = $override !== '' ? $override : (string)($fullPaths[$i] ?? $name);
    $relativeSource = str_replace('\\', '/', $relativeSource);
    $relativePath = trim((string)dirname($relativeSource), './');
    $entries[] = [
      'file' => [
        'name' => $name,
        'type' => (string)($fileBag['type'][$i] ?? ''),
        'tmp_name' => (string)($fileBag['tmp_name'][$i] ?? ''),
        'error' => $error,
        'size' => (int)($fileBag['size'][$i] ?? 0),
      ],
      'relative_path' => $relativePath === '.' ? '' : $relativePath,
    ];
  }

  return $entries;
}

function upload_request_error_code(?array $fileBag): ?int {
  if (!$fileBag || !isset($fileBag['error'])) {
    return null;
  }

  $errors = $fileBag['error'];
  if (!is_array($errors)) {
    $code = (int)$errors;
    return $code === UPLOAD_ERR_OK || $code === UPLOAD_ERR_NO_FILE ? null : $code;
  }

  foreach ($errors as $error) {
    $code = (int)$error;
    if ($code !== UPLOAD_ERR_OK && $code !== UPLOAD_ERR_NO_FILE) {
      return $code;
    }
  }

  return null;
}

function upload_error_message_key(int $errorCode): string {
  return match ($errorCode) {
    UPLOAD_ERR_INI_SIZE => 'upload_ini_size_exceeded',
    UPLOAD_ERR_FORM_SIZE => 'upload_form_size_exceeded',
    UPLOAD_ERR_PARTIAL => 'upload_partial',
    UPLOAD_ERR_NO_TMP_DIR => 'upload_no_tmp_dir',
    UPLOAD_ERR_CANT_WRITE => 'upload_cant_write',
    UPLOAD_ERR_EXTENSION => 'upload_blocked_by_extension',
    default => 'upload_failed',
  };
}

function upload_debug_log(Throwable $e): void {
  $logPath = dirname(STORAGE_DIR) . DIRECTORY_SEPARATOR . 'upload-errors.log';
  $line = '[' . date('Y-m-d H:i:s') . '] '
    . get_class($e) . ': ' . $e->getMessage()
    . ' in ' . $e->getFile() . ':' . $e->getLine()
    . PHP_EOL;
  @file_put_contents($logPath, $line, FILE_APPEND);
}

function request_exceeds_post_max_size(): bool {
  $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
  if ($contentLength <= 0) {
    return false;
  }

  $postMax = ini_get('post_max_size');
  $postMaxBytes = ini_size_to_bytes($postMax);
  return $postMaxBytes > 0 && $contentLength > $postMaxBytes;
}

function ini_size_to_bytes(string|false $value): int {
  $raw = trim((string)$value);
  if ($raw === '') {
    return 0;
  }

  $number = (float)$raw;
  $unit = strtolower(substr($raw, -1));
  return match ($unit) {
    'g' => (int)($number * 1024 * 1024 * 1024),
    'm' => (int)($number * 1024 * 1024),
    'k' => (int)($number * 1024),
    default => (int)$number,
  };
}

function document_preview_mime(string $filename, string $absPath): string {
  $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
  $map = [
    'pdf' => 'application/pdf',
    'mp4' => 'video/mp4',
    'webm' => 'video/webm',
    'ogg' => 'video/ogg',
    'ogv' => 'video/ogg',
    'mov' => 'video/quicktime',
    'm4v' => 'video/mp4',
    'png' => 'image/png',
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'txt' => 'text/plain; charset=utf-8',
    'md' => 'text/plain; charset=utf-8',
    'csv' => 'text/plain; charset=utf-8',
    'json' => 'application/json; charset=utf-8',
    'xml' => 'application/xml; charset=utf-8',
    'html' => 'text/plain; charset=utf-8',
    'css' => 'text/plain; charset=utf-8',
    'js' => 'text/plain; charset=utf-8',
    'ts' => 'text/plain; charset=utf-8',
    'php' => 'text/plain; charset=utf-8',
  ];
  if (isset($map[$ext])) {
    return $map[$ext];
  }

  $detected = function_exists('mime_content_type') ? (string)@mime_content_type($absPath) : '';
  return $detected !== '' ? $detected : 'application/octet-stream';
}

function document_is_video_extension(string $filename): bool {
  $ext = strtolower((string)pathinfo($filename, PATHINFO_EXTENSION));
  return in_array($ext, ['mp4', 'webm', 'ogg', 'ogv', 'mov', 'm4v'], true);
}

function document_docx_text_preview(string $absPath): ?string {
  if (!class_exists('ZipArchive')) {
    return null;
  }

  $zip = new ZipArchive();
  if ($zip->open($absPath) !== true) {
    return null;
  }

  $xml = $zip->getFromName('word/document.xml');
  $zip->close();
  if ($xml === false || $xml === '') {
    return null;
  }

  $text = preg_replace('/<w:tab[^>]*\/>/', "\t", $xml);
  $text = preg_replace('/<w:br[^>]*\/>/', "\n", (string)$text);
  $text = preg_replace('/<\/w:p>/', "\n\n", (string)$text);
  $text = strip_tags((string)$text);
  $text = html_entity_decode((string)$text, ENT_QUOTES | ENT_XML1, 'UTF-8');
  $text = preg_replace("/\n{3,}/", "\n\n", (string)$text);
  $text = trim((string)$text);
  return $text !== '' ? mb_substr($text, 0, 12000) : null;
}

function document_docx_image_mime(string $path): string {
  return match (strtolower((string)pathinfo($path, PATHINFO_EXTENSION))) {
    'png' => 'image/png',
    'jpg', 'jpeg' => 'image/jpeg',
    'gif' => 'image/gif',
    'webp' => 'image/webp',
    'bmp' => 'image/bmp',
    'svg' => 'image/svg+xml',
    default => 'application/octet-stream',
  };
}

function document_docx_rel_target_path(string $target): string {
  $target = str_replace('\\', '/', trim($target));
  if ($target === '') {
    return '';
  }
  if (str_starts_with($target, '/')) {
    return ltrim($target, '/');
  }

  $segments = [];
  foreach (explode('/', 'word/' . $target) as $segment) {
    if ($segment === '' || $segment === '.') {
      continue;
    }
    if ($segment === '..') {
      array_pop($segments);
      continue;
    }
    $segments[] = $segment;
  }

  return implode('/', $segments);
}

function document_docx_relationships(ZipArchive $zip, string $relsPath): array {
  $relsXml = $zip->getFromName($relsPath);
  if ($relsXml === false || $relsXml === '') {
    return [];
  }

  $dom = new DOMDocument();
  if (!@$dom->loadXML($relsXml)) {
    return [];
  }

  $relationships = [];
  foreach ($dom->getElementsByTagName('Relationship') as $relationship) {
    if (!($relationship instanceof DOMElement)) {
      continue;
    }

    $id = trim((string)$relationship->getAttribute('Id'));
    $type = trim((string)$relationship->getAttribute('Type'));
    $target = trim((string)$relationship->getAttribute('Target'));
    if ($id === '' || $target === '') {
      continue;
    }

    $relationships[$id] = [
      'type' => $type,
      'target' => document_docx_rel_target_path($target),
    ];
  }

  return $relationships;
}

function document_docx_embedded_images(ZipArchive $zip, string $relsPath): array {
  $relationships = document_docx_relationships($zip, $relsPath);
  if (empty($relationships)) {
    return [];
  }

  $images = [];
  foreach ($relationships as $id => $relationship) {
    $type = (string)($relationship['type'] ?? '');
    $zipPath = (string)($relationship['target'] ?? '');
    if ($zipPath === '' || !str_contains($type, '/image')) {
      continue;
    }

    $binary = $zip->getFromName($zipPath);
    if ($binary === false || $binary === '') {
      continue;
    }

    $images[$id] = 'data:' . document_docx_image_mime($zipPath) . ';base64,' . base64_encode($binary);
  }

  return $images;
}

function document_docx_rels_path_for_part(string $partPath): string {
  $partPath = str_replace('\\', '/', trim($partPath));
  if ($partPath === '') {
    return '';
  }
  $dir = dirname($partPath);
  $base = basename($partPath);
  if ($dir === '.' || $dir === '') {
    return '_rels/' . $base . '.rels';
  }
  return $dir . '/_rels/' . $base . '.rels';
}

function document_docx_render_inline_node(DOMNode $node, array $imageMap): string {
  if ($node->nodeType === XML_TEXT_NODE) {
    return '';
  }

  $name = $node->localName ?? '';
  if ($name === 't') {
    return htmlspecialchars((string)$node->textContent, ENT_QUOTES, 'UTF-8');
  }
  if ($name === 'tab') {
    return '&emsp;';
  }
  if ($name === 'br') {
    return '<br>';
  }
  if ($name === 'blip' && $node instanceof DOMElement) {
    $embedId = trim((string)$node->getAttribute('r:embed'));
    if ($embedId === '') {
      $embedId = trim((string)$node->getAttributeNS('http://schemas.openxmlformats.org/officeDocument/2006/relationships', 'embed'));
    }
    $src = $embedId !== '' ? ($imageMap[$embedId] ?? '') : '';
    if ($src !== '') {
      return '<img class="document-preview-docx-image" src="' . htmlspecialchars($src, ENT_QUOTES, 'UTF-8') . '" alt="Embedded Word image">';
    }
  }

  $html = '';
  foreach ($node->childNodes as $child) {
    $html .= document_docx_render_inline_node($child, $imageMap);
  }
  return $html;
}

function document_docx_part_html(string $xml, array $imageMap, int $limit = 120): string {
  if (!class_exists('DOMDocument')) {
    return '';
  }

  $dom = new DOMDocument();
  if (!@$dom->loadXML($xml)) {
    return '';
  }

  $xpath = new DOMXPath($dom);
  $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
  $paragraphs = $xpath->query('//w:p');
  if (!$paragraphs) {
    return '';
  }

  $html = '';
  $paragraphCount = 0;
  foreach ($paragraphs as $paragraph) {
    $content = '';
    foreach ($paragraph->childNodes as $child) {
      $content .= document_docx_render_inline_node($child, $imageMap);
    }
    $content = trim($content);
    if ($content === '') {
      continue;
    }
    $html .= '<p>' . $content . '</p>';
    $paragraphCount++;
    if ($paragraphCount >= $limit) {
      break;
    }
  }

  return $html;
}

function document_docx_header_footer_html(ZipArchive $zip, string $typeNeedle): string {
  $relationships = document_docx_relationships($zip, 'word/_rels/document.xml.rels');
  if (empty($relationships)) {
    return '';
  }

  $html = '';
  foreach ($relationships as $relationship) {
    $type = (string)($relationship['type'] ?? '');
    $target = (string)($relationship['target'] ?? '');
    if ($target === '' || !str_contains($type, '/' . $typeNeedle)) {
      continue;
    }

    $partXml = $zip->getFromName($target);
    if ($partXml === false || $partXml === '') {
      continue;
    }

    $partHtml = document_docx_part_html(
      $partXml,
      document_docx_embedded_images($zip, document_docx_rels_path_for_part($target)),
      24
    );
    if ($partHtml !== '') {
      $html .= $partHtml;
    }
  }

  return $html;
}

function document_docx_html_preview(string $absPath): ?string {
  if (!class_exists('ZipArchive') || !class_exists('DOMDocument')) {
    return null;
  }

  $zip = new ZipArchive();
  if ($zip->open($absPath) !== true) {
    return null;
  }

  $documentXml = $zip->getFromName('word/document.xml');
  if ($documentXml === false || $documentXml === '') {
    $zip->close();
    return null;
  }

  $bodyHtml = document_docx_part_html($documentXml, document_docx_embedded_images($zip, 'word/_rels/document.xml.rels'), 120);
  $headerHtml = document_docx_header_footer_html($zip, 'header');
  $footerHtml = document_docx_header_footer_html($zip, 'footer');
  $zip->close();

  $html = '';
  if ($headerHtml !== '') {
    $html .= '<header class="document-preview-docx-header">' . $headerHtml . '</header>';
  }
  if ($bodyHtml !== '') {
    $html .= '<section class="document-preview-docx-body">' . $bodyHtml . '</section>';
  }
  if ($footerHtml !== '') {
    $html .= '<footer class="document-preview-docx-footer">' . $footerHtml . '</footer>';
  }

  if ($html === '') {
    return null;
  }

  return $html;
}

function document_text_preview(string $absPath): ?string {
  $content = @file_get_contents($absPath, false, null, 0, 12000);
  if ($content === false) {
    return null;
  }
  return trim((string)$content);
}

function document_preview_payload(array $doc, ?array $latest): array {
  global $pdo;

  if (!$latest) {
    return ['kind' => 'none', 'message' => 'No preview available.'];
  }

  $filePath = (string)$latest['file_path'];
  if (!StorageService::exists($pdo, $filePath)) {
    return ['kind' => 'none', 'message' => 'Preview unavailable because the file is missing.'];
  }

  $name = (string)($doc['name'] ?? '');
  $ext = strtolower((string)pathinfo($name, PATHINFO_EXTENSION));
  $fileUrl = BASE_URL . '/documents/file?id=' . (int)$doc['id'] . '&sig=' . DocumentService::signedDocumentToken((int)$doc['id']);

  if ($ext === 'pdf') {
    return ['kind' => 'pdf', 'url' => $fileUrl];
  }
  if (document_is_video_extension($name)) {
    return ['kind' => 'video', 'url' => $fileUrl];
  }
  if (in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) {
    return ['kind' => 'image', 'url' => $fileUrl];
  }
  if (in_array($ext, ['txt', 'md', 'csv', 'json', 'xml', 'html', 'css', 'js', 'ts', 'php'], true)) {
    $text = StorageService::withReadablePath($pdo, $filePath, static fn(string $path): ?string => document_text_preview($path));
    return ['kind' => 'text', 'text' => $text];
  }
  if ($ext === 'docx') {
    $html = StorageService::withReadablePath($pdo, $filePath, static fn(string $path): ?string => document_docx_html_preview($path));
    if ($html !== null) {
      return ['kind' => 'docx-html', 'html' => $html];
    }
    $text = StorageService::withReadablePath($pdo, $filePath, static fn(string $path): ?string => document_docx_text_preview($path));
    if ($text !== null) {
      return ['kind' => 'docx-text', 'text' => $text];
    }
    return ['kind' => 'none', 'message' => 'DOCX preview is unavailable on this server. Download the file to open it externally.'];
  }

  return ['kind' => 'none', 'message' => 'Preview is not supported for this file type. Download the file to open it externally.'];
}

function create_document(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $ownerId = max(1, req_int('target_user_id', $uid));
  $folderId = req_int('folder_id', 0);
  $storageArea = req_str('storage_area', 'OFFICIAL');
  // Disable in-app file creation entirely.
  redirect('/admin/dashboard');

  $extension = strtolower(req_str('document_extension', 'xlsx'));
  $acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
  $isJsonRequest = str_contains($acceptHeader, 'application/json') || $requestedWith === 'xmlhttprequest';

  $respondCreate = static function (int $status, array $payload) use ($isJsonRequest): void {
    if ($isJsonRequest) {
      http_response_code($status);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode($payload);
      exit;
    }
  };

  $refreshUrl = '/documents?tab=routed' . documents_context_query_suffix($folderId, $ownerId);

  if (!in_array($extension, ['docx', 'xlsx'], true)) {
    $respondCreate(422, ['ok' => false, 'message' => 'Unsupported document type.']);
    redirect('/admin/dashboard');
  }

  $divisionId = 0;
  if ($ownerId > 0) {
    $owner = User::findById($pdo, $ownerId);
    $divisionId = (int)($owner['division_id'] ?? 0);
  }

  $fallbackName = $extension === 'docx' ? 'Untitled Document.docx' : 'Untitled Spreadsheet.xlsx';
  $tracking = document_tracking_payload($_POST, $fallbackName, $storageArea);
  if (($trackingError = document_tracking_required_error($tracking)) !== null) {
    $respondCreate(422, ['ok' => false, 'message' => ui_message($trackingError)]);
    redirect('/admin/dashboard');
  }

  try {
    $pdo->beginTransaction();
    $docId = DocumentService::createBlankEditableDocument(
      $pdo,
      $ownerId,
      $extension,
      $uid,
      $storageArea,
      $divisionId > 0 ? $divisionId : null,
      $tracking,
      $folderId > 0 ? $folderId : null
    );
    DocumentRoute::add(
      $pdo,
      $docId,
      null,
      (string)$tracking['current_location'],
      (string)$tracking['routing_status'],
      document_route_note('create', $tracking, $fallbackName),
      $uid
    );
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    $respondCreate(500, ['ok' => false, 'message' => 'Unable to create blank file.']);
    redirect('/admin/dashboard');
  }

  $openUrl = '/documents/view?id=' . $docId . '&open_editor=1';
  if ($ownerId !== $uid) {
    $openUrl .= '&user_id=' . $ownerId;
  }

  if ($isJsonRequest) {
    $respondCreate(200, [
      'ok' => true,
      'open_url' => cddfts_base_url_path($openUrl),
      'refresh_url' => cddfts_base_url_path($refreshUrl),
    ]);
  }

  redirect($openUrl);
}

function view_doc(): void {
  global $pdo;
  $uid = (int)$_SESSION['user']['id'];
  $docId = req_int('id', 0);

  $doc = Document::get($pdo, $docId);
  if (!$doc) { http_response_code(404); die("Not found"); }
  $level = AccessService::level($pdo, $docId, $uid);
  if (!in_array($level, ['admin', 'owner', 'editor', 'viewer', 'division_chief', 'viewer_pending', 'editor_pending', 'viewer_declined', 'editor_declined', 'division_chief_pending', 'division_chief_declined'], true)) {
    http_response_code(403);
    die("403 No access");
  }
  $shared = Permission::listForDoc($pdo, $docId);
  $latest = Version::latest($pdo, $docId);
  $reviews = DocumentReview::listForDocument($pdo, $docId);
  $routes = DocumentRoute::listForDocument($pdo, $docId);
  $canViewFile = in_array($level, ['admin', 'owner', 'editor', 'viewer', 'division_chief'], true);
  $preview = $canViewFile
    ? document_preview_payload($doc, $latest)
    : ['kind' => 'none', 'message' => 'File preview is locked until this routed document is accepted.'];

  view('documents/view', [
    'doc' => $doc,
    'level' => $level,
    'canViewFile' => $canViewFile,
    'shared' => $shared,
    'shareRecipients' => document_route_recipients($pdo, $doc, $uid),
    'latest' => $latest,
    'preview' => $preview,
    'reviews' => $reviews,
    'routes' => $routes,
    'returnUserId' => req_int('user_id', (int)$doc['owner_id']),
  ]);
}

function serve_doc_file(): void {
  global $pdo;
  $docId = req_int('id', 0);
  $sig = req_str('sig', req_str('token', ''));

  if (!DocumentService::verifyDocumentToken($docId, $sig)) {
    http_response_code(403);
    die("403 Invalid token");
  }

  $doc = Document::get($pdo, $docId);
  $version = Version::latest($pdo, $docId);
  if (!$doc || !$version) {
    http_response_code(404);
    die("Not found");
  }

  $filePath = (string)$version['file_path'];
  if (!StorageService::exists($pdo, $filePath)) {
    http_response_code(404);
    die("File missing");
  }

  $mime = StorageService::withReadablePath(
    $pdo,
    $filePath,
    static fn(string $path): string => document_preview_mime((string)$doc['name'], $path)
  ) ?? 'application/octet-stream';
  $size = StorageService::size($pdo, $filePath);
  $start = 0;
  $end = max(0, $size - 1);

  header('Content-Type: ' . $mime);
  header('Content-Disposition: inline; filename="' . basename($doc['name']) . '"');
  header('Accept-Ranges: bytes');
  header('X-Content-Type-Options: nosniff');

  $range = (string)($_SERVER['HTTP_RANGE'] ?? '');
  if ($size > 0 && preg_match('/bytes=(\d*)-(\d*)/i', $range, $matches)) {
    if ($matches[1] !== '') {
      $start = max(0, (int)$matches[1]);
    }
    if ($matches[2] !== '') {
      $end = min($end, (int)$matches[2]);
    }
    if ($matches[1] === '' && $matches[2] !== '') {
      $suffixLength = (int)$matches[2];
      $start = max(0, $size - $suffixLength);
      $end = $size - 1;
    }
    if ($start > $end || $start >= $size) {
      http_response_code(416);
      header('Content-Range: bytes */' . $size);
      exit;
    }
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
    header('Content-Length: ' . (($end - $start) + 1));
  } else {
    header('Content-Length: ' . $size);
  }

  if (!StorageService::output($pdo, $filePath, $start, $end)) {
    http_response_code(500);
    exit;
  }
  exit;
}

function download_doc(): void {
  global $pdo;
  $uid = (int)$_SESSION['user']['id'];
  $docId = req_int('id', 0);
  $versionId = req_int('version', 0);

  AccessService::requireView($pdo, $docId, $uid);
  $doc = Document::get($pdo, $docId);
  if (!$doc) { http_response_code(404); die("Not found"); }

  $v = $versionId ? Version::get($pdo, $versionId) : Version::latest($pdo, $docId);
  if (!$v || (int)$v['document_id'] !== $docId) { http_response_code(404); die("Version not found"); }

  $filePath = (string)$v['file_path'];
  if (!StorageService::exists($pdo, $filePath)) { http_response_code(404); die("File missing"); }

  AuditLog::add($pdo, $uid, "Downloaded document", $docId, "version=".$v['version_number']);
  header('Content-Type: application/octet-stream');
  header('Content-Disposition: attachment; filename="'.basename($doc['name']).'"');
  header('Content-Length: ' . StorageService::size($pdo, $filePath));
  StorageService::output($pdo, $filePath);
  exit;
}

function replace_file(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)$_SESSION['user']['id'];
  $docId = req_int('id', 0);

  AccessService::requireEdit($pdo, $docId, $uid);
  $doc = Document::get($pdo, $docId);
  if (!$doc) { redirect('/documents?err=not_found'); }
  if (document_is_finalized($doc)) {
    redirect('/documents/view?id='.$docId.'&err=decision_already_final&user_id='.(int)$doc['owner_id']);
  }
  if (strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION)) === 'pdf') {
    redirect('/documents/view?id='.$docId.'&err=feature_retired&user_id='.(int)$doc['owner_id']);
  }
  if (is_approval_locked($doc) && !is_admin_user()) {
    redirect('/documents/view?id='.$docId.'&err=approval_locked&user_id='.(int)$doc['owner_id']);
  }

  try {
    $trackingInput = $_POST;
    $trackingInput['current_location'] = (string)($doc['current_location'] ?? document_tracking_location_default((string)($doc['storage_area'] ?? 'OFFICIAL')));
    $tracking = document_tracking_payload($trackingInput, (string)($doc['name'] ?? ''), (string)($doc['storage_area'] ?? 'OFFICIAL'));
    $tracking['routing_status'] = (string)($doc['routing_status'] ?? 'AVAILABLE');
    $tracking['status'] = (string)($doc['status'] ?? 'Draft');
    $tracking['retention_until'] = $doc['retention_until'] ?? null;
    if (($trackingError = document_tracking_required_error($tracking)) !== null) {
      redirect('/documents/view?id='.$docId.'&err='.$trackingError.'&user_id='.(int)$doc['owner_id']);
    }
    Document::updateMetadata($pdo, $docId, $tracking);
    $editedFields = [];
    foreach (['document_code', 'title', 'signatory', 'priority_level', 'document_date', 'category', 'tags'] as $field) {
      $before = trim((string)($doc[$field] ?? ''));
      $after = trim((string)($tracking[$field] ?? ''));
      if ($before !== $after) {
        $editedFields[] = $field;
      }
    }
    $editNote = empty($editedFields)
      ? null
      : 'updated_fields=' . implode('|', $editedFields);

    DocumentService::uploadNewVersionWithNote($pdo, $docId, $_FILES['file'], $uid, $editNote);

    redirect('/documents/view?id='.$docId.'&msg=file_replaced');
  } catch (Throwable $e) {
    redirect('/documents/view?id='.$docId.'&err=file_replace_failed');
  }
}

function upload_version(): void {
  replace_file();
}

function soft_delete(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)$_SESSION['user']['id'];
  if (!is_super_admin_user()) {
    redirect('/admin/dashboard?err=permission_denied');
  }

  $docId = request_document_id();
  try {
    if (!permanently_delete_document($pdo, $docId, $uid)) {
      redirect('/admin/dashboard?err=not_found');
    }
  } catch (Throwable $e) {
    redirect('/admin/dashboard?err=trash_empty_failed');
  }

  redirect('/admin/dashboard?msg=file_deleted');
}

function admin_delete_document(): void {
  global $pdo;
  csrf_verify();

  if (!is_super_admin_user()) {
    http_response_code(403);
    die("403 Forbidden");
  }

  if (!document_admin_reauth_ok($pdo, req_str('confirm_password', ''))) {
    redirect('/admin/dashboard?err=reauth_failed');
  }

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = request_document_id();
  try {
    if (!permanently_delete_document($pdo, $docId, $uid)) {
      redirect('/admin/dashboard?err=not_found');
    }
  } catch (Throwable $e) {
    redirect('/admin/dashboard?err=trash_empty_failed');
  }

  redirect('/admin/dashboard?msg=file_deleted');
}

function manage_selected_documents(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $action = req_str('action', '');
  $documentIds = array_values(array_unique(array_map('intval', $_POST['document_ids'] ?? [])));
  $folderIds = array_values(array_unique(array_map('intval', $_POST['folder_ids'] ?? [])));
  $ownerId = selected_owner_id($pdo, $uid);

  if (empty($documentIds) && empty($folderIds)) {
    redirect('/admin/dashboard');
  }

  if ($action === 'delete') {
    foreach ($documentIds as $docId) {
      $doc = Document::get($pdo, $docId);
      if ($doc && can_mutate_document($doc, $uid)) {
        Document::softDelete($pdo, $docId, $uid, 'bulk_delete');
      }
    }
    foreach ($folderIds as $folderId) {
      $sourceStorageArea = req_str('source_storage_area', 'OFFICIAL');
      $folder = Folder::getForUser($pdo, $folderId, $ownerId, $sourceStorageArea);
      if (!$folder) {
        continue;
      }
      if (folder_tree_locked_document_exists($pdo, $ownerId, (string)$folder['name'], $sourceStorageArea) && !is_admin_user()) {
        redirect('/admin/dashboard');
      }
      $tree = Folder::listTreeForUser($pdo, $ownerId, (string)$folder['name'], $sourceStorageArea);
      foreach ($tree as $treeFolder) {
        Document::softDeleteByFolder($pdo, $ownerId, (int)$treeFolder['id'], $uid, 'folder_deleted', $sourceStorageArea);
      }
      Folder::softDeleteTreeForUser($pdo, $ownerId, (string)$folder['name'], $uid, $sourceStorageArea);
    }
    AuditLog::add($pdo, $uid, "Managed selected documents", null, "action=delete");
    redirect('/admin/dashboard?msg=deleted');
  }

  if ($action === 'submit_for_review') {
    $submissionDocIds = $documentIds;
    foreach ($folderIds as $folderId) {
      $folder = Folder::getForUser($pdo, $folderId, $ownerId, 'OFFICIAL');
      if (!$folder) {
        continue;
      }
      $folderIdMap = collect_folder_move_targets($pdo, $ownerId, (string)$folder['name'], 'OFFICIAL', 'OFFICIAL');
      $folderDocCandidates = Document::listActiveForOwnerInStorage($pdo, $ownerId, 'OFFICIAL');
      foreach ($folderDocCandidates as $candidate) {
        $candidateFolderId = (int)($candidate['folder_id'] ?? 0);
        if (isset($folderIdMap[$candidateFolderId])) {
          $submissionDocIds[] = (int)$candidate['id'];
        }
      }
    }
    $submissionDocIds = array_values(array_unique(array_filter($submissionDocIds)));

    foreach ($submissionDocIds as $docId) {
      $doc = Document::get($pdo, $docId);
      if (!$doc || !can_manage_document($doc, $uid)) {
        continue;
      }
      if (!can_submit_document_for_review($doc)) {
        continue;
      }
      try {
        DocumentReviewService::submitForReview($pdo, $doc, $uid);
      } catch (RuntimeException $e) {
        continue;
      }
    }
    redirect('/admin/dashboard?msg=submitted_for_review');
  }

  if (!in_array($action, ['move_to_official', 'move_to_private'], true)) {
    redirect('/admin/dashboard');
  }

  redirect('/admin/dashboard?msg=feature_retired');
}

function restore_doc(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)$_SESSION['user']['id'];
  $docId = request_document_id();
  $doc = Document::get($pdo, $docId);
  if (!$doc) { redirect('/admin/dashboard'); }
  if (!can_manage_document($doc, $uid)) { http_response_code(403); die("403 owner only"); }

  Document::restore($pdo, $docId);
  AuditLog::add($pdo, $uid, "Restored document", $docId, null);
  redirect('/admin/dashboard');
}

function restore_folder(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $ownerId = selected_owner_id($pdo, $uid);
  $folderId = request_folder_id();
  $folder = Folder::getTrashedForUser($pdo, $folderId, $ownerId);
  if (!$folder) {
    redirect('/admin/dashboard');
  }

  $storageArea = (string)($folder['storage_area'] ?? 'OFFICIAL');
  $tree = Folder::listTreeIncludingDeletedForUser($pdo, $ownerId, (string)$folder['name']);
  $treeIds = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $tree)));

  Folder::restoreTreeForUser($pdo, $ownerId, (string)$folder['name'], $storageArea);
  if (!empty($treeIds)) {
    Document::restoreByFolderIds($pdo, $treeIds, $ownerId);
  }

  AuditLog::add($pdo, $uid, "Restored folder", null, "folder_id=" . $folderId);
  redirect('/admin/dashboard?msg=restored');
}

function delete_selected_trash(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  if (!require_reauth($pdo, $uid, req_str('confirm_password', ''))) {
    redirect('/admin/dashboard');
  }

  $ownerId = selected_owner_id($pdo, $uid);
  $requestedDocumentIds = array_values(array_unique(array_map('intval', $_POST['document_ids'] ?? [])));
  $selectedFolderIds = array_values(array_unique(array_map('intval', $_POST['folder_ids'] ?? [])));
  $documentIds = [];

  foreach ($requestedDocumentIds as $docId) {
    $doc = Document::get($pdo, $docId);
    if ($doc && (int)($doc['owner_id'] ?? 0) === $ownerId && ((string)($doc['deleted_at'] ?? '') !== '' || document_is_finalized($doc))) {
      $documentIds[] = $docId;
    }
  }

  $folderPaths = [];
  foreach ($selectedFolderIds as $folderId) {
    $folder = Folder::getTrashedForUser($pdo, $folderId, $ownerId);
    if ($folder) {
      $folderPaths[] = (string)$folder['name'];
    }
  }
  $folderIds = Folder::idsInTreeForPaths($pdo, $ownerId, $folderPaths);

  $documentIds = array_values(array_filter($documentIds, static fn(int $id): bool => $id > 0));
  $folderIds = array_values(array_filter($folderIds, static fn(int $id): bool => $id > 0));
  if (empty($documentIds) && empty($folderIds)) {
    redirect('/admin/dashboard');
  }

  try {
    $deletedCount = purge_permanent_items($pdo, $ownerId, $folderIds, $documentIds);
  } catch (Throwable $e) {
    redirect('/admin/dashboard');
  }

  AuditLog::add($pdo, $uid, "Permanently deleted trash selection", null, "owner_id=".$ownerId.", count=".$deletedCount);
  redirect('/admin/dashboard?msg=trash_deleted');
}

function update_metadata(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);

  AccessService::requireEdit($pdo, $docId, $uid);
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }
  if (is_approval_locked($doc) && !is_admin_user()) {
    redirect('/documents/view?id='.$docId.'&err=approval_locked&user_id='.(int)$doc['owner_id']);
  }

  $tracking = document_tracking_payload($_POST, (string)($doc['name'] ?? ''), (string)($doc['storage_area'] ?? 'OFFICIAL'));
  $tracking['routing_status'] = (string)($doc['routing_status'] ?? 'AVAILABLE');
  if (($trackingError = document_tracking_required_error($tracking)) !== null) {
    redirect('/documents/view?id='.$docId.'&err='.$trackingError.'&user_id='.(int)$doc['owner_id']);
  }
  Document::updateMetadata($pdo, $docId, $tracking);
  AuditLog::add($pdo, $uid, "Updated document tracking details", $docId, (string)($tracking['document_code'] ?? ''));
  redirect('/documents/view?id='.$docId.'&msg=metadata_saved&user_id='.(int)$doc['owner_id']);
}

function rename_document_title(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);
  $folderId = req_int('folder_id', 0) ?: null;

  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }
  if (!can_manage_document($doc, $uid)) {
    http_response_code(403);
    die("403 owner only");
  }
  if (document_is_finalized($doc)) {
    redirect('/admin/dashboard');
  }
  if (is_approval_locked($doc) && !is_admin_user()) {
    redirect('/admin/dashboard');
  }

  $title = mb_substr(trim((string)($_POST['title'] ?? '')), 0, 255);
  if ($title === '') {
    redirect('/admin/dashboard');
  }

  Document::updateTitle($pdo, $docId, $title);
  AuditLog::add($pdo, $uid, "Renamed document title", $docId, $title);
  redirect('/admin/dashboard?msg=metadata_saved');
}

function route_document(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = req_int('id', 0);

  AccessService::requireEdit($pdo, $docId, $uid);
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }
  if (is_approval_locked($doc) && !is_admin_user()) {
    redirect('/documents/view?id='.$docId.'&err=approval_locked&user_id='.(int)$doc['owner_id']);
  }

  $nextLocation = trim(req_str('next_location', ''));
  if ($nextLocation === '') {
    redirect('/documents/view?id='.$docId.'&err=missing_fields&user_id='.(int)$doc['owner_id']);
  }

  $nextStatus = Document::normalizeRoutingStatus(req_str('routing_status', (string)($doc['routing_status'] ?? 'NOT_ROUTED')));
  $note = trim(req_str('route_note', ''));
  Document::updateTrackingState($pdo, $docId, $nextLocation, $nextStatus);
  if ($nextStatus === 'APPROVED') {
    Document::closeRoute($pdo, $docId, 'APPROVED');
  } elseif ($nextStatus === 'COMPLETED') {
    Document::closeRoute($pdo, $docId, 'COMPLETED');
  } elseif (in_array($nextStatus, ['SHARE_DECLINED', 'REVIEW_ASSIGNMENT_DECLINED'], true)) {
    Document::closeRoute($pdo, $docId, 'RETURNED');
  } elseif ($nextStatus === 'REJECTED') {
    Document::closeRoute($pdo, $docId, 'REJECTED');
  } else {
    Document::markRouteActive($pdo, $docId);
  }
  DocumentRoute::add(
    $pdo,
    $docId,
    (string)($doc['current_location'] ?? ''),
    $nextLocation,
    $nextStatus,
    $note !== '' ? $note : document_route_note('manual', [], (string)($doc['name'] ?? '')),
    $uid
  );
  AuditLog::add($pdo, $uid, "Updated document route", $docId, "to=" . $nextLocation . ", status=" . $nextStatus);
  redirect('/documents/view?id='.$docId.'&msg=route_saved&user_id='.(int)$doc['owner_id']);
}

function complete_route_lifecycle(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $docId = request_document_id();
  $doc = Document::get($pdo, $docId);
  if (!$doc) {
    redirect('/documents?err=not_found');
  }

  try {
    DocumentShareService::completeRouteLifecycle($pdo, $doc, $uid, trim(req_str('completion_note', '')));
  } catch (RuntimeException $e) {
    $error = $e->getMessage();
    if ($error === 'forbidden') {
      http_response_code(403);
      die("403 current holder only");
    }
    redirect('/documents/view?id=' . $docId . '&err=' . urlencode($error) . '&user_id=' . (int)$doc['owner_id']);
  }

  redirect(complete_route_redirect_path($pdo, $docId, $uid, (int)$doc['owner_id']));
}

function bulk_action(): void {
  global $pdo;
  csrf_verify();
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $action = req_str('action', '');
  $ids = $_POST['ids'] ?? [];
  if (!is_array($ids) || empty($ids)) {
    redirect('/admin/dashboard');
  }

  foreach (array_values(array_unique(array_map('intval', $ids))) as $docId) {
    $doc = Document::get($pdo, $docId);
    if (!$doc || !can_manage_document($doc, $uid)) {
      continue;
    }
    if ($action === 'restore') {
      Document::restore($pdo, $docId);
    } elseif ($action === 'trash') {
      Document::softDelete($pdo, $docId, $uid, 'bulk_action');
    }
  }

  AuditLog::add($pdo, $uid, "Bulk action", null, "action=".$action);
  redirect('/admin/dashboard?msg=' . ($action === 'restore' ? 'restored' : 'deleted'));
}

function require_reauth(PDO $pdo, int $userId, string $password): bool {
  if ($password === '') {
    return false;
  }
  $u = User::findById($pdo, $userId);
  return $u ? password_verify($password, (string)$u['password']) : false;
}

function is_approval_locked(array $doc): bool {
  return (int)($doc['approval_locked'] ?? 0) === 1;
}



