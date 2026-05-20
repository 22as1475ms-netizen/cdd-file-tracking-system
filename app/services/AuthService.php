<?php
require_once __DIR__ . "/../models/User.php";
require_once __DIR__ . "/../models/AuditLog.php";

class AuthService {
  public static function login(PDO $pdo, string $email, string $password): bool {
    $u = User::findByEmail($pdo, $email);
    if (!$u) return false;
    if (($u['status'] ?? 'ACTIVE') !== 'ACTIVE') return false;

    if (password_verify($password, $u['password'])) {
      if (User::passwordNeedsRehash((string)$u['password'])) {
        User::updatePassword($pdo, (int)$u['id'], User::hashPassword($password));
        $u = User::findById($pdo, (int)$u['id']) ?? $u;
      }
      if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
      }
      $_SESSION['user'] = $u;
      $_SESSION['_last_activity'] = time();
      AuditLog::add($pdo, (int)$u['id'], "Logged in", null, null);
      return true;
    }
    return false;
  }
}
