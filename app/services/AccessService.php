<?php
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/Permission.php";

class AccessService {
  // returns accepted or pending access states
  public static function level(PDO $pdo, int $docId, int $userId): ?string {
    $doc = Document::get($pdo, $docId);
    if (!$doc) return null;
    $role = strtoupper((string)($_SESSION['user']['role'] ?? ''));
    if (in_array($role, ['SUPER_ADMIN', 'ADMIN'], true)) return 'admin';
    if ((int)$doc['owner_id'] === $userId) return 'owner';
    if ((int)($doc['assigned_reviewer_id'] ?? 0) === $userId || (
      $role === 'DIVISION_CHIEF'
      && (int)($doc['assigned_reviewer_id'] ?? 0) <= 0
      && strtoupper((string)($doc['storage_area'] ?? 'PRIVATE')) === 'OFFICIAL'
      && (int)($doc['division_id'] ?? 0) > 0
      && (int)($doc['division_id'] ?? 0) === (int)($_SESSION['user']['division_id'] ?? 0)
    )) {
      $reviewAcceptance = strtoupper((string)($doc['review_acceptance_status'] ?? 'NOT_SENT'));
      if ($reviewAcceptance === 'ACCEPTED' || in_array((string)($doc['status'] ?? ''), ['Approved', 'Rejected'], true)) {
        return 'division_chief';
      }
      if ($reviewAcceptance === 'PENDING') {
        return 'division_chief_pending';
      }
      if ($reviewAcceptance === 'DECLINED') {
        return 'division_chief_declined';
      }
    }

    $permission = Permission::findRowForUser($pdo, $docId, $userId);
    if (!$permission) {
      return null;
    }
    $perm = (string)($permission['permission'] ?? '');
    if ($perm === '') {
      return null;
    }
    if (!empty($permission['accepted_at'])) {
      return $perm;
    }
    if (!empty($permission['declined_at'])) {
      return $perm . '_declined';
    }
    return $perm . '_pending';
  }

  public static function canEditDocument(PDO $pdo, int $docId, int $userId): bool {
    $doc = Document::get($pdo, $docId);
    if (!$doc) {
      return false;
    }

    return self::canEditDocumentRecord($doc, self::level($pdo, $docId, $userId));
  }

  public static function canEditDocumentRecord(array $doc, ?string $level): bool {
    if (!in_array($level, ['admin', 'owner', 'editor'], true)) {
      return false;
    }

    if ((int)($doc['approval_locked'] ?? 0) === 1) {
      return false;
    }

    $extension = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
      return false;
    }

    $routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
    $routeOutcome = strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE'));
    if (in_array($routeOutcome, ['APPROVED', 'COMPLETED', 'ARCHIVED'], true)) {
      return false;
    }

    return match ($routingStatus) {
      'PENDING_SHARE_ACCEPTANCE', 'PENDING_REVIEW_ACCEPTANCE', 'IN_REVIEW' => $level === 'admin',
      'SHARE_ACCEPTED' => in_array($level, ['admin', 'editor'], true),
      'APPROVED', 'COMPLETED' => false,
      default => true,
    };
  }

  public static function editLockReason(array $doc, ?string $level): string {
    $extension = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === 'pdf') {
      return 'PDF files are view-only in the browser workspace.';
    }

    if ((int)($doc['approval_locked'] ?? 0) === 1) {
      return 'This routed file is locked while the current review cycle is active.';
    }

    $routeOutcome = strtoupper((string)($doc['route_outcome'] ?? 'ACTIVE'));
    if (in_array($routeOutcome, ['APPROVED', 'COMPLETED', 'ARCHIVED'], true)) {
      return 'This routed file is already finalized and cannot be edited anymore.';
    }

    $routingStatus = strtoupper((string)($doc['routing_status'] ?? 'AVAILABLE'));
    return match ($routingStatus) {
      'PENDING_SHARE_ACCEPTANCE' => 'This routed file is waiting for the next recipient to accept it before editing can continue.',
      'SHARE_ACCEPTED' => $level === 'owner'
        ? 'This routed file is already with the recipient, so the original sharer can only view it until it returns.'
        : 'This routed file is currently read-only for your workflow role.',
      'PENDING_REVIEW_ACCEPTANCE' => 'This routed file is waiting for section chief acceptance, so editing is paused for now.',
      'IN_REVIEW' => 'This routed file is currently under review and cannot be edited right now.',
      'APPROVED' => 'This routed file is already approved and locked.',
      'COMPLETED' => 'This route lifecycle is already completed and kept with the final routed holder.',
      default => 'This routed file is read-only for your current access level.',
    };
  }

  public static function requireView(PDO $pdo, int $docId, int $userId): void {
    $lvl = self::level($pdo, $docId, $userId);
    if (!in_array($lvl, ['admin','owner','editor','viewer','division_chief'], true)) { http_response_code(403); die("403 No access"); }
  }

  public static function requireEdit(PDO $pdo, int $docId, int $userId): void {
    if (!self::canEditDocument($pdo, $docId, $userId)) { http_response_code(403); die("403 No edit access"); }
  }
}
