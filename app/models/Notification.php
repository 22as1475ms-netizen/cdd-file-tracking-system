<?php
class Notification {
  public static function add(PDO $pdo, int $userId, string $title, ?string $body = null, ?string $link = null): void {
    if (!self::shouldStore($pdo, $userId, $title)) {
      return;
    }
    $pdo->prepare("INSERT INTO notifications(user_id,title,body,link) VALUES(?,?,?,?)")
      ->execute([$userId, $title, $body, $link]);
  }

  public static function recentUnread(PDO $pdo, int $userId, int $limit = 6): array {
    $s = $pdo->prepare("
      SELECT *
      FROM notifications
      WHERE user_id=? AND is_read=0
      ORDER BY id DESC
      LIMIT ?
    ");
    $s->bindValue(1, $userId, PDO::PARAM_INT);
    $s->bindValue(2, $limit, PDO::PARAM_INT);
    $s->execute();
    return $s->fetchAll();
  }

  public static function recentAll(PDO $pdo, int $userId, int $limit = 10): array {
    $s = $pdo->prepare("
      SELECT *
      FROM notifications
      WHERE user_id=?
      ORDER BY id DESC
      LIMIT ?
    ");
    $s->bindValue(1, $userId, PDO::PARAM_INT);
    $s->bindValue(2, $limit, PDO::PARAM_INT);
    $s->execute();
    return $s->fetchAll();
  }

  public static function unreadCount(PDO $pdo, int $userId): int {
    $s = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id=? AND is_read=0");
    $s->execute([$userId]);
    return (int)$s->fetchColumn();
  }

  public static function markAllRead(PDO $pdo, int $userId): void {
    $pdo->prepare("UPDATE notifications SET is_read=1 WHERE user_id=? AND is_read=0")->execute([$userId]);
  }

  public static function clearAll(PDO $pdo, int $userId): void {
    $pdo->prepare("DELETE FROM notifications WHERE user_id=?")->execute([$userId]);
  }

  public static function resolveDestination(array $row): string {
    $link = trim((string)($row['link'] ?? ''));
    $title = trim((string)($row['title'] ?? ''));
    $body = trim((string)($row['body'] ?? ''));
    $blob = strtolower($title . ' ' . $body);
    $docId = self::extractDocumentId($link, $title, $body);

    if ($link !== '' && $link !== '/documents') {
      return $link;
    }

    if (str_contains($blob, 'shared with you') || str_contains($blob, 'access was revoked') || str_contains($blob, 'revoked')) {
      return '/documents?tab=shared';
    }
    if ((str_contains($blob, 'approved') || str_contains($blob, 'rejected') || str_contains($blob, 'review')) && $docId !== null) {
      return '/documents/view?id=' . $docId;
    }
    if ((str_contains($blob, 'document') || str_contains($blob, 'version') || str_contains($blob, 'shared') || str_contains($blob, 'route')) && $docId !== null) {
      return '/documents/view?id=' . $docId;
    }

    return '';
  }

  private static function shouldStore(PDO $pdo, int $userId, string $title): bool {
    if ($userId <= 0) {
      return false;
    }

    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$userId]);
    $role = strtoupper((string)$stmt->fetchColumn());
    if (!in_array($role, ['SUPER_ADMIN', 'ADMIN'], true)) {
      return true;
    }

    return in_array(trim($title), [
      'Route lifecycle completed',
      'File shared to another user',
    ], true);
  }

  private static function extractDocumentId(string $link, string $title, string $body): ?int {
    if ($link !== '' && preg_match('/[?&]id=(\d+)/', $link, $m)) {
      return (int)$m[1];
    }

    $haystack = $title . ' ' . $body;
    if (preg_match('/document\s*#\s*(\d+)/i', $haystack, $m)) {
      return (int)$m[1];
    }

    return null;
  }
}
