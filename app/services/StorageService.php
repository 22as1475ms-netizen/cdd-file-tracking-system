<?php

class StorageService {
  public static function driver(): string {
    return STORAGE_DRIVER;
  }

  public static function usesDatabase(): bool {
    return self::driver() === 'database';
  }

  public static function publicMediaUrl(string $key, bool $download = false): string {
    $query = http_build_query([
      'k' => $key,
      'download' => $download ? '1' : '0',
    ]);
    return cddfts_base_url_path('/media/file?' . $query);
  }

  public static function storeUploadedFile(PDO $pdo, array $file, string $logicalPath, array $meta = []): bool {
    // Binary file handling is intentionally centralized here so the app can swap
    // storage backends without changing document/routing code everywhere else.
    $tmpPath = (string)($file['tmp_name'] ?? '');
    if ($tmpPath === '' || !self::isAcceptedUploadSource($tmpPath)) {
      return false;
    }

    if (self::usesDatabase()) {
      $contents = @file_get_contents($tmpPath);
      if ($contents === false) {
        return false;
      }
      $stored = self::storeContents($pdo, $logicalPath, $contents, $meta);
      @unlink($tmpPath);
      return $stored;
    }

    $targetPath = self::absolutePath($logicalPath);
    if ($targetPath === null) {
      return false;
    }

    $dir = dirname($targetPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      return false;
    }

    return self::moveIncomingFile($tmpPath, $targetPath);
  }

  public static function storeContents(PDO $pdo, string $logicalPath, string $contents, array $meta = []): bool {
    if (self::usesDatabase()) {
      $sql = "
        INSERT INTO stored_files(storage_key, kind, visibility, original_name, mime_type, size_bytes, content, created_by)
        VALUES(?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          kind = VALUES(kind),
          visibility = VALUES(visibility),
          original_name = VALUES(original_name),
          mime_type = VALUES(mime_type),
          size_bytes = VALUES(size_bytes),
          content = VALUES(content),
          created_by = VALUES(created_by)
      ";
      $stmt = $pdo->prepare($sql);
      return $stmt->execute([
        $logicalPath,
        (string)($meta['kind'] ?? 'generic'),
        (string)($meta['visibility'] ?? 'private'),
        self::nullableString($meta['original_name'] ?? null),
        self::nullableString($meta['mime_type'] ?? null),
        strlen($contents),
        $contents,
        self::nullableInt($meta['created_by'] ?? null),
      ]);
    }

    $targetPath = self::absolutePath($logicalPath);
    if ($targetPath === null) {
      return false;
    }

    $dir = dirname($targetPath);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      return false;
    }

    return file_put_contents($targetPath, $contents, LOCK_EX) !== false;
  }

  public static function copy(PDO $pdo, string $sourcePath, string $targetPath, array $meta = []): bool {
    if (self::usesDatabase()) {
      $row = self::databaseRow($pdo, $sourcePath);
      if (!$row) {
        return false;
      }

      return self::storeContents($pdo, $targetPath, (string)$row['content'], [
        'kind' => $meta['kind'] ?? (string)($row['kind'] ?? 'generic'),
        'visibility' => $meta['visibility'] ?? (string)($row['visibility'] ?? 'private'),
        'original_name' => $meta['original_name'] ?? (string)($row['original_name'] ?? ''),
        'mime_type' => $meta['mime_type'] ?? (string)($row['mime_type'] ?? ''),
        'created_by' => $meta['created_by'] ?? ($row['created_by'] ?? null),
      ]);
    }

    $sourceAbs = self::absolutePath($sourcePath);
    $targetAbs = self::absolutePath($targetPath);
    if ($sourceAbs === null || $targetAbs === null || !is_file($sourceAbs)) {
      return false;
    }

    $dir = dirname($targetAbs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      return false;
    }

    return @copy($sourceAbs, $targetAbs);
  }

  public static function move(PDO $pdo, string $sourcePath, string $targetPath): bool {
    if (self::usesDatabase()) {
      $stmt = $pdo->prepare("UPDATE stored_files SET storage_key = ? WHERE storage_key = ?");
      $stmt->execute([$targetPath, $sourcePath]);
      return $stmt->rowCount() > 0;
    }

    $sourceAbs = self::absolutePath($sourcePath);
    $targetAbs = self::absolutePath($targetPath);
    if ($sourceAbs === null || $targetAbs === null || !file_exists($sourceAbs)) {
      return false;
    }

    $dir = dirname($targetAbs);
    if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
      return false;
    }

    return @rename($sourceAbs, $targetAbs);
  }

  public static function delete(PDO $pdo, string $logicalPath): void {
    if ($logicalPath === '') {
      return;
    }

    if (self::usesDatabase()) {
      $stmt = $pdo->prepare("DELETE FROM stored_files WHERE storage_key = ?");
      $stmt->execute([$logicalPath]);
      return;
    }

    $abs = self::absolutePath($logicalPath);
    if ($abs !== null && is_file($abs)) {
      @unlink($abs);
    }
  }

  public static function deleteByPrefix(PDO $pdo, string $prefix): int {
    if ($prefix === '') {
      return 0;
    }

    if (self::usesDatabase()) {
      $stmt = $pdo->prepare("DELETE FROM stored_files WHERE storage_key LIKE ?");
      $stmt->execute([$prefix . '%']);
      return $stmt->rowCount();
    }

    $abs = self::absolutePath($prefix);
    if ($abs === null || !is_dir($abs)) {
      return 0;
    }

    $count = 0;
    $items = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS),
      RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($items as $item) {
      if ($item->isDir()) {
        @rmdir($item->getPathname());
        continue;
      }
      if (@unlink($item->getPathname())) {
        $count++;
      }
    }
    @rmdir($abs);
    return $count;
  }

  public static function exists(PDO $pdo, string $logicalPath): bool {
    if (self::usesDatabase()) {
      $stmt = $pdo->prepare("SELECT 1 FROM stored_files WHERE storage_key = ? LIMIT 1");
      $stmt->execute([$logicalPath]);
      return (bool)$stmt->fetchColumn();
    }

    $abs = self::absolutePath($logicalPath);
    return $abs !== null && is_file($abs);
  }

  public static function size(PDO $pdo, string $logicalPath): int {
    if (self::usesDatabase()) {
      $stmt = $pdo->prepare("SELECT size_bytes FROM stored_files WHERE storage_key = ? LIMIT 1");
      $stmt->execute([$logicalPath]);
      return (int)($stmt->fetchColumn() ?: 0);
    }

    $abs = self::absolutePath($logicalPath);
    return ($abs !== null && is_file($abs)) ? (int)(filesize($abs) ?: 0) : 0;
  }

  public static function withReadablePath(PDO $pdo, string $logicalPath, callable $callback): mixed {
    if (!self::usesDatabase()) {
      $abs = self::absolutePath($logicalPath);
      if ($abs === null || !is_file($abs)) {
        return null;
      }
      return $callback($abs);
    }

    $row = self::databaseRow($pdo, $logicalPath);
    if (!$row) {
      return null;
    }

    $tmp = tempnam(sys_get_temp_dir(), 'cddfts_');
    if ($tmp === false) {
      return null;
    }

    if (file_put_contents($tmp, (string)$row['content'], LOCK_EX) === false) {
      @unlink($tmp);
      return null;
    }

    try {
      return $callback($tmp);
    } finally {
      @unlink($tmp);
    }
  }

  public static function output(PDO $pdo, string $logicalPath, ?int $start = null, ?int $end = null): bool {
    if (self::usesDatabase()) {
      $row = self::databaseRow($pdo, $logicalPath);
      if (!$row) {
        return false;
      }

      $content = (string)$row['content'];
      $length = strlen($content);
      $start = $start ?? 0;
      $end = $end ?? max(0, $length - 1);
      if ($length === 0 || $start > $end || $start >= $length) {
        return false;
      }

      echo substr($content, $start, ($end - $start) + 1);
      return true;
    }

    $abs = self::absolutePath($logicalPath);
    if ($abs === null || !is_file($abs)) {
      return false;
    }

    $handle = fopen($abs, 'rb');
    if ($handle === false) {
      return false;
    }

    $start = $start ?? 0;
    $end = $end ?? max(0, (int)(filesize($abs) ?: 0) - 1);
    if ($start > 0) {
      fseek($handle, $start);
    }

    $remaining = ($end - $start) + 1;
    while ($remaining > 0 && !feof($handle)) {
      $chunkSize = min(8192, $remaining);
      $buffer = fread($handle, $chunkSize);
      if ($buffer === false) {
        break;
      }
      echo $buffer;
      flush();
      $remaining -= strlen($buffer);
    }

    fclose($handle);
    return true;
  }

  public static function storageUsage(PDO $pdo, array $prefixes = []): array {
    if (!self::usesDatabase()) {
      $bytes = 0;
      $files = 0;
      foreach ($prefixes as $prefix) {
        $abs = self::absolutePath($prefix);
        if ($abs === null || !is_dir($abs)) {
          continue;
        }
        $items = new RecursiveIteratorIterator(
          new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($items as $item) {
          if ($item->isFile()) {
            $files++;
            $bytes += $item->getSize();
          }
        }
      }
      return ['files' => $files, 'bytes' => $bytes];
    }

    if (empty($prefixes)) {
      return ['files' => 0, 'bytes' => 0];
    }

    $clauses = [];
    $params = [];
    foreach ($prefixes as $prefix) {
      $clauses[] = "storage_key LIKE ?";
      $params[] = $prefix . '%';
    }

    $sql = "SELECT COUNT(*) AS file_count, COALESCE(SUM(size_bytes), 0) AS total_bytes FROM stored_files WHERE " . implode(' OR ', $clauses);
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch() ?: [];
    return [
      'files' => (int)($row['file_count'] ?? 0),
      'bytes' => (int)($row['total_bytes'] ?? 0),
    ];
  }

  public static function absolutePath(string $logicalPath): ?string {
    if (self::usesDatabase()) {
      return null;
    }

    $normalized = str_replace('\\', '/', $logicalPath);
    if (str_starts_with($normalized, '../storage/documents/') || str_starts_with($normalized, '/storage/documents/')) {
      return self::absoluteDocumentPath($logicalPath);
    }

    if (str_starts_with($normalized, 'public/avatars/')) {
      return cddfts_public_upload_dir('avatars') . DIRECTORY_SEPARATOR . basename($normalized);
    }

    return null;
  }

  public static function absoluteDocumentPath(string $logicalPath): string {
    $normalized = str_replace(['\\', '..'], ['/', ''], $logicalPath);
    $prefix = '/storage/documents/';
    $pos = strpos($normalized, $prefix);
    if ($pos !== false) {
      $normalized = substr($normalized, $pos + strlen($prefix));
    }

    $normalized = ltrim($normalized, '/');
    return rtrim(STORAGE_DIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $normalized);
  }

  private static function databaseRow(PDO $pdo, string $logicalPath): ?array {
    $stmt = $pdo->prepare("
      SELECT storage_key, kind, visibility, original_name, mime_type, size_bytes, content, created_by
      FROM stored_files
      WHERE storage_key = ?
      LIMIT 1
    ");
    $stmt->execute([$logicalPath]);
    $row = $stmt->fetch();
    return $row ?: null;
  }

  private static function isAcceptedUploadSource(string $tmpName): bool {
    if ($tmpName === '') {
      return false;
    }

    if (is_uploaded_file($tmpName)) {
      return true;
    }

    return defined('CDDFTS_TEST_MODE') && CDDFTS_TEST_MODE && is_file($tmpName);
  }

  private static function moveIncomingFile(string $sourcePath, string $targetPath): bool {
    if (move_uploaded_file($sourcePath, $targetPath)) {
      return true;
    }

    if (!(defined('CDDFTS_TEST_MODE') && CDDFTS_TEST_MODE)) {
      return false;
    }

    if (@rename($sourcePath, $targetPath)) {
      return true;
    }

    return @copy($sourcePath, $targetPath) && @unlink($sourcePath);
  }

  private static function nullableString(mixed $value): ?string {
    if ($value === null) {
      return null;
    }

    $string = trim((string)$value);
    return $string === '' ? null : $string;
  }

  private static function nullableInt(mixed $value): ?int {
    if ($value === null || $value === '') {
      return null;
    }

    return (int)$value;
  }
}
