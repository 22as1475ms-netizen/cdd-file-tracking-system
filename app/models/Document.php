<?php
class Document {
  private static function sortOrderSql(array $filters = [], string $fallback = 'd.id DESC'): string {
    $sort = trim((string)($filters['sort'] ?? ''));
    $activityExpr = "COALESCE((SELECT MAX(dv.created_at) FROM document_versions dv WHERE dv.document_id=d.id), d.reviewed_at, d.submitted_at, d.created_at)";
    return match ($sort) {
      'name_asc' => 'd.name ASC, d.id ASC',
      'name_desc' => 'd.name DESC, d.id DESC',
      'modified_asc' => $activityExpr . ' ASC, d.id ASC',
      'modified_desc' => $activityExpr . ' DESC, d.id DESC',
      default => $fallback,
    };
  }

  public static function create(
    PDO $pdo,
    int $ownerId,
    ?int $folderId,
    string $name,
    string $storageArea = 'PRIVATE',
    ?int $divisionId = null,
    array $metadata = []
  ): int {
    $storageArea = self::normalizeStorageArea($storageArea);
    $pdo->prepare("
      INSERT INTO documents(
        name, owner_id, folder_id, storage_area, division_id, document_code, title,
        document_type, signatory, current_location, routing_status, priority_level, document_date,
        tags, category, status, retention_until
      )
      VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
      $name,
      $ownerId,
      $folderId,
      $storageArea,
      $divisionId,
      self::cleanText($metadata['document_code'] ?? null, 80),
      self::cleanText($metadata['title'] ?? null, 255),
      self::normalizeDocumentType((string)($metadata['document_type'] ?? 'INCOMING')),
      self::cleanText($metadata['signatory'] ?? null, 150),
      self::cleanText($metadata['current_location'] ?? null, 180),
      self::normalizeRoutingStatus((string)($metadata['routing_status'] ?? 'NOT_ROUTED')),
      self::normalizePriorityLevel((string)($metadata['priority_level'] ?? 'NORMAL')),
      self::cleanDate($metadata['document_date'] ?? null),
      $metadata['tags'] ?? null,
      $metadata['category'] ?? null,
      $metadata['status'] ?? 'Draft',
      $metadata['retention_until'] ?? null,
    ]);
    $documentId = (int)$pdo->lastInsertId();
    self::markRouteActive($pdo, $documentId);
    return $documentId;
  }

  public static function normalizeStorageArea(string $storageArea): string {
    return strtoupper(trim($storageArea)) === 'OFFICIAL' ? 'OFFICIAL' : 'PRIVATE';
  }

  public static function rename(PDO $pdo, int $id, string $name): void {
    $pdo->prepare("UPDATE documents SET name=? WHERE id=?")->execute([$name, $id]);
  }

  public static function updateTitle(PDO $pdo, int $id, ?string $title): void {
    $pdo->prepare("UPDATE documents SET title=? WHERE id=?")->execute([
      self::cleanText($title, 255),
      $id
    ]);
  }

  public static function updateMetadata(PDO $pdo, int $id, array $data): void {
    $pdo->prepare("
      UPDATE documents
      SET document_code = ?, title = ?, document_type = ?, signatory = ?, current_location = ?,
          routing_status = ?, priority_level = ?, document_date = ?, tags = ?, category = ?,
          status = ?, retention_until = ?, storage_area = ?
      WHERE id = ?
    ")->execute([
      self::cleanText($data['document_code'] ?? null, 80),
      self::cleanText($data['title'] ?? null, 255),
      self::normalizeDocumentType((string)($data['document_type'] ?? 'INCOMING')),
      self::cleanText($data['signatory'] ?? null, 150),
      self::cleanText($data['current_location'] ?? null, 180),
      self::normalizeRoutingStatus((string)($data['routing_status'] ?? 'NOT_ROUTED')),
      self::normalizePriorityLevel((string)($data['priority_level'] ?? 'NORMAL')),
      self::cleanDate($data['document_date'] ?? null),
      $data['tags'] ?? null,
      $data['category'] ?? null,
      $data['status'] ?? 'Draft',
      $data['retention_until'] ?? null,
      self::normalizeStorageArea((string)($data['storage_area'] ?? 'PRIVATE')),
      $id,
    ]);
  }

  public static function updateTrackingState(PDO $pdo, int $id, string $currentLocation, string $routingStatus): void {
    $pdo->prepare("
      UPDATE documents
      SET current_location = ?, routing_status = ?
      WHERE id = ?
    ")->execute([
      self::cleanText($currentLocation, 180),
      self::normalizeRoutingStatus($routingStatus),
      $id,
    ]);
  }

  public static function markRouteActive(PDO $pdo, int $id): void {
    $pdo->prepare("
      UPDATE documents
      SET route_outcome='ACTIVE', route_closed_at=NULL
      WHERE id=?
    ")->execute([$id]);
  }

  public static function closeRoute(PDO $pdo, int $id, string $outcome): void {
    $pdo->prepare("
      UPDATE documents
      SET route_outcome=?, route_closed_at=NOW()
      WHERE id=?
    ")->execute([self::normalizeRouteOutcome($outcome), $id]);
  }

  public static function completeRouteLifecycle(PDO $pdo, int $id, string $currentLocation = 'Admin'): void {
    $pdo->prepare("
      UPDATE documents
      SET current_location = ?, routing_status = 'COMPLETED', route_outcome = 'COMPLETED', route_closed_at = NOW(),
          assigned_reviewer_id = NULL, review_acceptance_status = 'NOT_SENT',
          review_accepted_at = NULL, review_declined_at = NULL, review_acceptance_note = NULL
      WHERE id = ?
    ")->execute([
      self::cleanText($currentLocation, 180) ?? 'Admin',
      $id,
    ]);
  }

  public static function moveToStorageArea(PDO $pdo, int $id, string $storageArea, ?int $folderId, ?int $divisionId = null): void {
    $pdo->prepare("
      UPDATE documents
      SET storage_area = ?, folder_id = ?, division_id = ?, status = 'Draft', review_note = NULL,
          approval_locked = 0, submitted_at = NULL, reviewed_at = NULL, reviewed_by = NULL
      WHERE id = ?
    ")->execute([
      self::normalizeStorageArea($storageArea),
      $folderId,
      $divisionId,
      $id,
    ]);
  }

  public static function moveFolderTreeToStorageArea(PDO $pdo, int $ownerId, array $folderIdMap, string $fromStorageArea, string $toStorageArea, ?int $divisionId = null): int {
    if (empty($folderIdMap)) {
      return 0;
    }

    $updated = 0;
    foreach ($folderIdMap as $sourceFolderId => $targetFolderId) {
      $s = $pdo->prepare("
        UPDATE documents
        SET storage_area=?, folder_id=?, division_id=?, status='Draft', review_note=NULL,
            approval_locked=0, submitted_at=NULL, reviewed_at=NULL, reviewed_by=NULL
        WHERE owner_id=? AND folder_id=? AND storage_area=? AND deleted_at IS NULL
      ");
      $s->execute([
        self::normalizeStorageArea($toStorageArea),
        (int)$targetFolderId,
        $divisionId,
        $ownerId,
        (int)$sourceFolderId,
        self::normalizeStorageArea($fromStorageArea),
      ]);
      $updated += $s->rowCount();
    }

    return $updated;
  }

  public static function listActiveForOwnerInStorage(PDO $pdo, int $ownerId, string $storageArea): array {
    $s = $pdo->prepare("
      SELECT d.*
      FROM documents d
      WHERE owner_id=? AND storage_area=? AND deleted_at IS NULL
    ");
    $s->execute([$ownerId, self::normalizeStorageArea($storageArea)]);
    return $s->fetchAll();
  }

  public static function get(PDO $pdo, int $id): ?array {
    $s = $pdo->prepare("
      SELECT d.*, u.name owner_name, u.email owner_email, u.division_id owner_division_id, dv.name division_name,
             reviewer.name assigned_reviewer_name, reviewer.email assigned_reviewer_email,
             rs.name review_section_name
      FROM documents d
      JOIN users u ON u.id=d.owner_id
      LEFT JOIN divisions dv ON dv.id = d.division_id
      LEFT JOIN users reviewer ON reviewer.id = d.assigned_reviewer_id
      LEFT JOIN sections rs ON rs.id = d.review_section_id
      WHERE d.id=? LIMIT 1
    ");
    $s->execute([$id]);
    $r = $s->fetch();
    return $r ?: null;
  }

  public static function findActiveByOwnerAndNameInFolder(PDO $pdo, int $ownerId, string $name, ?int $folderId, ?string $storageArea = null): ?array {
    $params = [$ownerId, $name];
    $storageSql = '';
    if ($storageArea !== null) {
      $storageSql = " AND d.storage_area = ? ";
      $params[] = self::normalizeStorageArea($storageArea);
    }

    if ($folderId === null) {
      $s = $pdo->prepare("
        SELECT d.*, u.name owner_name
        FROM documents d
        JOIN users u ON u.id=d.owner_id
        WHERE d.owner_id=? AND d.folder_id IS NULL AND d.deleted_at IS NULL AND d.name=? $storageSql
        LIMIT 1
      ");
    } else {
      $params = [$ownerId, $folderId, $name];
      if ($storageArea !== null) {
        $params[] = self::normalizeStorageArea($storageArea);
      }
      $s = $pdo->prepare("
        SELECT d.*, u.name owner_name
        FROM documents d
        JOIN users u ON u.id=d.owner_id
        WHERE d.owner_id=? AND d.folder_id=? AND d.deleted_at IS NULL AND d.name=? $storageSql
        LIMIT 1
      ");
    }

    $s->execute($params);
    $r = $s->fetch();
    return $r ?: null;
  }

  public static function listActiveNamesForOwner(PDO $pdo, int $ownerId, ?int $folderId, ?string $storageArea = null): array {
    $params = [$ownerId];
    $storageSql = '';
    if ($storageArea !== null) {
      $storageSql = " AND storage_area = ? ";
      $params[] = self::normalizeStorageArea($storageArea);
    }

    if ($folderId === null) {
      $s = $pdo->prepare("SELECT name FROM documents WHERE owner_id=? AND folder_id IS NULL AND deleted_at IS NULL $storageSql");
    } else {
      $params = [$ownerId, $folderId];
      if ($storageArea !== null) {
        $params[] = self::normalizeStorageArea($storageArea);
      }
      $s = $pdo->prepare("SELECT name FROM documents WHERE owner_id=? AND folder_id=? AND deleted_at IS NULL $storageSql");
    }

    $s->execute($params);
    return array_map(static fn(array $row): string => (string)$row['name'], $s->fetchAll());
  }

  public static function listRoutedToUser(
    PDO $pdo,
    int $userId,
    string $userName,
    int $page = 1,
    int $perPage = 25,
    string $search = '',
    array $routeStates = []
  ): array {
    // Central routed-inbox query for both staff and admin views.
    // Keep this paged and join-based: the older per-row subquery pattern becomes
    // expensive quickly once route history and permissions grow.
    $page = max(1, $page);
    $perPage = (int)$perPage;
    $params = [$userId];
    $params = array_merge($params, self::routedScopeParams($userId, $userName));
    $searchSql = self::buildSearchSql($search, $params);
    $routeStateSql = self::buildRoutedStateFilterSql($routeStates, $params);
    $routeStateExpr = self::routedRouteStateExpr();

    $sql = "
      SELECT
        d.id,
        d.owner_id,
        d.folder_id,
        d.name,
        d.storage_area,
        d.status,
        d.document_code,
        d.document_date,
        d.title,
        d.document_type,
        d.signatory,
        d.routing_status,
        d.route_outcome,
        d.route_closed_at,
        d.priority_level,
        d.current_location,
        d.review_stage,
        d.review_acceptance_status,
        d.assigned_reviewer_id,
        d.created_at,
        owner.name AS owner_name,
        owner.email AS owner_email,
        owner.role AS owner_role,
        owner_division.name AS division_name,
        p_user.permission,
        p_user.shared_by,
        p_user.accepted_at,
        p_user.declined_at,
        p_user.response_note,
        shared_by_user.name AS shared_by_name,
        shared_by_user.email AS shared_by_email,
        active_recipient_user.name AS active_recipient_name,
        active_recipient_user.role AS active_recipient_role,
        active_recipient_division.name AS active_recipient_division_name,
        latest_route.from_location AS last_route_from,
        latest_route.to_location AS last_route_to,
        latest_route.note AS last_route_note,
        latest_route.routed_at AS last_route_at,
        latest_route_user.name AS last_route_by_name,
        {$routeStateExpr} AS route_state
      FROM documents d
      JOIN users owner ON owner.id = d.owner_id
      LEFT JOIN divisions owner_division ON owner_division.id = d.division_id
      LEFT JOIN permissions p_user ON p_user.document_id = d.id AND p_user.user_id = ?
      LEFT JOIN users shared_by_user ON shared_by_user.id = p_user.shared_by
      " . self::latestAcceptedPermissionJoinSql() . "
      LEFT JOIN users active_recipient_user ON active_recipient_user.id = active_recipient.user_id
      LEFT JOIN divisions active_recipient_division ON active_recipient_division.id = active_recipient_user.division_id
      " . self::latestRouteJoinSql() . "
      LEFT JOIN users latest_route_user ON latest_route_user.id = latest_route.routed_by
      WHERE " . self::routedScopeWhereSql() . "
      {$searchSql}
      {$routeStateSql}
      ORDER BY COALESCE(latest_route.routed_at, p_user.accepted_at, p_user.declined_at, d.created_at) DESC, d.id DESC
    ";

    if ($perPage > 0) {
      $offset = ($page - 1) * $perPage;
      $sql .= " LIMIT " . (int)$perPage . " OFFSET " . (int)$offset;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();

    $countParams = [$userId];
    $countParams = array_merge($countParams, self::routedScopeParams($userId, $userName));
    $countSearchSql = self::buildSearchSql($search, $countParams);
    $countRouteStateSql = self::buildRoutedStateFilterSql($routeStates, $countParams);
    $countStmt = $pdo->prepare("
      SELECT COUNT(*)
      FROM documents d
      LEFT JOIN permissions p_user ON p_user.document_id = d.id AND p_user.user_id = ?
      WHERE " . self::routedScopeWhereSql() . "
      {$countSearchSql}
      {$countRouteStateSql}
    ");
    $countStmt->execute($countParams);
    $total = (int)$countStmt->fetchColumn();

    return [$rows, $total];
  }

  public static function summarizeRoutedToUser(PDO $pdo, int $userId, string $userName): array {
    // Summary counts are computed separately from the paged list so dashboards
    // can stay fast without loading every routed document into PHP first.
    $params = [$userId];
    $params = array_merge($params, self::routedScopeParams($userId, $userName));
    $routeStateExpr = self::routedRouteStateExpr();

    $stmt = $pdo->prepare("
      SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN {$routeStateExpr} IN ('ROUTED', 'UNDER_REVIEW') THEN 1 ELSE 0 END) AS incoming_count,
        SUM(CASE WHEN {$routeStateExpr} = 'COMPLETED' THEN 1 ELSE 0 END) AS completed_count,
        SUM(CASE WHEN {$routeStateExpr} = 'UNDER_REVIEW' THEN 1 ELSE 0 END) AS under_review_count,
        SUM(CASE
          WHEN {$routeStateExpr} IN ('ROUTED', 'UNDER_REVIEW')
            AND UPPER(COALESCE(d.priority_level, 'MODERATE')) IN ('HIGH', 'RUSH', 'URGENT')
          THEN 1 ELSE 0 END
        ) AS priority_count
      FROM documents d
      LEFT JOIN permissions p_user ON p_user.document_id = d.id AND p_user.user_id = ?
      WHERE " . self::routedScopeWhereSql() . "
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
      'total' => (int)($row['total_count'] ?? 0),
      'incoming' => (int)($row['incoming_count'] ?? 0),
      'completed' => (int)($row['completed_count'] ?? 0),
      'under_review' => (int)($row['under_review_count'] ?? 0),
      'priority' => (int)($row['priority_count'] ?? 0),
    ];
  }

  public static function summarizeOwnerQueue(PDO $pdo, int $ownerId, string $search = ''): array {
    $params = [$ownerId];
    $searchSql = self::buildSearchSql($search, $params);
    $stmt = $pdo->prepare("
      SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN UPPER(COALESCE(d.routing_status, 'NOT_ROUTED')) IN ('NOT_ROUTED', 'AVAILABLE') THEN 1 ELSE 0 END) AS waiting_count,
        SUM(CASE WHEN UPPER(COALESCE(d.routing_status, 'NOT_ROUTED')) IN ('SHARE_DECLINED', 'REVIEW_ASSIGNMENT_DECLINED', 'REJECTED') THEN 1 ELSE 0 END) AS returned_count,
        SUM(CASE WHEN UPPER(COALESCE(d.routing_status, 'NOT_ROUTED')) IN ('APPROVED', 'COMPLETED') THEN 1 ELSE 0 END) AS approved_count,
        SUM(CASE
          WHEN UPPER(COALESCE(d.routing_status, 'NOT_ROUTED')) NOT IN ('NOT_ROUTED', 'AVAILABLE', 'SHARE_DECLINED', 'REVIEW_ASSIGNMENT_DECLINED', 'REJECTED', 'APPROVED', 'COMPLETED')
          THEN 1 ELSE 0 END
        ) AS routed_count
      FROM documents d
      WHERE d.storage_area = 'OFFICIAL' AND d.deleted_at IS NULL AND d.owner_id = ?
      {$searchSql}
    ");
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];

    return [
      'total' => (int)($row['total_count'] ?? 0),
      'waiting' => (int)($row['waiting_count'] ?? 0),
      'returned' => (int)($row['returned_count'] ?? 0),
      'approved' => (int)($row['approved_count'] ?? 0),
      'routed' => (int)($row['routed_count'] ?? 0),
    ];
  }

  public static function searchActiveForOwnerInStorage(PDO $pdo, int $ownerId, string $storageArea, string $search, int $limit = 10): array {
    $limit = max(1, $limit);
    $params = [$ownerId, self::normalizeStorageArea($storageArea)];
    $searchSql = self::buildSearchSql($search, $params);
    $stmt = $pdo->prepare("
      SELECT d.*
      FROM documents d
      WHERE d.owner_id = ? AND d.storage_area = ? AND d.deleted_at IS NULL
      {$searchSql}
      ORDER BY COALESCE(d.document_date, DATE(d.created_at)) DESC, d.id DESC
      LIMIT {$limit}
    ");
    $stmt->execute($params);
    return $stmt->fetchAll();
  }

  public static function listMy(PDO $pdo, int $userId, string $search, ?int $folderId, int $page, int $per, bool $trash=false, array $filters = []): array {
    $where = $trash ? "d.deleted_at IS NOT NULL" : "d.deleted_at IS NULL";
    $params = [$userId];
    $searchSql = self::buildSearchSql($search, $params);

    $folderSql = "";
    if ($folderId !== null && $folderId > 0) {
      $folderSql = " AND d.folder_id = ? ";
      $params[] = $folderId;
    }

    $metaSql = self::buildFilterSql($filters, $params);

    $off = ($page-1)*$per;
    $orderBy = self::sortOrderSql($filters, 'd.id DESC');
    $activityExpr = "COALESCE((SELECT MAX(dv.created_at) FROM document_versions dv WHERE dv.document_id=d.id), d.reviewed_at, d.submitted_at, d.created_at)";
    $sql = "
      SELECT d.*, u.name owner_name,
             (SELECT MAX(version_number) FROM document_versions dv WHERE dv.document_id=d.id) latest_version,
             (SELECT dv.file_path FROM document_versions dv WHERE dv.document_id=d.id ORDER BY dv.version_number DESC, dv.id DESC LIMIT 1) latest_file_path,
             (SELECT ru.name FROM document_routes dr JOIN users ru ON ru.id=dr.routed_by WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_touched_by_name,
             $activityExpr AS last_activity_at
      FROM documents d
      JOIN users u ON u.id=d.owner_id
      WHERE d.owner_id=? AND $where $searchSql $folderSql $metaSql
      ORDER BY $orderBy
      LIMIT $per OFFSET $off
    ";
    $s = $pdo->prepare($sql);
    $s->execute($params);
    $rows = $s->fetchAll();

    $sqlc = "SELECT COUNT(*) FROM documents d WHERE d.owner_id=? AND $where $searchSql $folderSql $metaSql";
    $sc = $pdo->prepare($sqlc);
    $sc->execute($params);
    $total = (int)$sc->fetchColumn();

    return [$rows, $total];
  }

  public static function listTrashedForOwner(PDO $pdo, int $userId, string $search, array $filters = []): array {
    $params = [$userId];
    $searchSql = self::buildSearchSql($search, $params);
    $metaSql = self::buildFilterSql($filters, $params);
    $activityExpr = "COALESCE((SELECT MAX(dv.created_at) FROM document_versions dv WHERE dv.document_id=d.id), d.deleted_at, d.reviewed_at, d.submitted_at, d.created_at)";

    $s = $pdo->prepare("
      SELECT d.*, u.name owner_name, f.name AS folder_name,
             (SELECT MAX(version_number) FROM document_versions dv WHERE dv.document_id=d.id) latest_version,
             (SELECT dv.file_path FROM document_versions dv WHERE dv.document_id=d.id ORDER BY dv.version_number DESC, dv.id DESC LIMIT 1) latest_file_path,
             (SELECT ru.name FROM document_routes dr JOIN users ru ON ru.id=dr.routed_by WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_touched_by_name,
             $activityExpr AS last_activity_at
      FROM documents d
      JOIN users u ON u.id=d.owner_id
      LEFT JOIN folders f ON f.id=d.folder_id
      WHERE d.owner_id=? AND d.deleted_at IS NOT NULL $searchSql $metaSql
      ORDER BY " . self::sortOrderSql($filters, 'd.deleted_at DESC, d.id DESC') . "
    ");
    $s->execute($params);
    return $s->fetchAll();
  }

  public static function listShared(PDO $pdo, int $userId, string $search, int $page, int $per, array $filters = []): array {
    $off = ($page-1)*$per;
    $listParams = [$userId, $userId, $userId, $userId];
    $searchSql = self::buildSearchSql($search, $listParams);
    $metaSql = self::buildFilterSql($filters, $listParams);
    $orderBy = self::sortOrderSql($filters, 'd.id DESC');
    $activityExpr = "COALESCE((SELECT MAX(dv.created_at) FROM document_versions dv WHERE dv.document_id=d.id), d.reviewed_at, d.submitted_at, d.created_at)";

    $s = $pdo->prepare("
      SELECT d.*, u.name owner_name,
             p.id AS permission_id,
             p.user_id AS permission_user_id,
             p.permission,
             p.shared_by,
             p.accepted_at,
             p.declined_at,
             p.response_note,
             CASE
               WHEN d.owner_id = ? THEN 'outgoing'
               ELSE 'incoming'
             END AS shared_scope,
             (SELECT MAX(version_number) FROM document_versions dv WHERE dv.document_id=d.id) latest_version,
             (SELECT dv.file_path FROM document_versions dv WHERE dv.document_id=d.id ORDER BY dv.version_number DESC, dv.id DESC LIMIT 1) latest_file_path,
             (SELECT ru.name FROM document_routes dr JOIN users ru ON ru.id=dr.routed_by WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_touched_by_name,
             (SELECT dr.from_location FROM document_routes dr WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_route_from,
             (SELECT dr.to_location FROM document_routes dr WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_route_to,
             (SELECT dr.note FROM document_routes dr WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_route_note,
             (SELECT dr.routed_at FROM document_routes dr WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_route_at,
             (SELECT ru.name FROM document_routes dr JOIN users ru ON ru.id=dr.routed_by WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_route_by_name,
             $activityExpr AS last_activity_at
      FROM documents d
      JOIN users u ON u.id=d.owner_id
      LEFT JOIN permissions p ON p.document_id=d.id AND p.user_id=?
      WHERE d.deleted_at IS NULL
        AND (
          p.user_id=?
          OR (
            d.owner_id=?
            AND EXISTS (SELECT 1 FROM permissions px WHERE px.document_id=d.id)
          )
        )
        $searchSql $metaSql
      ORDER BY $orderBy
      LIMIT $per OFFSET $off
    ");
    $s->execute($listParams);
    $rows = $s->fetchAll();

    $countParams = [$userId, $userId, $userId];
    $countSearchSql = self::buildSearchSql($search, $countParams);
    $countMetaSql = self::buildFilterSql($filters, $countParams);
    $sc = $pdo->prepare("
      SELECT COUNT(*)
      FROM documents d
      LEFT JOIN permissions p ON p.document_id=d.id AND p.user_id=?
      WHERE d.deleted_at IS NULL
        AND (
          p.user_id=?
          OR (
            d.owner_id=?
            AND EXISTS (SELECT 1 FROM permissions px WHERE px.document_id=d.id)
          )
        )
        $countSearchSql $countMetaSql
    ");
    $sc->execute($countParams);
    $total = (int)$sc->fetchColumn();

    return [$rows, $total];
  }

  public static function listForDivisionChief(PDO $pdo, int $divisionId, int $reviewerUserId, array $filters = []): array {
    $where = "
      d.division_id=? AND d.storage_area='OFFICIAL' AND d.deleted_at IS NULL
      AND (
        d.assigned_reviewer_id=?
        OR d.reviewed_by=?
        OR (d.status IN ('Approved', 'Rejected') AND d.reviewed_by=?)
      )
    ";
    $params = [$divisionId, $reviewerUserId, $reviewerUserId, $reviewerUserId];
    $status = trim((string)($filters['status'] ?? ''));
    if ($status !== '') {
      $where .= " AND d.status = ? ";
      $params[] = $status;
    }
    $employeeId = (int)($filters['employee_id'] ?? 0);
    if ($employeeId > 0) {
      $where .= " AND d.owner_id = ? ";
      $params[] = $employeeId;
    }
    $search = trim((string)($filters['search'] ?? ''));
    $where .= self::buildSearchSql($search, $params);

    $s = $pdo->prepare("
      SELECT d.*, u.name owner_name, u.email owner_email,
             (SELECT MAX(version_number) FROM document_versions dv WHERE dv.document_id=d.id) latest_version,
             (SELECT dv.file_path FROM document_versions dv WHERE dv.document_id=d.id ORDER BY dv.version_number DESC, dv.id DESC LIMIT 1) latest_file_path,
             (SELECT ru.name FROM document_routes dr JOIN users ru ON ru.id=dr.routed_by WHERE dr.document_id=d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) last_touched_by_name,
             COALESCE((SELECT MAX(dv.created_at) FROM document_versions dv WHERE dv.document_id=d.id), d.reviewed_at, d.submitted_at, d.created_at) AS last_activity_at
      FROM documents d
      JOIN users u ON u.id = d.owner_id
      WHERE $where
      ORDER BY
        CASE d.status
          WHEN 'To be reviewed' THEN 0
          WHEN 'Rejected' THEN 1
          WHEN 'Approved' THEN 2
          ELSE 3
        END,
        COALESCE(d.submitted_at, d.created_at) DESC
    ");
    $s->execute($params);
    return $s->fetchAll();
  }

  public static function softDelete(PDO $pdo, int $id, ?int $deletedBy = null, ?string $reason = null): void {
    $pdo->prepare("UPDATE documents SET deleted_at=NOW(), deleted_by=?, deleted_reason=? WHERE id=?")
      ->execute([$deletedBy, $reason, $id]);
  }

  public static function softDeleteByFolder(PDO $pdo, int $ownerId, int $folderId, ?int $deletedBy = null, ?string $reason = null, ?string $storageArea = null): int {
    $params = [$deletedBy, $reason, $ownerId, $folderId];
    $areaSql = '';
    if ($storageArea !== null) {
      $areaSql = " AND storage_area = ? ";
      $params[] = self::normalizeStorageArea($storageArea);
    }
    $s = $pdo->prepare("UPDATE documents SET deleted_at=NOW(), deleted_by=?, deleted_reason=? WHERE owner_id=? AND folder_id=? AND deleted_at IS NULL $areaSql");
    $s->execute($params);
    return $s->rowCount();
  }

  public static function trashedIdsForOwner(PDO $pdo, int $ownerId): array {
    $s = $pdo->prepare("SELECT id FROM documents WHERE owner_id=? AND deleted_at IS NOT NULL");
    $s->execute([$ownerId]);
    return array_map(static fn(array $row): int => (int)$row['id'], $s->fetchAll());
  }

  public static function trashedIdsEligibleForPurge(PDO $pdo, int $ownerId, int $retentionDays): array {
    if ($retentionDays <= 0) {
      return self::trashedIdsForOwner($pdo, $ownerId);
    }
    $s = $pdo->prepare("
      SELECT id
      FROM documents
      WHERE owner_id=? AND deleted_at IS NOT NULL
        AND deleted_at <= (NOW() - INTERVAL ? DAY)
    ");
    $s->execute([$ownerId, $retentionDays]);
    return array_map(static fn(array $row): int => (int)$row['id'], $s->fetchAll());
  }

  public static function idsByFolderIds(PDO $pdo, array $folderIds, ?int $ownerId = null): array {
    $ids = array_values(array_unique(array_map('intval', $folderIds)));
    if (empty($ids)) {
      return [];
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $ownerSql = '';
    if ($ownerId !== null) {
      $ownerSql = ' AND owner_id=?';
      $params[] = $ownerId;
    }

    $s = $pdo->prepare("SELECT id FROM documents WHERE folder_id IN ($ph) $ownerSql");
    $s->execute($params);
    return array_map(static fn(array $row): int => (int)$row['id'], $s->fetchAll());
  }

  public static function hardDeleteByIds(PDO $pdo, array $docIds): int {
    if (empty($docIds)) {
      return 0;
    }
    $ids = array_values(array_map('intval', $docIds));
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $s = $pdo->prepare("DELETE FROM documents WHERE id IN ($ph)");
    $s->execute($ids);
    return $s->rowCount();
  }

  public static function restore(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE documents SET deleted_at=NULL, deleted_by=NULL, deleted_reason=NULL WHERE id=?")->execute([$id]);
  }

  public static function restoreByFolderIds(PDO $pdo, array $folderIds, ?int $ownerId = null): int {
    $ids = array_values(array_unique(array_map('intval', $folderIds)));
    if (empty($ids)) {
      return 0;
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = $ids;
    $ownerSql = '';
    if ($ownerId !== null) {
      $ownerSql = ' AND owner_id=?';
      $params[] = $ownerId;
    }

    $s = $pdo->prepare("
      UPDATE documents
      SET deleted_at=NULL, deleted_by=NULL, deleted_reason=NULL
      WHERE folder_id IN ($ph) AND deleted_at IS NOT NULL $ownerSql
    ");
    $s->execute($params);
    return $s->rowCount();
  }

  public static function checkout(PDO $pdo, int $id, int $userId): void {
    $pdo->prepare("UPDATE documents SET checked_out_by=?, checked_out_at=NOW() WHERE id=?")
      ->execute([$userId, $id]);
  }

  public static function checkin(PDO $pdo, int $id): void {
    $pdo->prepare("UPDATE documents SET checked_out_by=NULL, checked_out_at=NULL WHERE id=?")
      ->execute([$id]);
  }

  public static function submitForReview(PDO $pdo, int $id, int $divisionId, int $sectionId, int $reviewerId): void {
    $pdo->prepare("
      UPDATE documents
      SET storage_area='OFFICIAL', division_id=?, status='To be reviewed', submitted_at=NOW(),
          approval_locked=1, checked_out_by=NULL, checked_out_at=NULL, review_note=NULL,
          review_acceptance_status='PENDING', review_accepted_at=NULL, review_declined_at=NULL,
          review_acceptance_note=NULL, review_stage='SECTION_REVIEW', review_section_id=?,
          assigned_reviewer_id=?, review_escalated_at=NULL
      WHERE id=?
    ")->execute([$divisionId, $sectionId, $reviewerId, $id]);
  }

  public static function acceptReviewAssignment(PDO $pdo, int $id): void {
    $pdo->prepare("
      UPDATE documents
      SET review_acceptance_status='ACCEPTED', review_accepted_at=NOW(), review_declined_at=NULL, review_acceptance_note=NULL
      WHERE id=?
    ")->execute([$id]);
  }

  public static function declineReviewAssignment(PDO $pdo, int $id, ?string $note): void {
    $pdo->prepare("
      UPDATE documents
      SET review_acceptance_status='DECLINED', review_accepted_at=NULL, review_declined_at=NOW(), review_acceptance_note=?
      WHERE id=?
    ")->execute([self::cleanText($note, 1000), $id]);
  }

  public static function escalateReview(PDO $pdo, int $id, int $divisionReviewerId, ?string $note): void {
    $pdo->prepare("
      UPDATE documents
      SET review_stage='DIVISION_REVIEW', assigned_reviewer_id=?, review_acceptance_status='PENDING',
          review_accepted_at=NULL, review_declined_at=NULL, review_acceptance_note=?,
          review_escalated_at=NOW()
      WHERE id=?
    ")->execute([$divisionReviewerId, self::cleanText($note, 1000), $id]);
  }

  public static function finalizeReview(PDO $pdo, int $id, string $decision, ?string $note, int $reviewerId): void {
    $status = strtoupper($decision) === 'APPROVED' ? 'Approved' : 'Rejected';
    $locked = $status === 'Approved' ? 1 : 0;
    $documentType = $status === 'Approved' ? 'OUTGOING' : 'INCOMING';
    $pdo->prepare("
      UPDATE documents
      SET status=?, review_note=?, reviewed_by=?, reviewed_at=NOW(), approval_locked=?,
          checked_out_by=NULL, checked_out_at=NULL, review_acceptance_status='ACCEPTED',
          document_type=?, review_stage='FINAL', assigned_reviewer_id=NULL
      WHERE id=?
    ")->execute([$status, $note, $reviewerId, $locked, $documentType, $id]);
  }

  public static function countAll(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM documents")->fetchColumn();
  }

  public static function countActive(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NULL")->fetchColumn();
  }

  public static function countTrashed(PDO $pdo): int {
    return (int)$pdo->query("SELECT COUNT(*) FROM documents WHERE deleted_at IS NOT NULL")->fetchColumn();
  }

  public static function listInventoryForOwner(PDO $pdo, int $ownerId): array {
    $s = $pdo->prepare("
      SELECT
        d.id,
        d.name,
        d.owner_id,
        d.folder_id,
        d.storage_area,
        d.status,
        d.document_code,
        d.title,
        d.document_type,
        d.routing_status,
        d.priority_level,
        d.current_location,
        d.deleted_at,
        d.created_at,
        d.submitted_at,
        d.reviewed_at,
        f.name AS folder_name,
        (SELECT ru.name FROM document_routes dr JOIN users ru ON ru.id = dr.routed_by WHERE dr.document_id = d.id ORDER BY dr.routed_at DESC, dr.id DESC LIMIT 1) AS last_touched_by_name,
        COALESCE((SELECT MAX(dv.created_at) FROM document_versions dv WHERE dv.document_id = d.id), d.reviewed_at, d.submitted_at, d.created_at) AS last_activity_at
      FROM documents d
      LEFT JOIN folders f ON f.id = d.folder_id
      WHERE d.owner_id = ?
      ORDER BY
        d.storage_area DESC,
        CASE WHEN f.name IS NULL THEN 1 ELSE 0 END,
        f.name ASC,
        d.name ASC
    ");
    $s->execute([$ownerId]);
    return $s->fetchAll();
  }

  public static function sharedMembersPreview(PDO $pdo, array $documentIds, int $limit = 3): array {
    $ids = array_values(array_filter(array_map('intval', $documentIds), static fn(int $v): bool => $v > 0));
    if (empty($ids)) {
      return [];
    }
    $ph = implode(',', array_fill(0, count($ids), '?'));

    $totalsStmt = $pdo->prepare("
      SELECT document_id, COUNT(*) AS total
      FROM permissions
      WHERE document_id IN ($ph)
      GROUP BY document_id
    ");
    $totalsStmt->execute($ids);
    $totals = [];
    foreach ($totalsStmt->fetchAll() as $row) {
      $totals[(int)$row['document_id']] = (int)$row['total'];
    }

    $membersStmt = $pdo->prepare("
      SELECT p.document_id, u.id AS user_id, u.name, u.email, u.avatar_photo, u.avatar_preset
      FROM permissions p
      JOIN users u ON u.id = p.user_id
      WHERE p.document_id IN ($ph)
      ORDER BY p.document_id ASC, p.id ASC
    ");
    $membersStmt->execute($ids);
    $map = [];
    foreach ($membersStmt->fetchAll() as $row) {
      $docId = (int)$row['document_id'];
      if (!isset($map[$docId])) {
        $map[$docId] = [
          'total' => (int)($totals[$docId] ?? 0),
          'items' => [],
        ];
      }
      if (count($map[$docId]['items']) >= $limit) {
        continue;
      }
      $map[$docId]['items'][] = $row;
    }
    return $map;
  }

  private static function buildFilterSql(array $filters, array &$params): string {
    $metaSql = "";
    $status = trim((string)($filters['status'] ?? ''));
    $category = trim((string)($filters['category'] ?? ''));
    $tags = trim((string)($filters['tags'] ?? ''));
    $dateFrom = trim((string)($filters['date_from'] ?? ''));
    $dateTo = trim((string)($filters['date_to'] ?? ''));
    $storageArea = trim((string)($filters['storage_area'] ?? ''));
    $documentCode = trim((string)($filters['document_code'] ?? ''));
    $documentType = trim((string)($filters['document_type'] ?? ''));
    $routingStatus = trim((string)($filters['routing_status'] ?? ''));
    $routeState = strtoupper(trim((string)($filters['route_state'] ?? '')));
    $priorityLevel = trim((string)($filters['priority_level'] ?? ''));
    $currentLocation = trim((string)($filters['current_location'] ?? ''));

    if ($status !== '') {
      $metaSql .= " AND d.status = ? ";
      $params[] = $status;
    }
    if ($category !== '') {
      $metaSql .= " AND d.category LIKE ? ";
      $params[] = "%" . $category . "%";
    }
    if ($tags !== '') {
      $metaSql .= " AND d.tags LIKE ? ";
      $params[] = "%" . $tags . "%";
    }
    if ($dateFrom !== '') {
      $metaSql .= " AND DATE(d.created_at) >= ? ";
      $params[] = $dateFrom;
    }
    if ($dateTo !== '') {
      $metaSql .= " AND DATE(d.created_at) <= ? ";
      $params[] = $dateTo;
    }
    if ($storageArea !== '') {
      $metaSql .= " AND d.storage_area = ? ";
      $params[] = self::normalizeStorageArea($storageArea);
    }
    if ($documentCode !== '') {
      $metaSql .= " AND d.document_code LIKE ? ";
      $params[] = "%" . $documentCode . "%";
    }
    if ($documentType !== '') {
      $metaSql .= " AND d.document_type = ? ";
      $params[] = self::normalizeDocumentType($documentType);
    }
    if ($routingStatus !== '') {
      $metaSql .= " AND d.routing_status = ? ";
      $params[] = self::normalizeRoutingStatus($routingStatus);
    }
    if ($routeState === 'ROUTED') {
      $metaSql .= " AND d.routing_status <> ? ";
      $params[] = 'AVAILABLE';
    } elseif ($routeState === 'NOT_ROUTED') {
      $metaSql .= " AND d.routing_status = ? ";
      $params[] = 'AVAILABLE';
    }
    if ($priorityLevel !== '') {
      $metaSql .= " AND d.priority_level = ? ";
      $params[] = self::normalizePriorityLevel($priorityLevel);
    }
    if ($currentLocation !== '') {
      $metaSql .= " AND d.current_location LIKE ? ";
      $params[] = "%" . $currentLocation . "%";
    }

    return $metaSql;
  }

  private static function buildSearchSql(string $search, array &$params): string {
    $term = trim($search);
    if ($term === '') {
      return '';
    }

    $like = '%' . $term . '%';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;

    $fullTextSql = '';
    if (self::documentFullTextAvailable()) {
      // Prefer FULLTEXT when available, but keep LIKE fallbacks so local/test
      // environments still work even if the fulltext index has not been created yet.
      $booleanQuery = self::buildBooleanSearchQuery($term);
      if ($booleanQuery !== '') {
        array_pop($params);
        array_pop($params);
        array_pop($params);
        array_pop($params);
        array_pop($params);
        array_pop($params);
        $params[] = $booleanQuery;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $fullTextSql = "MATCH(d.name, d.title, d.document_code, d.current_location, d.signatory, d.tags) AGAINST (? IN BOOLEAN MODE) OR ";
      }
    }

    return "
      AND (
        {$fullTextSql}
        d.name LIKE ?
        OR COALESCE(d.title, '') LIKE ?
        OR COALESCE(d.document_code, '') LIKE ?
        OR COALESCE(d.current_location, '') LIKE ?
        OR COALESCE(d.signatory, '') LIKE ?
        OR COALESCE(d.tags, '') LIKE ?
      )
    ";
  }

  private static function buildBooleanSearchQuery(string $search): string {
    $parts = preg_split('/\s+/', trim($search)) ?: [];
    $tokens = [];

    foreach ($parts as $part) {
      $clean = preg_replace('/[^\pL\pN_-]+/u', '', $part);
      if ($clean === null) {
        continue;
      }
      $clean = trim($clean);
      if (mb_strlen($clean) < 2) {
        continue;
      }
      $tokens[] = '+' . $clean . (mb_strlen($clean) >= 3 ? '*' : '');
    }

    return implode(' ', array_slice(array_values(array_unique($tokens)), 0, 8));
  }

  private static function documentFullTextAvailable(): bool {
    if (function_exists('cddfts_document_fulltext_enabled') && !cddfts_document_fulltext_enabled()) {
      return false;
    }

    static $available = null;
    if ($available !== null) {
      return $available;
    }

    $pdo = $GLOBALS['pdo'] ?? null;
    if (!$pdo instanceof PDO) {
      $available = false;
      return $available;
    }

    try {
      $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'documents'
          AND INDEX_NAME = 'ft_documents_search'
      ");
      $stmt->execute();
      $available = (int)$stmt->fetchColumn() > 0;
    } catch (Throwable $_e) {
      $available = false;
    }

    return $available;
  }

  private static function routedScopeParams(int $userId, string $userName): array {
    $sharedLocation = 'Shared with ' . trim($userName);
    return [$userId, $sharedLocation, '%' . trim($userName) . '%'];
  }

  private static function routedScopeWhereSql(): string {
    // A routed document is visible to a user if they have an explicit permission,
    // are the assigned reviewer, or were named in route history/location notes.
    // Keep this scope definition shared so staff/admin routed views do not drift.
    return "
      d.deleted_at IS NULL
      AND (
        p_user.user_id IS NOT NULL
        OR d.assigned_reviewer_id = ?
        OR EXISTS (
          SELECT 1
          FROM document_routes route_match
          WHERE route_match.document_id = d.id
            AND (
              route_match.to_location = ?
              OR route_match.note LIKE ?
            )
        )
      )
    ";
  }

  private static function routedRouteStateExpr(): string {
    return "
      CASE
        WHEN UPPER(COALESCE(d.route_outcome, 'ACTIVE')) = 'COMPLETED'
          OR UPPER(COALESCE(d.routing_status, 'AVAILABLE')) = 'COMPLETED'
        THEN 'COMPLETED'
        WHEN UPPER(COALESCE(d.routing_status, 'AVAILABLE')) IN ('IN_REVIEW', 'PENDING_REVIEW_ACCEPTANCE')
          OR UPPER(COALESCE(d.review_acceptance_status, 'NOT_SENT')) = 'ACCEPTED'
        THEN 'UNDER_REVIEW'
        WHEN UPPER(COALESCE(d.routing_status, 'AVAILABLE')) IN ('REJECTED', 'SHARE_DECLINED', 'REVIEW_ASSIGNMENT_DECLINED')
        THEN 'RETURNED'
        ELSE 'ROUTED'
      END
    ";
  }

  private static function buildRoutedStateFilterSql(array $routeStates, array &$params): string {
    $normalized = array_values(array_filter(array_map(
      static fn(mixed $state): string => strtoupper(trim((string)$state)),
      $routeStates
    )));
    if (empty($normalized)) {
      return '';
    }

    $placeholders = implode(', ', array_fill(0, count($normalized), '?'));
    foreach ($normalized as $state) {
      $params[] = $state;
    }

    return " AND " . self::routedRouteStateExpr() . " IN ({$placeholders}) ";
  }

  private static function latestRouteJoinSql(): string {
    // Resolve the latest route row once and join it, instead of asking for the
    // latest route fields through separate subqueries per selected column.
    return "
      LEFT JOIN (
        SELECT dr.document_id, dr.from_location, dr.to_location, dr.note, dr.routed_at, dr.routed_by
        FROM document_routes dr
        INNER JOIN (
          SELECT document_id, MAX(id) AS latest_id
          FROM document_routes
          GROUP BY document_id
        ) latest_route_index ON latest_route_index.latest_id = dr.id
      ) latest_route ON latest_route.document_id = d.id
    ";
  }

  private static function latestAcceptedPermissionJoinSql(): string {
    // This join gives us the current accepted recipient without loading the whole
    // permission history into PHP.
    return "
      LEFT JOIN (
        SELECT p.document_id, p.user_id, p.accepted_at
        FROM permissions p
        INNER JOIN (
          SELECT document_id, accepted_at, MAX(id) AS latest_id
          FROM permissions
          WHERE accepted_at IS NOT NULL
          GROUP BY document_id, accepted_at
        ) accepted_perm_index ON accepted_perm_index.latest_id = p.id
        INNER JOIN (
          SELECT document_id, MAX(accepted_at) AS latest_accepted_at
          FROM permissions
          WHERE accepted_at IS NOT NULL
          GROUP BY document_id
        ) accepted_perm_time
          ON accepted_perm_time.document_id = p.document_id
         AND accepted_perm_time.latest_accepted_at = p.accepted_at
      ) active_recipient ON active_recipient.document_id = d.id
    ";
  }

  public static function normalizeDocumentType(string $value): string {
    return strtoupper(trim($value)) === 'OUTGOING' ? 'OUTGOING' : 'INCOMING';
  }

  public static function normalizeRoutingStatus(string $value): string {
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

  public static function normalizeRouteOutcome(string $value): string {
    return match (strtoupper(trim($value))) {
      'APPROVED' => 'APPROVED',
      'RETURNED' => 'RETURNED',
      'REJECTED' => 'REJECTED',
      'COMPLETED' => 'COMPLETED',
      'ARCHIVED' => 'ARCHIVED',
      default => 'ACTIVE',
    };
  }

  public static function normalizePriorityLevel(string $value): string {
    return match (strtoupper(trim($value))) {
      'LOW' => 'LOW',
      'MODERATE', 'NORMAL' => 'MODERATE',
      'HIGH' => 'HIGH',
      'RUSH', 'URGENT' => 'RUSH',
      default => 'MODERATE',
    };
  }

  private static function cleanText(mixed $value, int $limit): ?string {
    $clean = trim((string)$value);
    if ($clean === '') {
      return null;
    }
    return mb_substr($clean, 0, $limit);
  }

  private static function cleanDate(mixed $value): ?string {
    $clean = trim((string)$value);
    if ($clean === '') {
      return null;
    }
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $clean) ? $clean : null;
  }
}
