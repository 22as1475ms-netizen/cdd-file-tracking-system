<?php
require_once __DIR__ . "/../helpers/http.php";
require_once __DIR__ . "/../models/User.php";
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/Permission.php";
require_once __DIR__ . "/../models/Version.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/../models/Notification.php";
require_once __DIR__ . "/../models/Organization.php";
require_once __DIR__ . "/../services/AuthService.php";
require_once __DIR__ . "/../services/AccessService.php";
require_once __DIR__ . "/../services/DocumentService.php";
require_once __DIR__ . "/../services/DocumentShareService.php";
require_once __DIR__ . "/../services/StorageService.php";

function api_selected_owner_id(PDO $pdo, int $sessionUserId): int {
  $isAdmin = in_array(strtoupper((string)($_SESSION['user']['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true);
  if (!$isAdmin) {
    return $sessionUserId;
  }

  $targetUserId = max(1, req_int('user_id', req_int('target_user_id', $sessionUserId)));
  return User::findById($pdo, $targetUserId) ? $targetUserId : $sessionUserId;
}

function api_can_manage_document(array $doc, int $uid): bool {
  return in_array(strtoupper((string)($_SESSION['user']['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true) || (int)$doc['owner_id'] === $uid;
}

function api_can_rename_document_title(array $doc, int $uid): bool {
  return api_can_manage_document($doc, $uid)
    && (int)($doc['approval_locked'] ?? 0) !== 1
    && !in_array(strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE')), ['APPROVED', 'ARCHIVED'], true)
    && !in_array(strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE')), ['APPROVED', 'REJECTED'], true);
}

function api_editor_title_clean(string $title, string $extension): string {
  $clean = trim($title);
  $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));
  if ($clean === '' || $extension === '') {
    return $clean;
  }

  $clean = str_ireplace('.' . $extension, '', $clean);
  $clean = preg_replace('/\s+/', ' ', $clean) ?? $clean;
  return trim($clean);
}

function api_editor_file_name(string $fileName, string $fallbackName, string $extension): string {
  $extension = strtolower(trim($extension, ". \t\n\r\0\x0B"));
  $source = trim($fileName) !== '' ? trim($fileName) : trim($fallbackName);
  $base = trim((string)preg_replace('/\.[^.\/\\\\]+$/', '', $source));
  $base = api_editor_title_clean($base, $extension);
  if ($base === '') {
    $base = api_editor_title_clean((string)pathinfo($fallbackName, PATHINFO_FILENAME), $extension);
  }
  if ($base === '') {
    $base = 'document';
  }
  return $base . '.' . $extension;
}

function api_can_invite_to_section(PDO $pdo, array $user, int $sectionId): bool {
  if ($sectionId <= 0) {
    return false;
  }
  return TeamMember::isSectionChief($pdo, (int)($user['id'] ?? 0), $sectionId);
}

function api_can_manage_section(PDO $pdo, array $user, int $sectionId): bool {
  if ($sectionId <= 0) {
    return false;
  }

  if (in_array(strtoupper((string)($user['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true)) {
    return true;
  }

  return TeamMember::isSectionChief($pdo, (int)($user['id'] ?? 0), $sectionId);
}

function api_dispatch(string $method, string $path): bool {
  global $pdo;
  $method = strtoupper($method);

  if ($path === '/api/auth/login' && $method === 'POST') {
    $data = api_input();
    $ok = AuthService::login($pdo, trim((string)($data['email'] ?? '')), (string)($data['password'] ?? ''));
    api_json($ok ? 200 : 401, $ok ? ['ok' => true, 'user' => api_user()] : ['ok' => false, 'message' => 'Invalid credentials']);
  }

  if ($path === '/api/auth/logout' && $method === 'POST') {
    api_require_write_request();
    if (!empty($_SESSION['user']['id'])) {
      AuditLog::add($pdo, (int)$_SESSION['user']['id'], "Logged out", null, null);
    }
    session_destroy();
    api_json(200, ['ok' => true]);
  }

  if ($path === '/api/auth/me' && $method === 'GET') {
    api_require_login();
    api_json(200, ['user' => api_user()]);
  }

  if ($path === '/api/admin/org-update' && $method === 'POST') {
    api_require_login();
    $currentUser = $_SESSION['user'] ?? null;
    if (!$currentUser) {
      api_json(403, ['error' => 'Unauthorized']);
    }

    $data = api_input();
    $action = trim((string)($data['action'] ?? ''));

    if ($action === 'add_section') {
      // Only admins can create new sections/teams.
      $isAdmin = in_array(strtoupper((string)($currentUser['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true);
      if (!$isAdmin) {
        api_json(403, ['error' => 'Only admins can create teams.']);
      }

      $orgId = (int)($data['org_id'] ?? 0);
      $name = trim((string)($data['name'] ?? ''));
      $description = trim((string)($data['description'] ?? ''));

      if ($orgId <= 0 || $name === '') {
        api_json(422, ['error' => 'org_id and name are required']);
      }

      $sectionId = Section::create($pdo, $orgId, $name, $description, (int)$currentUser['id']);
      api_json(200, ['ok' => true, 'section_id' => $sectionId]);
    }

    if ($action === 'remove_member') {
      $sectionId = (int)($data['section_id'] ?? 0);
      $userId = (int)($data['user_id'] ?? 0);
      if ($sectionId <= 0 || $userId <= 0) {
        api_json(422, ['error' => 'section_id and user_id are required']);
      }
      if (!api_can_invite_to_section($pdo, $currentUser, $sectionId)) {
        api_json(403, ['error' => 'Only the assigned section chief can manage section members.']);
      }

      TeamMember::removeMember($pdo, $sectionId, $userId);
      api_json(200, ['ok' => true]);
    }

    if ($action === 'delete_section') {
      $sectionId = (int)($data['section_id'] ?? 0);
      if ($sectionId <= 0) {
        api_json(422, ['error' => 'section_id is required']);
      }
      if (!api_can_manage_section($pdo, $currentUser, $sectionId)) {
        api_json(403, ['error' => 'Only admins or the assigned section chief can delete this team.']);
      }

      Section::delete($pdo, $sectionId);
      api_json(200, ['ok' => true]);
    }

    if ($action === 'update_chief') {
      if (!in_array(strtoupper((string)($currentUser['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true)) {
        api_json(403, ['error' => 'Only admins can assign section chiefs.']);
      }

      $sectionId = (int)($data['section_id'] ?? 0);
      $chiefId = (int)($data['chief_id'] ?? 0);
      if ($sectionId <= 0 || $chiefId <= 0) {
        api_json(422, ['error' => 'section_id and chief_id are required']);
      }

      $section = Section::findById($pdo, $sectionId);
      if (!$section) {
        api_json(404, ['error' => 'Section not found']);
      }

      Section::update($pdo, $sectionId, (string)$section['name'], (string)($section['description'] ?? ''), $chiefId);
      TeamMember::setSectionChief($pdo, $sectionId, $chiefId, (int)$currentUser['id']);
      api_json(200, ['ok' => true]);
    }

    if ($action === 'update_member') {
      $sectionId = (int)($data['section_id'] ?? 0);
      $userId = (int)($data['user_id'] ?? 0);
      $role = strtoupper(trim((string)($data['role'] ?? 'MEMBER')));
      $delegateUserId = (int)($data['delegate_user_id'] ?? 0);
      $delegateNote = trim((string)($data['delegate_note'] ?? ''));

      if ($sectionId <= 0 || $userId <= 0) {
        api_json(422, ['error' => 'section_id and user_id are required']);
      }
      if (!api_can_invite_to_section($pdo, $currentUser, $sectionId)) {
        api_json(403, ['error' => 'Only the assigned section chief can manage section members.']);
      }
      if (!TeamMember::exists($pdo, $sectionId, $userId)) {
        api_json(404, ['error' => 'Member not found in this section']);
      }
      if (!in_array($role, ['MEMBER', 'TEAM_LEAD'], true)) {
        $role = 'MEMBER';
      }

      $delegateId = null;
      if ($delegateUserId > 0) {
        if ($delegateUserId === $userId) {
          api_json(422, ['error' => 'A member cannot delegate approval to themselves.']);
        }
        if (!TeamMember::exists($pdo, $sectionId, $delegateUserId)) {
          api_json(422, ['error' => 'Delegate must be an existing member of the same section.']);
        }

        $delegateUser = User::findById($pdo, $delegateUserId);
        if (!$delegateUser || strtoupper((string)($delegateUser['status'] ?? 'DISABLED')) !== 'ACTIVE') {
          api_json(422, ['error' => 'Delegate must be an active account.']);
        }
        if (in_array(strtoupper((string)($delegateUser['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true)) {
          api_json(422, ['error' => 'Admin accounts cannot be used as delegates.']);
        }

        $delegateId = $delegateUserId;
      }

      TeamMember::updateMember($pdo, $sectionId, $userId, $role, $delegateId, $delegateNote !== '' ? $delegateNote : null);
      api_json(200, ['ok' => true]);
    }

    api_json(422, ['error' => 'Unsupported action']);
  }

  if ($path === '/api/notifications/unread' && $method === 'GET') {
    api_require_login();
    $uid = (int)$_SESSION['user']['id'];
    $items = Notification::recentAll($pdo, $uid, 8);
    $payloadItems = array_map(static function (array $row): array {
      return [
        'id' => (int)($row['id'] ?? 0),
        'title' => (string)($row['title'] ?? ''),
        'body' => (string)($row['body'] ?? ''),
        'link' => Notification::resolveDestination($row),
        'is_read' => (int)($row['is_read'] ?? 0) === 1,
        'created_at' => (string)($row['created_at'] ?? ''),
      ];
    }, $items);

    api_json(200, [
      'count' => Notification::unreadCount($pdo, $uid),
      'items' => $payloadItems,
    ]);
  }

  if ($path === '/api/integrations/spreadsheet/open' && $method === 'POST') {
    $data = api_input();
    $docId = (int)($data['document_id'] ?? 0);
    $launchToken = trim((string)($data['launch_token'] ?? ''));

    if ($docId <= 0 || $launchToken === '') {
      api_json(422, ['message' => 'Missing document_id or launch_token.']);
    }

    $tokenPayload = DocumentService::verifySpreadsheetLaunchToken($launchToken, $docId);
    if (!$tokenPayload) {
      api_json(403, ['message' => 'Invalid or expired launch token.']);
    }

    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      api_json(404, ['message' => 'Document not found.']);
    }

    $extension = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
      api_json(422, ['message' => 'This document is not a spreadsheet.']);
    }

    $latestVersion = Version::latest($pdo, $docId);
    if (!$latestVersion || trim((string)($latestVersion['file_path'] ?? '')) === '') {
      api_json(404, ['message' => 'No file version found for this document.']);
    }

    $content = StorageService::withReadablePath(
      $pdo,
      (string)$latestVersion['file_path'],
      static fn(string $path): string|false => file_get_contents($path)
    );

    if ($content === false || $content === null) {
      api_json(500, ['message' => 'Unable to load spreadsheet content.']);
    }

    $userId = max(1, (int)($tokenPayload['user_id'] ?? (int)($doc['owner_id'] ?? 1)));
    $userLevel = AccessService::level($pdo, $docId, $userId);
    $documentTitle = api_editor_title_clean((string)($doc['title'] ?? ''), $extension);
    $displayName = $documentTitle !== '' ? $documentTitle : (string)($doc['name'] ?? 'spreadsheet.xlsx');
    if ($displayName === '') {
      $displayName = 'spreadsheet';
    }
    if ($extension !== '') {
      $displayName = api_editor_file_name($displayName, (string)($doc['name'] ?? 'spreadsheet.xlsx'), $extension);
    }

    api_json(200, [
      'document_id' => $docId,
      'document_name' => $displayName,
      'document_title' => $documentTitle,
      'can_rename_title' => api_can_rename_document_title($doc, $userId),
      'read_only' => !AccessService::canEditDocumentRecord($doc, $userLevel),
      'read_only_reason' => AccessService::editLockReason($doc, $userLevel),
      'file_name' => (string)($doc['name'] ?? 'spreadsheet.xlsx'),
      'mime_type' => api_spreadsheet_mime_type($extension),
      'content_base64' => base64_encode((string)$content),
    ]);
  }

  if ($path === '/api/integrations/spreadsheet/save' && $method === 'POST') {
    $data = api_input();
    $docId = (int)($data['document_id'] ?? 0);
    $launchToken = trim((string)($data['launch_token'] ?? ''));
    $contentBase64 = trim((string)($data['content_base64'] ?? ''));
    $fileName = trim((string)($data['file_name'] ?? ''));
    $documentTitle = trim((string)($data['document_title'] ?? ''));
    $sheetName = trim((string)($data['sheet_name'] ?? ''));
    $changeSummary = trim((string)($data['change_summary'] ?? ''));
    $sheetName = trim((string)($data['sheet_name'] ?? ''));
    $changeSummary = trim((string)($data['change_summary'] ?? ''));

    if ($docId <= 0 || $launchToken === '' || $contentBase64 === '') {
      api_json(422, ['message' => 'Missing document_id, launch_token, or content_base64.']);
    }

    $tokenPayload = DocumentService::verifySpreadsheetLaunchToken($launchToken, $docId);
    if (!$tokenPayload) {
      api_json(403, ['message' => 'Invalid or expired launch token.']);
    }

    $doc = Document::get($pdo, $docId);
    $content = base64_decode($contentBase64, true);
    if ($content === false) {
      api_json(422, ['message' => 'Spreadsheet payload is not valid base64.']);
    }

    if (!$doc) {
      api_json(404, ['message' => 'Document not found.']);
    }

    $userId = max(1, (int)($tokenPayload['user_id'] ?? (int)($doc['owner_id'] ?? 1)));
    $extension = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($extension, ['xls', 'xlsx'], true)) {
      api_json(422, ['message' => 'This document is not a spreadsheet.']);
    }

    $userLevel = AccessService::level($pdo, $docId, $userId);
    if (!AccessService::canEditDocumentRecord($doc, $userLevel)) {
      api_json(403, ['message' => AccessService::editLockReason($doc, $userLevel)]);
    }

    $canRenameTitle = api_can_rename_document_title($doc, $userId);
    $storedDocumentTitle = api_editor_title_clean((string)($doc['title'] ?? ''), $extension);
    if ($storedDocumentTitle === '') {
      $storedDocumentTitle = api_editor_title_clean((string)pathinfo((string)($doc['name'] ?? 'spreadsheet.' . $extension), PATHINFO_FILENAME), $extension);
    }
    $documentTitle = api_editor_title_clean($documentTitle !== '' ? $documentTitle : $fileName, $extension);
    $savedDocumentTitle = $canRenameTitle ? $documentTitle : $storedDocumentTitle;

    $pdo->beginTransaction();
    try {
      if ($docId > 0) {
        $nextFileName = api_editor_file_name($canRenameTitle ? $fileName : (string)($doc['name'] ?? ''), (string)($doc['name'] ?? ('spreadsheet.' . $extension)), $extension);
        if ($canRenameTitle) {
          Document::updateTitle($pdo, $docId, $documentTitle !== '' ? $documentTitle : null);
        }

        $versionNumber = DocumentService::uploadNewVersionFromContents($pdo, $docId, $nextFileName, (string)$content, $userId);
      } else {
        $newDocId = DocumentService::createSpreadsheetDocumentFromContents($pdo, $userId, $fileName !== '' ? $fileName : 'Untitled Spreadsheet.xlsx', (string)$content, $userId, 'OFFICIAL');
        $versionNumber = 1;
        $docId = $newDocId;
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      api_json(422, ['message' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to save spreadsheet version.']);
    }

    $auditDetails = [];
    if ($canRenameTitle && $documentTitle !== '') {
      $auditDetails[] = 'title=' . $documentTitle;
    }
    if ($sheetName !== '') {
      $auditDetails[] = 'sheet=' . $sheetName;
    }
    if ($changeSummary !== '') {
      $auditDetails[] = 'changes=' . $changeSummary;
    }
    if (!empty($auditDetails)) {
      AuditLog::add($pdo, $userId, 'Updated spreadsheet file', $docId, implode(', ', $auditDetails));
    }

    api_json(200, [
      'ok' => true,
      'document_id' => $docId,
      'version' => $versionNumber,
      'title_saved' => $canRenameTitle,
      'document_title' => $savedDocumentTitle,
    ]);
  }

  if ($path === '/api/integrations/word/open' && $method === 'POST') {
    $data = api_input();
    $docId = (int)($data['document_id'] ?? 0);
    $launchToken = trim((string)($data['launch_token'] ?? ''));

    if ($docId <= 0 || $launchToken === '') {
      api_json(422, ['message' => 'Missing document_id or launch_token.']);
    }

    $tokenPayload = DocumentService::verifyWordLaunchToken($launchToken, $docId);
    if (!$tokenPayload) {
      api_json(403, ['message' => 'Invalid or expired launch token.']);
    }

    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      api_json(404, ['message' => 'Document not found.']);
    }

    $extension = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension !== 'docx') {
      api_json(422, ['message' => 'Only DOCX files can be edited in the Word editor.']);
    }

    $latestVersion = Version::latest($pdo, $docId);
    if (!$latestVersion || trim((string)($latestVersion['file_path'] ?? '')) === '') {
      api_json(404, ['message' => 'No file version found for this document.']);
    }

    $content = StorageService::withReadablePath(
      $pdo,
      (string)$latestVersion['file_path'],
      static fn(string $path): string|false => file_get_contents($path)
    );

    if ($content === false || $content === null) {
      api_json(500, ['message' => 'Unable to load Word document content.']);
    }

    $contentHtml = DocumentService::extractDocxHtml((string)$content);
    if ($contentHtml === '') {
      $contentHtml = '<p></p>';
    }

    $userId = max(1, (int)($tokenPayload['user_id'] ?? (int)($doc['owner_id'] ?? 1)));
    $userLevel = AccessService::level($pdo, $docId, $userId);
    $readOnly = !AccessService::canEditDocumentRecord($doc, $userLevel);
    $documentTitle = api_editor_title_clean((string)($doc['title'] ?? ''), 'docx');
    $displayName = $documentTitle !== ''
      ? api_editor_file_name($documentTitle, (string)($doc['name'] ?? 'document.docx'), 'docx')
      : api_editor_file_name((string)($doc['name'] ?? 'document.docx'), 'document.docx', 'docx');

    api_json(200, [
      'document_id' => $docId,
      'document_name' => $displayName,
      'document_title' => $documentTitle,
      'can_rename_title' => api_can_rename_document_title($doc, $userId),
      'file_name' => (string)($doc['name'] ?? 'document.docx'),
      'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
      'content_html' => $contentHtml,
      'read_only' => $readOnly,
      'read_only_reason' => $readOnly ? AccessService::editLockReason($doc, $userLevel) : '',
      'workflow_status' => (string)($doc['routing_status'] ?? 'AVAILABLE'),
    ]);
  }

  if ($path === '/api/integrations/word/save' && $method === 'POST') {
    $data = api_input();
    $docId = (int)($data['document_id'] ?? 0);
    $launchToken = trim((string)($data['launch_token'] ?? ''));
    $contentHtml = trim((string)($data['content_html'] ?? ''));
    $fileName = trim((string)($data['file_name'] ?? ''));
    $documentTitle = api_editor_title_clean((string)($data['document_title'] ?? ''), 'docx');
    $changeSummary = trim((string)($data['change_summary'] ?? ''));

    if ($docId <= 0 || $launchToken === '' || $contentHtml === '') {
      api_json(422, ['message' => 'Missing document_id, launch_token, or content_html.']);
    }

    $tokenPayload = DocumentService::verifyWordLaunchToken($launchToken, $docId);
    if (!$tokenPayload) {
      api_json(403, ['message' => 'Invalid or expired launch token.']);
    }

    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      api_json(404, ['message' => 'Document not found.']);
    }

    $userId = max(1, (int)($tokenPayload['user_id'] ?? (int)($doc['owner_id'] ?? 1)));
    $userLevel = AccessService::level($pdo, $docId, $userId);
    if (!AccessService::canEditDocumentRecord($doc, $userLevel)) {
      api_json(403, ['message' => AccessService::editLockReason($doc, $userLevel)]);
    }

    $extension = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension !== 'docx') {
      api_json(422, ['message' => 'Only DOCX files can be saved through the Word editor.']);
    }

    $canRenameTitle = api_can_rename_document_title($doc, $userId);
    $storedDocumentTitle = api_editor_title_clean((string)($doc['title'] ?? ''), 'docx');
    if ($storedDocumentTitle === '') {
      $storedDocumentTitle = api_editor_title_clean((string)pathinfo((string)($doc['name'] ?? 'document.docx'), PATHINFO_FILENAME), 'docx');
    }
    $savedDocumentTitle = $canRenameTitle ? $documentTitle : $storedDocumentTitle;
    $wordDocumentTitle = $canRenameTitle
      ? ($documentTitle !== '' ? $documentTitle : (string)pathinfo((string)($doc['name'] ?? 'document.docx'), PATHINFO_FILENAME))
      : ($storedDocumentTitle !== '' ? $storedDocumentTitle : (string)pathinfo((string)($doc['name'] ?? 'document.docx'), PATHINFO_FILENAME));

    try {
      $contents = DocumentService::createWordDocumentFromHtml(
        $wordDocumentTitle,
        $contentHtml
      );
    } catch (Throwable $e) {
      api_json(422, ['message' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to prepare the Word document.']);
    }

    $nextFileName = api_editor_file_name($canRenameTitle ? $fileName : (string)($doc['name'] ?? ''), (string)($doc['name'] ?? 'document.docx'), 'docx');

    $pdo->beginTransaction();
    try {
      if ($canRenameTitle) {
        Document::updateTitle($pdo, $docId, $documentTitle !== '' ? $documentTitle : null);
      }
      $versionNumber = DocumentService::uploadNewVersionFromContents($pdo, $docId, $nextFileName, $contents, $userId);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) {
        $pdo->rollBack();
      }
      api_json(422, ['message' => $e->getMessage() !== '' ? $e->getMessage() : 'Unable to save Word document version.']);
    }

    $auditDetails = [];
    if ($canRenameTitle && $documentTitle !== '') {
      $auditDetails[] = 'title=' . $documentTitle;
    }
    if ($changeSummary !== '') {
      $auditDetails[] = 'changes=' . $changeSummary;
    }
    if (!empty($auditDetails)) {
      AuditLog::add($pdo, $userId, 'Updated Word document', $docId, implode(', ', $auditDetails));
    }

    api_json(200, [
      'ok' => true,
      'document_id' => $docId,
      'version' => $versionNumber,
      'title_saved' => $canRenameTitle,
      'document_title' => $savedDocumentTitle,
    ]);
  }

  if ($path === '/api/documents' && $method === 'GET') {
    api_require_login();
    $uid = (int)$_SESSION['user']['id'];
    $tab = req_str('tab', 'my');
    $search = req_str('search', '');
    $folder = req_int('folder', 0);
    $page = max(1, req_int('page', 1));
    $per = max(1, min(50, req_int('per', 10)));
    $targetUserId = api_selected_owner_id($pdo, $uid);

    if ($tab === 'shared') {
      [$docs, $total] = Document::listShared($pdo, $uid, $search, $page, $per);
    } elseif ($tab === 'trash') {
      [$docs, $total] = Document::listMy($pdo, $targetUserId, $search, null, $page, $per, true);
    } else {
      [$docs, $total] = Document::listMy($pdo, $targetUserId, $search, $folder ?: null, $page, $per, false);
    }

    api_json(200, ['items' => $docs, 'total' => $total, 'page' => $page, 'per' => $per]);
  }

  if ($path === '/api/documents' && $method === 'POST') {
    api_require_write_request();
    $uid = (int)$_SESSION['user']['id'];
    $folderId = req_int('folder_id', 0) ?: null;
    $ownerId = api_selected_owner_id($pdo, $uid);

    // Only admin users may upload files via the API
    if (!in_array(strtoupper((string)($_SESSION['user']['role'] ?? '')), ['SUPER_ADMIN', 'ADMIN'], true)) {
      api_json(403, ['message' => 'permission_denied']);
    }

    if (!empty($_FILES['file'])) {
      $pdo->beginTransaction();
      try {
        $docId = DocumentService::upload($pdo, $_FILES['file'], $ownerId, $folderId, $uid);
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        if (function_exists('upload_debug_log')) {
          upload_debug_log($e);
        }
        api_json(422, ['message' => 'Upload failed']);
      }
      api_json(201, ['id' => $docId]);
    }

    api_json(422, ['message' => 'In-app file creation is disabled. Upload files instead.']);
  }

  if (preg_match('#^/api/documents/(\d+)$#', $path, $m)) {
    api_require_login();
    $docId = (int)$m[1];
    $uid = (int)$_SESSION['user']['id'];

    if ($method === 'GET') {
      api_require_view($pdo, $docId, $uid);
      $doc = Document::get($pdo, $docId);
      api_json($doc ? 200 : 404, $doc ? ['document' => $doc] : ['message' => 'Not found']);
    }

    if ($method === 'PUT') {
      api_require_write_request();
      $doc = Document::get($pdo, $docId);
      if (!$doc) {
        api_json(404, ['message' => 'Not found']);
      }
      if (!api_can_manage_document($doc, $uid)) {
        api_json(403, ['message' => 'Owner access required']);
      }
      $level = AccessService::level($pdo, $docId, $uid);
      if (!AccessService::canEditDocumentRecord($doc, $level)) {
        api_json(403, ['message' => AccessService::editLockReason($doc, $level)]);
      }
      $data = api_input();
      $name = trim((string)($data['name'] ?? ''));
      if ($name === '') {
        api_json(422, ['message' => 'Document name is required']);
      }
      Document::rename($pdo, $docId, $name);
      AuditLog::add($pdo, $uid, "Renamed document", $docId, $name);
      api_json(200, ['ok' => true]);
    }

    if ($method === 'DELETE') {
      api_require_write_request();
      $doc = Document::get($pdo, $docId);
      if (!$doc || !api_can_manage_document($doc, $uid)) {
        api_json(403, ['message' => 'Owner access required']);
      }
      Document::softDelete($pdo, $docId);
      AuditLog::add($pdo, $uid, "Soft-deleted document", $docId, null);
      api_json(200, ['ok' => true]);
    }
  }

  if (preg_match('#^/api/documents/(\d+)/share$#', $path, $m) && $method === 'POST') {
    api_require_write_request();
    $docId = (int)$m[1];
    $uid = (int)$_SESSION['user']['id'];
    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      api_json(404, ['message' => 'Not found']);
    }

    $data = api_input();
    $email = trim((string)($data['email'] ?? ''));
    $target = User::findByEmail($pdo, $email);
    if (!$target) {
      api_json(404, ['message' => 'User not found']);
    }
    try {
      $result = DocumentShareService::shareDocument($pdo, $doc, $uid, $target, 'editor');
    } catch (RuntimeException $e) {
      $error = $e->getMessage();
      $status = match ($error) {
        'forbidden' => 403,
        'user_not_found', 'cannot_share_self', 'share_in_progress' => 422,
        default => 400,
      };
      api_json($status, ['ok' => false, 'message' => $error]);
    }
    api_json(200, ['ok' => true, 'share' => $result]);
  }

  if (preg_match('#^/api/documents/(\d+)/permissions$#', $path, $m) && $method === 'GET') {
    api_require_login();
    $docId = (int)$m[1];
    $uid = (int)$_SESSION['user']['id'];
    api_require_view($pdo, $docId, $uid);
    api_json(200, ['items' => Permission::listForDoc($pdo, $docId)]);
  }

  if (preg_match('#^/api/documents/(\d+)/versions$#', $path, $m) && $method === 'GET') {
    api_require_login();
    $docId = (int)$m[1];
    $uid = (int)$_SESSION['user']['id'];
    api_require_view($pdo, $docId, $uid);
    api_json(200, ['items' => Version::list($pdo, $docId)]);
  }

  api_json(404, ['message' => 'API route not found']);

  return false;
}

function api_input(): array {
  $type = (string)($_SERVER['CONTENT_TYPE'] ?? '');
  if (str_contains($type, 'application/json')) {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
  }

  if ($_SERVER['REQUEST_METHOD'] === 'PUT') {
    $raw = file_get_contents('php://input');
    parse_str($raw ?: '', $data);
    return is_array($data) ? $data : [];
  }

  return $_POST;
}

function api_spreadsheet_mime_type(string $extension): string {
  return strtolower($extension) === 'xls'
    ? 'application/vnd.ms-excel'
    : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
}

function api_require_login(): void {
  if (empty($_SESSION['user'])) {
    api_json(401, ['message' => 'Authentication required']);
  }

  if (($_SESSION['user']['status'] ?? 'ACTIVE') !== 'ACTIVE') {
    session_destroy();
    api_json(403, ['message' => 'Account disabled']);
  }

  $now = time();
  $timeout = SESSION_TIMEOUT_MINUTES * 60;
  $lastActivity = (int)($_SESSION['_last_activity'] ?? $now);
  if ($timeout > 0 && ($now - $lastActivity) > $timeout) {
    session_destroy();
    api_json(401, ['message' => 'Session expired']);
  }
  $_SESSION['_last_activity'] = $now;
}

function api_require_write_request(): void {
  api_require_login();
  csrf_verify(['POST', 'PUT', 'PATCH', 'DELETE']);
}

function api_require_view(PDO $pdo, int $docId, int $userId): void {
  if (!AccessService::level($pdo, $docId, $userId)) {
    api_json(403, ['message' => 'No access']);
  }
}

function api_require_edit(PDO $pdo, int $docId, int $userId): void {
  if (!AccessService::canEditDocument($pdo, $docId, $userId)) {
    api_json(403, ['message' => 'No edit access']);
  }
}

function api_user(): array {
  if (empty($_SESSION['user'])) {
    return [];
  }

  $user = $_SESSION['user'];
  unset($user['password']);
  return $user;
}

function api_json(int $status, array $payload): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($payload, JSON_UNESCAPED_SLASHES);
  exit;
}

function api_public_file_url(string $path): string {
  if (preg_match('#^https?://#i', $path)) {
    return $path;
  }
  $clean = '/' . ltrim(str_replace('\\', '/', $path), '/');
  return cddfts_base_url_path($clean);
}

