<?php
require_once __DIR__ . '/../models/User.php';

if (!isset($_SESSION['user'])) {
  redirect('/login');
}

global $pdo;
$userId = (int)($_SESSION['user']['id'] ?? 0);
$currentUser = $userId > 0 ? User::findById($pdo, $userId) : null;
if (!$currentUser || ($currentUser['status'] ?? 'ACTIVE') !== 'ACTIVE') {
  session_destroy();
  redirect('/login?err=account_disabled');
}
$_SESSION['user'] = $currentUser;

$currentPath = cddfts_normalized_request_path();
$passwordHash = (string)($currentUser['password'] ?? '');
if ($currentPath !== '/account/password' && User::usesDefaultPassword($passwordHash)) {
  redirect('/account/password?force_change=1');
}

$now = time();
$timeout = SESSION_TIMEOUT_MINUTES * 60;
$lastActivity = (int)($_SESSION['_last_activity'] ?? $now);
if ($timeout > 0 && ($now - $lastActivity) > $timeout) {
  session_destroy();
  redirect('/login?err=session_expired');
}
$_SESSION['_last_activity'] = $now;
