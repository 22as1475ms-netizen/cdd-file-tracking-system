<?php
class DocumentRoute {
  public static function add(
    PDO $pdo,
    int $documentId,
    ?string $fromLocation,
    string $toLocation,
    string $statusSnapshot,
    ?string $note,
    int $routedBy
  ): int {
    // Keep routing records tied to the super admin account for CDD-wide auditing.
    $currentRole = strtoupper((string)($_SESSION['user']['role'] ?? ''));
    if (!in_array($currentRole, ['SUPER_ADMIN', 'ADMIN'], true)) {
      try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE role IN ('SUPER_ADMIN', 'ADMIN') ORDER BY FIELD(role, 'SUPER_ADMIN', 'ADMIN'), id LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch();
        if (!empty($row['id'])) {
          $routedBy = (int)$row['id'];
        }
      } catch (Throwable $e) {
        // If something goes wrong, fall back to the provided routedBy value.
      }
    }

    $pdo->prepare("
      INSERT INTO document_routes(document_id, from_location, to_location, status_snapshot, note, routed_by)
      VALUES(?,?,?,?,?,?)
    ")->execute([
      $documentId,
      self::cleanLocation($fromLocation),
      self::cleanLocation($toLocation) ?? 'Unspecified',
      self::normalizeStatus($statusSnapshot),
      self::cleanNote($note),
      $routedBy,
    ]);

    return (int)$pdo->lastInsertId();
  }

  public static function listForDocument(PDO $pdo, int $documentId): array {
    $s = $pdo->prepare("
      SELECT r.*, u.name AS routed_by_name, u.email AS routed_by_email
      FROM document_routes r
      JOIN users u ON u.id = r.routed_by
      WHERE r.document_id = ?
      ORDER BY r.routed_at DESC, r.id DESC
    ");
    $s->execute([$documentId]);
    return $s->fetchAll();
  }

  public static function normalizeStatus(string $value): string {
    return match (strtoupper(trim($value))) {
      'PENDING_SHARE_ACCEPTANCE' => 'PENDING_SHARE_ACCEPTANCE',
      'SHARE_ACCEPTED' => 'SHARE_ACCEPTED',
      'SHARE_DECLINED' => 'SHARE_DECLINED',
      'PENDING_REVIEW_ACCEPTANCE' => 'PENDING_REVIEW_ACCEPTANCE',
      'IN_REVIEW' => 'IN_REVIEW',
      'REVIEW_ASSIGNMENT_DECLINED' => 'REVIEW_ASSIGNMENT_DECLINED',
      'APPROVED' => 'APPROVED',
      'REJECTED' => 'REJECTED',
      'COMPLETED' => 'COMPLETED',
      default => 'AVAILABLE',
    };
  }

  private static function cleanLocation(?string $value): ?string {
    $clean = trim((string)$value);
    if ($clean === '') {
      return null;
    }
    return mb_substr($clean, 0, 180);
  }

  private static function cleanNote(?string $value): ?string {
    $clean = trim((string)$value);
    if ($clean === '') {
      return null;
    }
    return mb_substr($clean, 0, 1000);
  }
}
