<?php
class Permission {
  public static function findRowForUser(PDO $pdo, int $docId, int $userId): ?array {
    $s = $pdo->prepare("SELECT * FROM permissions WHERE document_id=? AND user_id=? LIMIT 1");
    $s->execute([$docId, $userId]);
    $row = $s->fetch();
    return $row ?: null;
  }

  public static function getForUser(PDO $pdo, int $docId, int $userId): ?string {
    $s = $pdo->prepare("SELECT permission FROM permissions WHERE document_id=? AND user_id=? LIMIT 1");
    $s->execute([$docId, $userId]);
    $p = $s->fetchColumn();
    return $p ? (string)$p : null;
  }

  public static function upsert(PDO $pdo, int $docId, int $userId, string $perm, ?int $sharedBy = null): void {
    $pdo->prepare("
      INSERT INTO permissions(document_id,user_id,permission,shared_by,accepted_at,declined_at,response_note)
      VALUES(?,?,?,?,NULL,NULL,NULL)
      ON DUPLICATE KEY UPDATE
        permission=VALUES(permission),
        shared_by=VALUES(shared_by),
        accepted_at=NULL,
        declined_at=NULL,
        response_note=NULL
    ")->execute([$docId, $userId, $perm, $sharedBy]);
  }

  public static function accept(PDO $pdo, int $docId, int $userId): void {
    $pdo->prepare("
      UPDATE permissions
      SET accepted_at=NOW(), declined_at=NULL, response_note=NULL
      WHERE document_id=? AND user_id=?
    ")->execute([$docId, $userId]);
  }

  public static function decline(PDO $pdo, int $docId, int $userId, ?string $note): void {
    $pdo->prepare("
      UPDATE permissions
      SET accepted_at=NULL, declined_at=NOW(), response_note=?
      WHERE document_id=? AND user_id=?
    ")->execute([self::cleanNote($note), $docId, $userId]);
  }

  public static function revoke(PDO $pdo, int $docId, int $userId): void {
    $pdo->prepare("DELETE FROM permissions WHERE document_id=? AND user_id=?")->execute([$docId, $userId]);
  }

  public static function listForDoc(PDO $pdo, int $docId): array {
    $s = $pdo->prepare("
      SELECT p.*, u.name, u.email
      FROM permissions p
      JOIN users u ON u.id=p.user_id
      WHERE p.document_id=?
      ORDER BY u.name
    ");
    $s->execute([$docId]);
    return $s->fetchAll();
  }

  public static function currentAcceptedUserId(PDO $pdo, int $docId): ?int {
    $s = $pdo->prepare("
      SELECT user_id
      FROM permissions
      WHERE document_id = ?
        AND accepted_at IS NOT NULL
      ORDER BY accepted_at DESC, id DESC
      LIMIT 1
    ");
    $s->execute([$docId]);
    $value = $s->fetchColumn();
    if ($value === false) {
      return null;
    }
    return (int)$value;
  }

  public static function deleteByDocumentIds(PDO $pdo, array $docIds): int {
    if (empty($docIds)) {
      return 0;
    }

    $ids = array_values(array_map('intval', $docIds));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("DELETE FROM permissions WHERE document_id IN ($ph)");
    $s->execute($ids);
    return $s->rowCount();
  }

  private static function cleanNote(?string $note): ?string {
    $clean = trim((string)$note);
    if ($clean === '') {
      return null;
    }
    return mb_substr($clean, 0, 1000);
  }
}
