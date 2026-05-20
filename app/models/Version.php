<?php
class Version {
  public static function nextNumber(PDO $pdo, int $docId): int {
    $s = $pdo->prepare("SELECT COALESCE(MAX(version_number),0) FROM document_versions WHERE document_id=?");
    $s->execute([$docId]);
    return ((int)$s->fetchColumn()) + 1;
  }

  public static function add(PDO $pdo, int $docId, int $userId, string $filePath, int $versionNumber): int {
    $pdo->prepare("
      INSERT INTO document_versions(document_id,file_path,version_number,created_by)
      VALUES(?,?,?,?)
    ")->execute([$docId, $filePath, $versionNumber, $userId]);

    return (int)$pdo->lastInsertId();
  }

  public static function list(PDO $pdo, int $docId): array {
    $s = $pdo->prepare("
      SELECT dv.*, u.name created_by_name
      FROM document_versions dv
      JOIN users u ON u.id=dv.created_by
      WHERE dv.document_id=?
      ORDER BY dv.version_number DESC
    ");
    $s->execute([$docId]);
    return $s->fetchAll();
  }

  public static function get(PDO $pdo, int $versionId): ?array {
    $s = $pdo->prepare("SELECT * FROM document_versions WHERE id=? LIMIT 1");
    $s->execute([$versionId]);
    $r = $s->fetch();
    return $r ?: null;
  }

  public static function latest(PDO $pdo, int $docId): ?array {
    static $cache = [];
    if ($docId <= 0) {
      return null;
    }
    if (array_key_exists($docId, $cache)) {
      return $cache[$docId];
    }

    $s = $pdo->prepare("SELECT * FROM document_versions WHERE document_id=? ORDER BY version_number DESC LIMIT 1");
    $s->execute([$docId]);
    $r = $s->fetch();
    return $cache[$docId] = ($r ?: null);
  }

  public static function countAll(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM document_versions")->fetchColumn();
  }

  public static function filePathsByDocumentIds(PDO $pdo, array $docIds): array {
    if (empty($docIds)) {
      return [];
    }

    $ids = array_values(array_map('intval', $docIds));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("SELECT file_path FROM document_versions WHERE document_id IN ($ph)");
    $s->execute($ids);
    return array_map(static fn(array $row): string => (string)$row['file_path'], $s->fetchAll());
  }

  public static function deleteByDocumentIds(PDO $pdo, array $docIds): int {
    if (empty($docIds)) {
      return 0;
    }

    $ids = array_values(array_map('intval', $docIds));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("DELETE FROM document_versions WHERE document_id IN ($ph)");
    $s->execute($ids);
    return $s->rowCount();
  }
}
