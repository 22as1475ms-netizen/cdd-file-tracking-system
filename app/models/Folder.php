<?php
class Folder {
  public const PATH_SEPARATOR = ' / ';

  public static function normalizeStorageArea(string $storageArea): string {
    return strtoupper(trim($storageArea)) === 'OFFICIAL' ? 'OFFICIAL' : 'PRIVATE';
  }

  public static function listForUser(PDO $pdo, int $userId, string $storageArea = 'PRIVATE'): array {
    $s = $pdo->prepare("SELECT * FROM folders WHERE owner_id=? AND storage_area=? AND deleted_at IS NULL ORDER BY name");
    $s->execute([$userId, self::normalizeStorageArea($storageArea)]);
    return $s->fetchAll();
  }

  public static function searchForUser(PDO $pdo, int $userId, string $search, string $storageArea = 'PRIVATE', int $limit = 8): array {
    $term = trim($search);
    if ($term === '') {
      return [];
    }

    $limit = max(1, $limit);
    $like = '%' . $term . '%';
    $prefixLike = $term . '%';
    $s = $pdo->prepare("
      SELECT *
      FROM folders
      WHERE owner_id = ? AND storage_area = ? AND deleted_at IS NULL AND name LIKE ?
      ORDER BY
        CASE WHEN name LIKE ? THEN 0 ELSE 1 END,
        LENGTH(name) ASC,
        name ASC
      LIMIT {$limit}
    ");
    $s->execute([$userId, self::normalizeStorageArea($storageArea), $like, $prefixLike]);
    return $s->fetchAll();
  }

  public static function findByNameForUser(PDO $pdo, int $userId, string $name, string $storageArea = 'PRIVATE'): ?array {
    $s = $pdo->prepare("SELECT * FROM folders WHERE owner_id=? AND name=? AND storage_area=? AND deleted_at IS NULL LIMIT 1");
    $s->execute([$userId, $name, self::normalizeStorageArea($storageArea)]);
    $row = $s->fetch();
    return $row ?: null;
  }

  public static function getForUser(PDO $pdo, int $folderId, int $userId, ?string $storageArea = null): ?array {
    $params = [$folderId, $userId];
    $storageSql = '';
    if ($storageArea !== null) {
      $storageSql = ' AND storage_area=?';
      $params[] = self::normalizeStorageArea($storageArea);
    }
    $s = $pdo->prepare("SELECT * FROM folders WHERE id=? AND owner_id=? AND deleted_at IS NULL $storageSql LIMIT 1");
    $s->execute($params);
    $row = $s->fetch();
    return $row ?: null;
  }

  public static function getTrashedForUser(PDO $pdo, int $folderId, int $userId): ?array {
    $s = $pdo->prepare("SELECT * FROM folders WHERE id=? AND owner_id=? AND deleted_at IS NOT NULL LIMIT 1");
    $s->execute([$folderId, $userId]);
    $row = $s->fetch();
    return $row ?: null;
  }

  public static function create(PDO $pdo, int $userId, string $name, string $storageArea = 'PRIVATE'): void {
    $normalized = self::normalizePath($name);
    $pdo->prepare("INSERT INTO folders(name, owner_id, storage_area) VALUES(?,?,?)")->execute([$normalized, $userId, self::normalizeStorageArea($storageArea)]);
  }

  public static function firstOrCreateForUser(PDO $pdo, int $userId, string $name, string $storageArea = 'PRIVATE'): int {
    $normalized = self::normalizePath($name);
    $existing = self::findByNameForUser($pdo, $userId, $normalized, $storageArea);
    if ($existing) {
      return (int)$existing['id'];
    }
    self::create($pdo, $userId, $normalized, $storageArea);
    return (int)$pdo->lastInsertId();
  }

  public static function renameForUser(PDO $pdo, int $folderId, int $userId, string $name, string $storageArea = 'PRIVATE'): bool {
    $s = $pdo->prepare("UPDATE folders SET name=? WHERE id=? AND owner_id=? AND storage_area=?");
    $s->execute([self::normalizePath($name), $folderId, $userId, self::normalizeStorageArea($storageArea)]);
    return $s->rowCount() > 0;
  }

  public static function deleteForUser(PDO $pdo, int $folderId, int $userId): bool {
    $s = $pdo->prepare("DELETE FROM folders WHERE id=? AND owner_id=?");
    $s->execute([$folderId, $userId]);
    return $s->rowCount() > 0;
  }

  public static function listTreeForUser(PDO $pdo, int $userId, string $path, string $storageArea = 'PRIVATE'): array {
    $normalized = self::normalizePath($path);
    $like = $normalized . self::PATH_SEPARATOR . '%';
    $s = $pdo->prepare("SELECT * FROM folders WHERE owner_id=? AND storage_area=? AND deleted_at IS NULL AND (name=? OR name LIKE ?) ORDER BY LENGTH(name) ASC, name ASC");
    $s->execute([$userId, self::normalizeStorageArea($storageArea), $normalized, $like]);
    return $s->fetchAll();
  }

  public static function listTreeIncludingDeletedForUser(PDO $pdo, int $userId, string $path): array {
    $normalized = self::normalizePath($path);
    $like = $normalized . self::PATH_SEPARATOR . '%';
    $s = $pdo->prepare("SELECT * FROM folders WHERE owner_id=? AND (name=? OR name LIKE ?) ORDER BY LENGTH(name) ASC, name ASC");
    $s->execute([$userId, $normalized, $like]);
    return $s->fetchAll();
  }

  public static function listTrashedForUser(PDO $pdo, int $userId): array {
    $s = $pdo->prepare("SELECT * FROM folders WHERE owner_id=? AND deleted_at IS NOT NULL ORDER BY deleted_at DESC, id DESC");
    $s->execute([$userId]);
    return $s->fetchAll();
  }

  public static function softDeleteTreeForUser(PDO $pdo, int $userId, string $path, ?int $deletedBy = null, string $storageArea = 'PRIVATE'): int {
    $normalized = self::normalizePath($path);
    $like = $normalized . self::PATH_SEPARATOR . '%';
    $s = $pdo->prepare("
      UPDATE folders
      SET deleted_at=NOW(), deleted_by=?
      WHERE owner_id=? AND storage_area=? AND deleted_at IS NULL AND (name=? OR name LIKE ?)
    ");
    $s->execute([$deletedBy, $userId, self::normalizeStorageArea($storageArea), $normalized, $like]);
    return $s->rowCount();
  }

  public static function restoreTreeForUser(PDO $pdo, int $userId, string $path, string $storageArea = 'PRIVATE'): int {
    $normalized = self::normalizePath($path);
    $like = $normalized . self::PATH_SEPARATOR . '%';
    $s = $pdo->prepare("
      UPDATE folders
      SET deleted_at=NULL, deleted_by=NULL
      WHERE owner_id=? AND storage_area=? AND deleted_at IS NOT NULL AND (name=? OR name LIKE ?)
    ");
    $s->execute([$userId, self::normalizeStorageArea($storageArea), $normalized, $like]);
    return $s->rowCount();
  }

  public static function trashedRootFolders(array $folders): array {
    $pathMap = [];
    foreach ($folders as $folder) {
      $pathMap[self::normalizePath((string)($folder['name'] ?? ''))] = true;
    }

    $roots = [];
    foreach ($folders as $folder) {
      $path = self::normalizePath((string)($folder['name'] ?? ''));
      $parent = self::parentPath($path);
      if ($parent !== '' && isset($pathMap[$parent])) {
        continue;
      }
      $folder['display_name'] = self::basename($path);
      $folder['path_name'] = $path;
      $roots[] = $folder;
    }

    return $roots;
  }

  public static function idsInTreeForPaths(PDO $pdo, int $userId, array $paths): array {
    $paths = array_values(array_unique(array_filter(array_map([self::class, 'normalizePath'], $paths))));
    if (empty($paths)) {
      return [];
    }

    $folders = [];
    foreach ($paths as $path) {
      foreach (self::listTreeIncludingDeletedForUser($pdo, $userId, $path) as $folder) {
        $folders[(int)$folder['id']] = true;
      }
    }

    return array_keys($folders);
  }

  public static function hardDeleteByIds(PDO $pdo, array $folderIds, int $userId): int {
    $ids = array_values(array_unique(array_map('intval', $folderIds)));
    if (empty($ids)) {
      return 0;
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge($ids, [$userId]);
    $s = $pdo->prepare("DELETE FROM folders WHERE id IN ($ph) AND owner_id=?");
    $s->execute($params);
    return $s->rowCount();
  }

  public static function renameTreeForUser(PDO $pdo, int $userId, string $oldPath, string $newPath, string $storageArea = 'PRIVATE'): int {
    $oldNormalized = self::normalizePath($oldPath);
    $newNormalized = self::normalizePath($newPath);
    $folders = self::listTreeForUser($pdo, $userId, $oldNormalized, $storageArea);
    $updated = 0;
    foreach ($folders as $folder) {
      $current = (string)($folder['name'] ?? '');
      $suffix = $current === $oldNormalized ? '' : substr($current, strlen($oldNormalized));
      $target = $newNormalized . $suffix;
      $s = $pdo->prepare("UPDATE folders SET name=? WHERE id=? AND owner_id=? AND storage_area=?");
      $s->execute([$target, (int)$folder['id'], $userId, self::normalizeStorageArea($storageArea)]);
      $updated += $s->rowCount();
    }
    return $updated;
  }

  public static function moveTreeToStorageArea(PDO $pdo, int $userId, string $path, string $fromStorageArea, string $toStorageArea): array {
    $tree = self::listTreeForUser($pdo, $userId, $path, $fromStorageArea);
    $map = [];
    foreach ($tree as $folder) {
      $currentPath = (string)($folder['name'] ?? '');
      $targetId = self::firstOrCreateForUser($pdo, $userId, $currentPath, $toStorageArea);
      $map[(int)$folder['id']] = $targetId;
    }

    $sourceIds = array_keys($map);
    if (!empty($sourceIds)) {
      $ph = implode(',', array_fill(0, count($sourceIds), '?'));
      $params = array_merge($sourceIds, [$userId, self::normalizeStorageArea($fromStorageArea)]);
      $s = $pdo->prepare("DELETE FROM folders WHERE id IN ($ph) AND owner_id=? AND storage_area=?");
      $s->execute($params);
    }

    return $map;
  }

  public static function normalizePath(string $name): string {
    $segments = self::pathSegments($name);
    return implode(self::PATH_SEPARATOR, $segments);
  }

  public static function pathSegments(string $name): array {
    return array_values(array_filter(array_map(
      static fn(string $segment): string => trim($segment),
      preg_split('/\s*\/\s*|\s*\\\\\s*|\s+\|\s+/', str_replace(self::PATH_SEPARATOR, '/', trim($name))) ?: []
    ), static fn(string $segment): bool => $segment !== ''));
  }

  public static function basename(string $name): string {
    $segments = self::pathSegments($name);
    return $segments ? end($segments) : '';
  }

  public static function parentPath(string $name): string {
    $segments = self::pathSegments($name);
    array_pop($segments);
    return implode(self::PATH_SEPARATOR, $segments);
  }
}
