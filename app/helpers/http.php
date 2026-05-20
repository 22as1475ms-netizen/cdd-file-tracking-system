<?php
function req_str(string $key, string $default=''): string {
  return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : $default;
}
function req_int(string $key, int $default=0): int {
  return isset($_REQUEST[$key]) ? (int)$_REQUEST[$key] : $default;
}
function e(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function app_url(string $path=''): string {
  if (defined('APP_URL') && APP_URL !== '') {
    return APP_URL . cddfts_base_url_path($path);
  }

  $scheme = 'http';
  if (!empty($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
    $scheme = (string)$_SERVER['HTTP_X_FORWARDED_PROTO'];
  } elseif (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
    $scheme = 'https';
  }

  $host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
  return $scheme . '://' . $host . cddfts_base_url_path($path);
}

function app_url_is_publicly_reachable(?string $url = null): bool {
  $candidate = trim((string)($url ?? app_url()));
  if ($candidate === '') {
    return false;
  }

  $parts = parse_url($candidate);
  $host = strtolower(trim((string)($parts['host'] ?? '')));
  if ($host === '' || $host === 'localhost') {
    return false;
  }

  if (filter_var($host, FILTER_VALIDATE_IP)) {
    return !preg_match('/^(127\.|10\.|192\.168\.|172\.(1[6-9]|2\d|3[0-1])\.|169\.254\.)/', $host);
  }

  if ($host === '::1') {
    return false;
  }

  return true;
}

function google_docs_open_url(string $fileUrl): string {
  return 'https://docs.google.com/gview?embedded=1&url=' . rawurlencode($fileUrl);
}

function ui_message(string $raw): string {
  $map = [
    'uploaded' => 'File uploaded successfully.',
    'uploaded_private' => 'File uploaded to the routed workspace.',
    'uploaded_official' => 'File uploaded to the routed workspace.',
    'version_uploaded' => 'File replaced.',
    'file_replaced' => 'File replaced.',
    'deleted' => 'File moved to trash.',
    'file_deleted' => 'File permanently deleted.',
    'moved_to_official' => 'This older storage move is no longer used.',
    'folder_moved_to_official' => 'This older storage move is no longer used.',
    'moved_to_private' => 'This older storage move is no longer used.',
    'folder_moved_to_private' => 'This older storage move is no longer used.',
    'selection_moved_to_official' => 'This older storage move is no longer used.',
    'selection_moved_to_private' => 'This older storage move is no longer used.',
    'restored' => 'File restored.',
    'shared' => 'File shared successfully.',
    'official_only_share' => 'This older sharing restriction is no longer used.',
    'revoked' => 'Access revoked.',
    'folder_deleted' => 'Folder deleted and files moved to trash.',
    'trash_selection_deleted' => 'Selected trash items were permanently deleted.',
    'folder_renamed' => 'Folder renamed.',
    'trash_emptied' => 'Trash permanently emptied.',
    'trash_already_empty' => 'Trash is already empty.',
    'file_creation_disabled' => 'In-app file creation is disabled. Upload files instead.',
    'only_pdf_allowed' => 'Only PDF uploads are allowed.',
    'permission_denied' => 'You do not have permission to perform that action.',
    'name_conflict' => 'A file with the same name already exists in this folder.',
    'upload_failed' => 'Upload failed. Please try again.',
    'upload_ini_size_exceeded' => 'Upload failed because the file exceeds the server upload_max_filesize limit.',
    'upload_post_max_exceeded' => 'Upload failed because the full request exceeds the server post_max_size limit.',
    'upload_form_size_exceeded' => 'Upload failed because the file exceeds the form upload size limit.',
    'upload_partial' => 'Upload failed because the file was only partially uploaded.',
    'upload_no_tmp_dir' => 'Upload failed because the server temporary upload folder is missing.',
    'upload_cant_write' => 'Upload failed because the server could not write the uploaded file.',
    'upload_blocked_by_extension' => 'Upload failed because a PHP extension blocked the file upload.',
    'document_code_required' => 'Doc. ID is required before uploading a document.',
    'document_title_required' => 'Document title or subject is required.',
    'signatory_required' => 'Signatory is required.',
    'document_date_required' => 'Document date is required.',
    'category_required' => 'Category is required.',
    'single_upload_required' => 'Upload one document at a time so each file has the correct Doc. ID.',
    'version_upload_failed' => 'File replacement failed.',
    'file_replace_failed' => 'File replacement failed.',
    'trash_empty_failed' => 'Failed to empty trash.',
    'checkout_taken' => 'This older editing feature is no longer used.',
    'checked_out' => 'This older editing feature is no longer used.',
    'checked_in' => 'This older editing feature is no longer used.',
    'metadata_saved' => 'Document tracking details updated.',
    'route_saved' => 'Document route updated.',
    'feature_retired' => 'That older document-management feature is no longer used in this workflow.',
    'approval_locked' => 'This file is locked for review/approval until the current review cycle is resolved.',
    'submitted_for_review' => 'Routed file submitted to the section chief.',
    'review_assignment_accepted' => 'Section chief accepted the document for review.',
    'review_assignment_declined' => 'Review assignment was declined.',
    'review_acceptance_required' => 'The section chief must accept the routed document before approving or rejecting it.',
    'review_escalated' => 'Routed file escalated to the division chief.',
    'section_chief_required' => 'No section chief is assigned to this member yet.',
    'escalation_not_available' => 'This file is not waiting for section chief escalation.',
    'division_chief_same_as_section_chief' => 'Assign a separate division chief before escalating this file.',
    'share_accepted' => 'Shared document accepted.',
    'share_declined' => 'Shared document not accepted.',
    'route_lifecycle_completed' => 'Route lifecycle completed and returned to admin.',
    'response_note_required' => 'Please provide the reason before declining the routed document.',
    'document_approved' => 'Routed file approved and locked.',
    'document_rejected' => 'Routed file rejected. You can edit and resubmit.',
    'reject_note_required' => 'A rejection note is required.',
    'decision_already_final' => 'This review decision is already final for the current cycle.',
    'decision_invalid' => 'Invalid review decision.',
    'division_required' => 'Assign a division before using this workflow.',
    'division_chief_required' => 'No division chief is assigned to this division yet.',
    'private_not_reviewable' => 'This older storage rule is no longer used in the routed workflow.',
    'reauth_required' => 'Please confirm your password for this action.',
    'reauth_failed' => 'Password confirmation failed.',
    'session_expired' => 'Session expired due to inactivity. Please sign in again.',
    'user_created' => 'User account created.',
    'user_deleted' => 'User account permanently deleted.',
    'updated' => 'Account access updated.',
    'division_create_failed' => 'Unable to create division. Check the name and assigned chief.',
    'role_updated' => 'Account role updated.',
    'password_updated' => 'Account password updated.',
    'password_reset_to_default' => 'Account password reset to the default password.',
    'password_too_short' => 'New password must be at least 8 characters.',
    'account_disabled' => 'Your account is currently disabled. Please contact an administrator.',
    'password_mismatch' => 'New password confirmation does not match.',
    'delete_failed' => 'Failed to delete account. Please try again.',
    'email_exists' => 'An account with this email already exists.',
    'invalid_email' => 'Please provide a valid email address.',
    'missing_fields' => 'Please complete all required fields.',
    'last_admin' => 'This action is blocked because at least one admin must remain.',
    'self_delete' => 'You cannot delete your own account.',
    'self' => 'You cannot apply that action to your own account.',
    'self_role' => 'You cannot change your own role.',
  ];
  return $map[$raw] ?? str_replace('_', ' ', $raw);
}

function meta_key_label(string $key): string {
  $k = strtolower(trim($key));
  $map = [
    'user_id' => 'User',
    'deleted_user_id' => 'User',
    'doc_id' => 'Document ID',
    'document_id' => 'Document ID',
    'status' => 'Status',
    'email' => 'Email',
    'role' => 'Role',
    'permission' => 'Permission',
    'folder_id' => 'Folder ID',
    'version' => 'Version',
    'version_id' => 'Version ID',
    'reason' => 'Reason',
  ];
  if (isset($map[$k])) {
    return $map[$k];
  }
  return ucwords(str_replace('_', ' ', $k));
}

function meta_value_label(string $value): string {
  return meta_value_label_for_key('', $value);
}

function meta_value_label_for_key(string $key, string $value): string {
  $v = trim($value);
  if ($v === '') {
    return '-';
  }

  $normalizedKey = strtolower(trim($key));
  if (in_array($normalizedKey, ['user_id', 'deleted_user_id', 'created_by', 'reviewed_by', 'routed_by', 'chief_user_id', 'assigned_reviewer_id'], true)) {
    $displayCode = meta_user_display_code($v);
    if ($displayCode !== null) {
      return $displayCode;
    }
  }

  $upper = strtoupper($v);
  if ($upper === 'ACTIVE') {
    return 'Active';
  }
  if ($upper === 'DISABLED') {
    return 'Disabled';
  }
  if ($upper === 'SUPER_ADMIN' || $upper === 'ADMIN') {
    return 'Super Admin';
  }
  if ($upper === 'SECTION_ADMIN' || $upper === 'DIVISION_CHIEF') {
    return 'Section Admin';
  }
  if ($upper === 'BUSY') {
    return 'Busy';
  }
  if ($upper === 'ON_LEAVE') {
    return 'On leave';
  }
  if ($upper === 'SECTION_STAFF' || $upper === 'EMPLOYEE' || $upper === 'USER') {
    return 'Section Staff';
  }
  return $v;
}

function meta_user_display_code(string $value): ?string {
  static $cache = [];

  $trimmed = trim($value);
  if ($trimmed === '' || !ctype_digit($trimmed)) {
    return null;
  }

  $userId = (int)$trimmed;
  if ($userId <= 0) {
    return null;
  }

  if (array_key_exists($userId, $cache)) {
    return $cache[$userId];
  }

  if (!class_exists('User')) {
    $userModelPath = __DIR__ . '/../models/User.php';
    if (is_file($userModelPath)) {
      require_once $userModelPath;
    }
  }

  global $pdo;
  if (!isset($pdo) || !($pdo instanceof PDO) || !class_exists('User')) {
    return $cache[$userId] = 'USR-' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT);
  }

  $user = User::withDisplayCode($pdo, User::findById($pdo, $userId));
  if (!$user) {
    return $cache[$userId] = 'USR-' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT);
  }

  $displayCode = trim((string)($user['display_code'] ?? ''));
  return $cache[$userId] = ($displayCode !== '' ? $displayCode : 'USR-' . str_pad((string)$userId, 3, '0', STR_PAD_LEFT));
}

function availability_label(?string $status): string {
  $upper = strtoupper(trim((string)$status));
  return match ($upper) {
    'ACTIVE' => 'Available',
    'BUSY' => 'Busy',
    'ON_LEAVE' => 'On leave',
    default => 'Available',
  };
}

function availability_badge_class(?string $status): string {
  $upper = strtoupper(trim((string)$status));
  return match ($upper) {
    'ACTIVE' => 'is-success',
    'BUSY' => 'is-warning',
    'ON_LEAVE' => 'is-danger',
    default => 'is-neutral',
  };
}

function role_label(string $role): string {
  $v = trim($role);
  if ($v === '') {
    return '-';
  }
  $upper = strtoupper($v);
  if ($upper === 'SUPER_ADMIN' || $upper === 'ADMIN') {
    return 'CDD Super Admin';
  }
  if ($upper === 'SECTION_ADMIN' || $upper === 'DIVISION_CHIEF') {
    return 'Section Admin';
  }
  if ($upper === 'SECTION_STAFF' || $upper === 'EMPLOYEE' || $upper === 'USER') {
    return 'Section Staff';
  }
  if ($upper === 'PRIVATE') {
    return 'Private';
  }
  if ($upper === 'OFFICIAL') {
    return 'Official';
  }
  return ucwords(strtolower(str_replace('_', ' ', $v)));
}

function parse_meta_details(?string $meta): array {
  $text = trim((string)$meta);
  if ($text === '' || strtolower($text) === 'n/a') {
    return [];
  }

  $parts = array_filter(array_map('trim', explode(',', $text)), static function ($part) {
    return $part !== '';
  });
  $items = [];

  foreach ($parts as $part) {
    if (strpos($part, '=') !== false) {
      [$key, $value] = array_pad(explode('=', $part, 2), 2, '');
      $items[] = [
        'label' => meta_key_label((string)$key),
        'value' => meta_value_label_for_key((string)$key, (string)$value),
      ];
      continue;
    }

    $items[] = [
      'label' => 'Note',
      'value' => meta_value_label((string)$part),
    ];
  }

  return $items;
}

function avatar_initials(string $name): string {
  $parts = preg_split('/\s+/', trim($name));
  $initials = '';
  foreach (array_slice(array_filter($parts), 0, 2) as $part) {
    $initials .= strtoupper(substr((string)$part, 0, 1));
  }
  if ($initials === '') {
    $initials = strtoupper(substr($name, 0, 2));
  }
  return $initials !== '' ? $initials : 'U';
}

function avatar_photo_url(array $user): ?string {
  $photo = trim((string)($user['avatar_photo'] ?? ''));
  if ($photo === '') {
    return null;
  }
  if (preg_match('#^https?://#i', $photo)) {
    return $photo;
  }
  // If photo already contains /media/file (from publicMediaUrl), don't double-wrap it
  if (str_contains($photo, '/media/file')) {
    return $photo;
  }
  if (!str_starts_with($photo, '/')) {
    $photo = '/' . $photo;
  }
  return cddfts_base_url_path($photo);
}

function avatar_preset_key(array $user): string {
  $preset = trim((string)($user['avatar_preset'] ?? ''));
  if ($preset === '') {
    return 'preset-ocean';
  }
  return preg_match('/^[a-z0-9\-]+$/i', $preset) ? $preset : 'preset-ocean';
}

function folder_location_label(?string $folderName): string {
  $name = trim((string)$folderName);
  return $name !== '' ? $name : 'Top level';
}

function folder_display_name(?string $folderName): string {
  $name = trim((string)$folderName);
  if ($name === '') {
    return 'Top level';
  }

  $segments = preg_split('/\s*\/\s*|\s*\\\\\s*|\s+\|\s+/', str_replace(' / ', '/', $name)) ?: [];
  $segments = array_values(array_filter(array_map(
    static fn(string $segment): string => trim($segment),
    $segments
  ), static fn(string $segment): bool => $segment !== ''));

  return $segments ? (string)end($segments) : $name;
}
