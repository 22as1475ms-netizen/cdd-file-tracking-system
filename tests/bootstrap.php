<?php

define('CDDFTS_TEST_ROOT', dirname(__DIR__));
define('CDDFTS_TEST_DB', 'cdd_file_tracking_system_test');
define('CDDFTS_TEST_MODE', true);

putenv('APP_SECRET=cdd-file-tracking-system-test-secret');
putenv('STORAGE_DIR=' . CDDFTS_TEST_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test_documents');
putenv('SESSION_SAVE_PATH=' . CDDFTS_TEST_ROOT . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'test_sessions');
putenv('DB_HOST=127.0.0.1');
putenv('DB_PORT=3306');
putenv('DB_NAME=' . CDDFTS_TEST_DB);
putenv('DB_USER=root');
putenv('DB_PASS=');
putenv('DB_AUTO_UNIFY_ROUTED_STORAGE=0');
putenv('DB_AUTO_BOOTSTRAP_SCHEMA=1');

if (session_status() !== PHP_SESSION_ACTIVE) {
  $_SESSION = [];
}

require_once CDDFTS_TEST_ROOT . '/app/config/app.php';

function cddfts_test_server_pdo(): PDO {
  static $pdo = null;
  if ($pdo instanceof PDO) {
    return $pdo;
  }

  $pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;charset=utf8mb4',
    'root',
    '',
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );

  return $pdo;
}

function cddfts_test_delete_tree(string $path): void {
  if (!is_dir($path)) {
    return;
  }

  $items = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    RecursiveIteratorIterator::CHILD_FIRST
  );

  foreach ($items as $item) {
    if ($item->isDir()) {
      @rmdir($item->getPathname());
      continue;
    }
    @unlink($item->getPathname());
  }

  @rmdir($path);
}

function cddfts_test_import_sql(PDO $pdo, string $sql): void {
  $lines = preg_split("/\r\n|\n|\r/", $sql) ?: [];
  $statement = '';

  foreach ($lines as $line) {
    $trimmed = trim($line);
    if ($trimmed === '' || str_starts_with($trimmed, '--')) {
      continue;
    }

    $statement .= $line . "\n";
    if (str_ends_with($trimmed, ';')) {
      $pdo->exec($statement);
      $statement = '';
    }
  }

  if (trim($statement) !== '') {
    $pdo->exec($statement);
  }
}

function cddfts_test_reset_database(): void {
  $server = cddfts_test_server_pdo();
  $server->exec('DROP DATABASE IF EXISTS `' . CDDFTS_TEST_DB . '`');
  $server->exec('CREATE DATABASE `' . CDDFTS_TEST_DB . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');

  $pdo = new PDO(
    'mysql:host=127.0.0.1;port=3306;dbname=' . CDDFTS_TEST_DB . ';charset=utf8mb4',
    'root',
    '',
    [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
    ]
  );

  cddfts_test_import_sql($pdo, (string)file_get_contents(CDDFTS_TEST_ROOT . '/cdd-file-tracking-system.sql'));

  $storageRoot = STORAGE_DIR;
  $sessionRoot = cddfts_session_dir();
  cddfts_test_delete_tree($storageRoot);
  cddfts_test_delete_tree($sessionRoot);
  @mkdir($storageRoot, 0775, true);
  @mkdir($sessionRoot, 0775, true);

  if (!function_exists('cddfts_bootstrap_schema')) {
    require_once CDDFTS_TEST_ROOT . '/app/config/database.php';
  }

  cddfts_bootstrap_schema($pdo);
  $GLOBALS['pdo'] = $pdo;
}

cddfts_test_reset_database();

require_once CDDFTS_TEST_ROOT . '/app/models/User.php';
require_once CDDFTS_TEST_ROOT . '/app/models/Document.php';
require_once CDDFTS_TEST_ROOT . '/app/models/Permission.php';
require_once CDDFTS_TEST_ROOT . '/app/models/Notification.php';
require_once CDDFTS_TEST_ROOT . '/app/models/DocumentRoute.php';
require_once CDDFTS_TEST_ROOT . '/app/models/AuditLog.php';
require_once CDDFTS_TEST_ROOT . '/app/services/DocumentShareService.php';

require_once CDDFTS_TEST_ROOT . '/app/models/DocumentReview.php';
require_once CDDFTS_TEST_ROOT . '/app/services/DocumentReviewService.php';


