<?php
class User {
  public static function withDisplayCode(PDO $pdo, ?array $user): ?array {
    if (!$user) {
      return null;
    }

    $annotated = self::attachDisplayCodes($pdo, [$user]);
    return $annotated[0] ?? $user;
  }

  public static function attachDisplayCodes(PDO $pdo, array $users): array {
    if (empty($users)) {
      return $users;
    }

    $codeMap = self::displayCodeMap($pdo);
    foreach ($users as &$user) {
      $userId = (int)($user['id'] ?? 0);
      $role = (string)($user['role'] ?? 'SECTION_STAFF');
      $user['display_code'] = $codeMap[$userId] ?? self::buildDisplayCode(self::normalizeRole($role), 0);
    }
    unset($user);

    return $users;
  }

  public static function hashPassword(string $password): string {
    if (defined('PASSWORD_ARGON2ID')) {
      return password_hash($password, PASSWORD_ARGON2ID);
    }

    return password_hash($password, PASSWORD_DEFAULT);
  }

  public static function passwordNeedsRehash(string $hash): bool {
    if (defined('PASSWORD_ARGON2ID')) {
      return password_needs_rehash($hash, PASSWORD_ARGON2ID);
    }

    return password_needs_rehash($hash, PASSWORD_DEFAULT);
  }

  public static function defaultPassword(): string {
    return 'password';
  }

  public static function usesDefaultPassword(string $hash): bool {
    return $hash !== '' && password_verify(self::defaultPassword(), $hash);
  }

  public static function findByEmail(PDO $pdo, string $email): ?array {
    $s = $pdo->prepare("
      SELECT u.*, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE u.email=? LIMIT 1
    ");
    $s->execute([$email]);
    $u = $s->fetch();
    return $u ?: null;
  }

  public static function findById(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("
      SELECT u.*, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE u.id=? LIMIT 1
    ");
    $s->execute([$id]);
    $u = $s->fetch();
    return $u ?: null;
  }

  public static function all(PDO $pdo): array {
    return $pdo->query("
      SELECT u.id, u.name, u.email, u.role, u.status, u.division_id, u.created_at, u.avatar_photo, u.avatar_preset, u.generated_password, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      ORDER BY u.id DESC
    ")->fetchAll();
  }

  public static function allNonAdmins(PDO $pdo): array {
    return $pdo->query("
      SELECT u.id, u.name, u.email, u.role, u.status, u.division_id, u.created_at, u.avatar_photo, u.avatar_preset, u.generated_password, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE u.role NOT IN ('SUPER_ADMIN', 'ADMIN')
      ORDER BY u.id DESC
    ")->fetchAll();
  }

  public static function allEmployees(PDO $pdo): array {
    return $pdo->query("
      SELECT u.id, u.name, u.email, u.role, u.status, u.division_id, u.created_at, u.avatar_photo, u.avatar_preset, u.generated_password, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE u.role IN ('SECTION_STAFF', 'EMPLOYEE')
      ORDER BY u.name
    ")->fetchAll();
  }

  public static function listShareRecipients(PDO $pdo, ?int $excludeUserId = null, ?int $divisionId = null): array {
    $sql = "
      SELECT
        u.id,
        u.name,
        u.email,
        u.role,
        u.status,
        u.availability_status,
        u.availability_note,
        u.availability_status,
        u.availability_note,
        u.division_id,
        u.created_at,
        u.avatar_photo,
        u.avatar_preset,
        d.name AS division_name,
        chief.id AS chief_user_id,
        chief.name AS chief_name,
        chief.email AS chief_email
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      LEFT JOIN users chief ON chief.id = d.chief_user_id
      WHERE u.role IN ('SECTION_STAFF', 'SECTION_ADMIN', 'EMPLOYEE', 'ADMIN', 'DIVISION_CHIEF') AND u.status = 'ACTIVE'
    ";
    $params = [];
    if ($excludeUserId !== null && $excludeUserId > 0) {
      $sql .= " AND u.id <> ? ";
      $params[] = $excludeUserId;
    }
    if ($divisionId !== null && $divisionId > 0) {
      $sql .= " AND u.division_id = ? ";
      $params[] = $divisionId;
    }
    $sql .= " ORDER BY COALESCE(d.name, 'ZZZ'), u.name ";

    $s = $pdo->prepare($sql);
    $s->execute($params);
    return $s->fetchAll();
  }

  public static function allDivisionChiefs(PDO $pdo): array {
    return $pdo->query("
      SELECT u.id, u.name, u.email, u.role, u.status, u.division_id, u.created_at, u.avatar_photo, u.avatar_preset, u.generated_password, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE u.role = 'SECTION_ADMIN' AND u.status = 'ACTIVE'
      ORDER BY u.name
    ")->fetchAll();
  }

  public static function allActiveNonAdmins(PDO $pdo): array {
    return $pdo->query("
      SELECT u.id, u.name, u.email, u.role, u.status, u.division_id, u.created_at, u.avatar_photo, u.avatar_preset, u.generated_password, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE u.role NOT IN ('SUPER_ADMIN', 'ADMIN') AND u.status = 'ACTIVE'
      ORDER BY u.name
    ")->fetchAll();
  }

  public static function setStatus(PDO $pdo, int $id, string $status): void {
    $pdo->prepare("UPDATE users SET status=? WHERE id=?")->execute([$status, $id]);
  }

  public static function normalizeAvailabilityStatus(string $status): string {
    return match (strtoupper(trim($status))) {
      'BUSY' => 'BUSY',
      'ON_LEAVE' => 'ON_LEAVE',
      default => 'ACTIVE',
    };
  }

  public static function setAvailability(PDO $pdo, int $id, string $availabilityStatus, ?string $note = null): void {
    $status = self::normalizeAvailabilityStatus($availabilityStatus);
    $cleanNote = trim((string)$note);
    $pdo->prepare("UPDATE users SET availability_status=?, availability_note=? WHERE id=?")
      ->execute([$status, $cleanNote !== '' ? mb_substr($cleanNote, 0, 255) : null, $id]);
  }

  public static function setRole(PDO $pdo, int $id, string $role): void {
    $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([self::normalizeRole($role), $id]);
  }

  public static function setDivision(PDO $pdo, int $id, ?int $divisionId): void {
    $pdo->prepare("UPDATE users SET division_id=? WHERE id=?")->execute([$divisionId, $id]);
  }

  public static function search(PDO $pdo, string $q): array {
    $q = "%$q%";
    $s = $pdo->prepare("
      SELECT id, name, email, role, division_id
      FROM users
      WHERE status='ACTIVE' AND (name LIKE ? OR email LIKE ?)
      ORDER BY name
      LIMIT 20
    ");
    $s->execute([$q, $q]);
    return $s->fetchAll();
  }

  public static function countAll(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  }

  public static function countActive(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status='ACTIVE'")->fetchColumn();
  }

  public static function updatePassword(PDO $pdo, int $id, string $hash, ?string $generatedPassword = null): void {
    $pdo->prepare("UPDATE users SET password=?, generated_password=? WHERE id=?")
      ->execute([$hash, self::cleanGeneratedPassword($generatedPassword), $id]);
  }

  public static function updateName(PDO $pdo, int $id, string $name): void {
    $pdo->prepare("UPDATE users SET name=? WHERE id=?")->execute([$name, $id]);
  }

  public static function updateAvatar(PDO $pdo, int $id, ?string $photoPath, ?string $preset): void {
    $pdo->prepare("UPDATE users SET avatar_photo=?, avatar_preset=? WHERE id=?")
      ->execute([$photoPath, $preset, $id]);
  }

  public static function markOnboardingSeen(PDO $pdo, int $id, string $version): void {
    $pdo->prepare("UPDATE users SET onboarding_seen_at=NOW(), onboarding_guide_version=? WHERE id=?")
      ->execute([$version, $id]);
  }

  public static function emailExists(PDO $pdo, string $email): bool {
    $s = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email=?");
    $s->execute([$email]);
    return (int)$s->fetchColumn() > 0;
  }

  public static function create(PDO $pdo, string $name, string $email, string $role, string $status, string $passwordHash, ?int $divisionId = null, ?string $generatedPassword = null): int {
    $pdo->prepare("INSERT INTO users(name,email,password,role,status,division_id,availability_status,generated_password) VALUES(?,?,?,?,?,?, 'ACTIVE', ?)")
      ->execute([$name, $email, $passwordHash, self::normalizeRole($role), $status, $divisionId, self::cleanGeneratedPassword($generatedPassword)]);
    return (int)$pdo->lastInsertId();
  }

  public static function countAdmins(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('SUPER_ADMIN', 'ADMIN')")->fetchColumn();
  }

  public static function countActiveAdmins(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role IN ('SUPER_ADMIN', 'ADMIN') AND status='ACTIVE'")->fetchColumn();
  }

  public static function deleteById(PDO $pdo, int $id): void {
    $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$id]);
  }

  public static function listEmployeesByDivision(PDO $pdo, int $divisionId): array {
    $s = $pdo->prepare("
      SELECT u.id, u.name, u.email, u.role, u.status, u.division_id, u.created_at, u.avatar_photo, u.avatar_preset, u.generated_password, d.name AS division_name
      FROM users u
      LEFT JOIN divisions d ON d.id = u.division_id
      WHERE u.role IN ('SECTION_STAFF', 'EMPLOYEE') AND u.division_id=?
      ORDER BY u.name
    ");
    $s->execute([$divisionId]);
    return $s->fetchAll();
  }

  public static function normalizeRole(string $role): string {
    return match (strtoupper(trim($role))) {
      'SUPER_ADMIN' => 'SUPER_ADMIN',
      'ADMIN' => 'SUPER_ADMIN',
      'SECTION_ADMIN' => 'SECTION_ADMIN',
      'DIVISION_CHIEF' => 'SECTION_ADMIN',
      'SECTION_STAFF' => 'SECTION_STAFF',
      'EMPLOYEE', 'USER' => 'SECTION_STAFF',
      default => 'SECTION_STAFF',
    };
  }

  public static function displayCodePrefix(string $role): string {
    return match (self::normalizeRole($role)) {
      'SUPER_ADMIN' => 'SUP',
      'SECTION_ADMIN' => 'SEC',
      default => 'STF',
    };
  }

  private static function cleanGeneratedPassword(?string $password): ?string {
    $clean = trim((string)$password);
    return $clean !== '' ? mb_substr($clean, 0, 255) : null;
  }

  private static function displayCodeMap(PDO $pdo): array {
    $rows = $pdo->query("
      SELECT id, role
      FROM users
      ORDER BY id ASC
    ")->fetchAll();

    $roleCounters = [
      'SUPER_ADMIN' => 0,
      'SECTION_ADMIN' => 0,
      'SECTION_STAFF' => 0,
    ];
    $map = [];

    foreach ($rows as $row) {
      $userId = (int)($row['id'] ?? 0);
      if ($userId <= 0) {
        continue;
      }

      $normalizedRole = self::normalizeRole((string)($row['role'] ?? 'SECTION_STAFF'));
      $roleCounters[$normalizedRole] = ($roleCounters[$normalizedRole] ?? 0) + 1;
      $map[$userId] = self::buildDisplayCode($normalizedRole, $roleCounters[$normalizedRole]);
    }

    return $map;
  }

  private static function buildDisplayCode(string $normalizedRole, int $ordinal): string {
    $prefix = self::displayCodePrefix($normalizedRole);
    $safeOrdinal = max(1, $ordinal);
    return $prefix . '-' . str_pad((string)$safeOrdinal, 3, '0', STR_PAD_LEFT);
  }
}
