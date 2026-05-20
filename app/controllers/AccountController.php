<?php
require_once __DIR__ . "/../helpers/csrf.php";
require_once __DIR__ . "/../helpers/http.php";
require_once __DIR__ . "/../models/User.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/../services/StorageService.php";

function account_avatar_presets(): array {
  return ['preset-ocean', 'preset-sunset', 'preset-forest', 'preset-plum', 'preset-slate', 'preset-amber'];
}

function account_avatar_upload(array $file, int $uid): ?string {
  global $pdo;

  if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return null;
  }
  if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    return null;
  }

  $tmp = (string)($file['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) {
    return null;
  }

  $mime = (string)(mime_content_type($tmp) ?: '');
  $ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png' => 'png',
    'image/webp' => 'webp',
    default => '',
  };
  if ($ext === '') {
    return null;
  }

  $filename = 'u' . $uid . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
  $storageKey = 'public/avatars/' . $filename;
  if (!StorageService::storeUploadedFile($pdo, $file, $storageKey, [
    'kind' => 'avatar',
    'visibility' => 'public',
    'original_name' => (string)($file['name'] ?? $filename),
    'mime_type' => $mime,
    'created_by' => $uid,
  ])) {
    return null;
  }

  return StorageService::publicMediaUrl($storageKey);
}

function account_password_strength_error(string $current, string $next, string $confirm): ?string {
  if (strlen($next) < 8) {
    return 'New password must be at least 8 characters.';
  }
  if (!preg_match('/[a-z]/', $next) || !preg_match('/[A-Z]/', $next)) {
    return 'New password must include both uppercase and lowercase letters.';
  }
  if (!preg_match('/\d/', $next)) {
    return 'New password must include at least one number.';
  }
  if ($next !== $confirm) {
    return 'Password confirmation does not match.';
  }
  if ($current !== '' && $next === $current) {
    return 'New password must be different from your current password.';
  }

  return null;
}

function account_password(): void {
  global $pdo;
  $uid = (int)($_SESSION['user']['id'] ?? 0);
  $error = null;
  $msg = null;
  $profileError = null;
  $profileMsg = null;
  $currentUser = User::findById($pdo, $uid);
  $forceChange = req_str('force_change', '') === '1';

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = req_str('form_action', 'password');
    $u = User::findById($pdo, $uid);

    if ($action === 'profile') {
      if ($forceChange) {
        $profileError = 'Please change your password before updating profile settings.';
      } else {
        $newName = trim(req_str('name', ''));
        $selectedPreset = req_str('avatar_preset', 'preset-ocean');
        $usePreset = req_str('use_preset', '') === '1';
        if (!in_array($selectedPreset, account_avatar_presets(), true)) {
          $selectedPreset = 'preset-ocean';
        }
        $uploadedPath = account_avatar_upload($_FILES['avatar_photo'] ?? [], $uid);
        if ($newName === '') {
          $profileError = 'Name is required.';
        } elseif (mb_strlen($newName) > 120) {
          $profileError = 'Name is too long.';
        } else {
          User::updateName($pdo, $uid, $newName);
          $nextPhoto = $uploadedPath ?? (string)($u['avatar_photo'] ?? '');
          if ($usePreset) {
            $nextPhoto = '';
          }
          if ($uploadedPath !== null || $usePreset) {
            $oldPhoto = trim((string)($u['avatar_photo'] ?? ''));
            if ($oldPhoto !== '') {
              $oldKey = account_avatar_storage_key($oldPhoto);
              if ($oldKey !== null) {
                StorageService::delete($pdo, $oldKey);
              } elseif (str_starts_with($oldPhoto, '/uploads/avatars/')) {
                $oldAbs = cddfts_public_path(ltrim($oldPhoto, '/'));
                if (is_file($oldAbs)) {
                  @unlink($oldAbs);
                }
              }
            }
          }
          User::updateAvatar($pdo, $uid, $nextPhoto !== '' ? $nextPhoto : null, $selectedPreset);
          if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
            $_SESSION['user']['name'] = $newName;
            $_SESSION['user']['avatar_photo'] = $nextPhoto !== '' ? $nextPhoto : null;
            $_SESSION['user']['avatar_preset'] = $selectedPreset;
          }
          AuditLog::add($pdo, $uid, "Updated profile settings", null, null);
          // Redirect to ensure fresh page load with updated session
          redirect('/account/password?msg=updated');
        }
      }
    } else {
      $current = req_str('current_password', '');
      $next = req_str('new_password', '');
      $confirm = req_str('new_password_confirm', '');

      if (!$u || !password_verify($current, (string)$u['password'])) {
        $error = 'Current password is incorrect.';
      } elseif (password_verify($next, (string)$u['password'])) {
        $error = 'New password must be different from your current password.';
      } elseif (($passwordError = account_password_strength_error($current, $next, $confirm)) !== null) {
        $error = $passwordError;
      } else {
        $nextHash = User::hashPassword($next);
        User::updatePassword($pdo, $uid, $nextHash);
        if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
          $_SESSION['user']['password'] = $nextHash;
        }
        AuditLog::add($pdo, $uid, "Changed password", null, null);
        if ($forceChange) {
          redirect(workspace_home_path() . '?msg=password_updated');
        }
        $msg = 'Password updated.';
      }
    }
  }

  view('account/password', [
    'error' => $error,
    'msg' => $msg,
    'profileError' => $profileError,
    'profileMsg' => $profileMsg,
    'currentUser' => $currentUser,
    'avatarPresets' => account_avatar_presets(),
    'forceChange' => $forceChange,
  ]);
}

function account_avatar_storage_key(string $photoPath): ?string {
  $parts = parse_url($photoPath);
  $path = (string)($parts['path'] ?? '');
  parse_str((string)($parts['query'] ?? ''), $query);
  if ($path !== '/media/file') {
    return null;
  }

  $key = trim((string)($query['k'] ?? ''));
  return $key !== '' ? $key : null;
}

function account_complete_onboarding(): void {
  global $pdo;
  csrf_verify();

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  if ($uid <= 0) {
    http_response_code(401);
    exit;
  }

  $version = trim(req_str('version', ''));
  if ($version === '') {
    http_response_code(422);
    exit;
  }

  User::markOnboardingSeen($pdo, $uid, $version);
  if (isset($_SESSION['user']) && is_array($_SESSION['user'])) {
    $_SESSION['user']['onboarding_seen_at'] = date('Y-m-d H:i:s');
    $_SESSION['user']['onboarding_guide_version'] = $version;
  }

  if (
    isset($_SERVER['HTTP_ACCEPT']) &&
    str_contains((string)$_SERVER['HTTP_ACCEPT'], 'application/json')
  ) {
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
  }

  redirect('/documents');
}
