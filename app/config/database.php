<?php
$dbHost = cddfts_env_string('DB_HOST', '127.0.0.1');
$dbPort = cddfts_env_int('DB_PORT', 3306, 1);
$dbName = cddfts_env_string('DB_NAME', 'cdd_file_tracking_system');
$dbUser = cddfts_env_string('DB_USER', 'root');
$dbPass = cddfts_env_string('DB_PASS', '');
$dbCharset = cddfts_env_string('DB_CHARSET', 'utf8mb4');
$dbSslCa = cddfts_resolve_database_ssl_ca_path();
const CDDFTS_SCHEMA_VERSION = 5;

$pdo = cddfts_connect_database($dbHost, $dbPort, $dbName, $dbUser, $dbPass, $dbCharset, $dbSslCa);

if (cddfts_env_bool('DB_AUTO_BOOTSTRAP_SCHEMA', true)) {
  cddfts_bootstrap_schema($pdo);
}

function cddfts_connect_database(
  string $dbHost,
  int $dbPort,
  string $dbName,
  string $dbUser,
  string $dbPass,
  string $dbCharset,
  ?string $dbSslCa = null
): PDO {
  $hostsToTry = [$dbHost];
  if (!cddfts_env_has('DB_HOST') && cddfts_is_windows_host() && $dbHost === '127.0.0.1') {
    $hostsToTry[] = 'localhost';
  }

  $pdoOptions = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
  ];

  if ($dbSslCa !== null && $dbSslCa !== '' && defined('PDO::MYSQL_ATTR_SSL_CA')) {
    $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $dbSslCa;
  }

  if (defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
    $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
  }

  $lastException = null;
  foreach (array_values(array_unique($hostsToTry)) as $host) {
    try {
      return new PDO(
        "mysql:host={$host};port={$dbPort};dbname={$dbName};charset={$dbCharset}",
        $dbUser,
        $dbPass,
        $pdoOptions
      );
    } catch (PDOException $exception) {
      $lastException = $exception;
    }
  }

  throw cddfts_database_connection_exception(
    $hostsToTry,
    $dbPort,
    $dbName,
    $dbUser,
    $lastException
  );
}

function cddfts_database_connection_exception(
  array $hostsToTry,
  int $dbPort,
  string $dbName,
  string $dbUser,
  ?PDOException $previous
): RuntimeException {
  $attemptedHosts = implode(', ', array_values(array_unique($hostsToTry)));
  $message = "Database connection failed for database '{$dbName}' as user '{$dbUser}' using host(s): {$attemptedHosts} on port {$dbPort}.";

  if ($previous instanceof PDOException) {
    $message .= ' MariaDB said: ' . $previous->getMessage() . '.';
  }

  $message .= ' Update your local `.env` database settings or create/grant a MariaDB account that can connect from this machine.';

  if (cddfts_is_windows_host()) {
    $message .= " On XAMPP for Windows, try setting `DB_HOST=localhost` in `.env` first; if MariaDB still returns `SQLSTATE[HY000] [1130]`, the database user itself needs a host grant.";
  }

  return new RuntimeException($message, 0, $previous);
}

function cddfts_env_has(string $key): bool {
  $value = getenv($key);
  return $value !== false && $value !== '';
}

function cddfts_is_windows_host(): bool {
  return DIRECTORY_SEPARATOR === '\\';
}

function cddfts_resolve_database_ssl_ca_path(): ?string {
  $configured = cddfts_env_string('DB_SSL_CA', '');
  if ($configured !== '' && is_file($configured)) {
    return $configured;
  }

  foreach ([
    '/etc/ssl/certs/ca-certificates.crt',
    '/etc/pki/tls/certs/ca-bundle.crt',
    '/etc/ssl/cert.pem',
    '/etc/ssl/ca-bundle.pem',
  ] as $candidate) {
    if (is_file($candidate)) {
      return $candidate;
    }
  }

  return $configured !== '' ? $configured : null;
}

function cddfts_bootstrap_schema(PDO $pdo): void {
  cddfts_ensure_meta_table($pdo);
  cddfts_ensure_base_schema($pdo);
  cddfts_ensure_audit_logs_table($pdo);
  cddfts_add_column_if_missing($pdo, 'audit_logs', 'category', "VARCHAR(30) NOT NULL DEFAULT 'SYSTEM'");
  cddfts_backfill_audit_log_categories($pdo);

  if (cddfts_get_schema_version($pdo) >= CDDFTS_SCHEMA_VERSION) {
    return;
  }

  cddfts_add_column_if_missing($pdo, 'folders', 'deleted_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'folders', 'deleted_by', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'folders', 'storage_area', "VARCHAR(20) NOT NULL DEFAULT 'PRIVATE'");
  cddfts_add_column_if_missing($pdo, 'documents', 'checked_out_by', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'checked_out_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'tags', "VARCHAR(255) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'category', "VARCHAR(100) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'status', "VARCHAR(20) NOT NULL DEFAULT 'Draft'");
  cddfts_add_column_if_missing($pdo, 'documents', 'retention_until', "DATE NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'deleted_by', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'deleted_reason', "VARCHAR(255) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'approval_locked', "TINYINT(1) NOT NULL DEFAULT 0");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_note', "VARCHAR(1000) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'storage_area', "VARCHAR(20) NOT NULL DEFAULT 'PRIVATE'");
  cddfts_add_column_if_missing($pdo, 'documents', 'division_id', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'submitted_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'reviewed_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'reviewed_by', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'document_code', "VARCHAR(80) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'title', "VARCHAR(255) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'document_type', "VARCHAR(20) NOT NULL DEFAULT 'INCOMING'");
  cddfts_add_column_if_missing($pdo, 'documents', 'signatory', "VARCHAR(150) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'current_location', "VARCHAR(180) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'routing_status', "VARCHAR(40) NOT NULL DEFAULT 'AVAILABLE'");
  cddfts_add_column_if_missing($pdo, 'documents', 'priority_level', "VARCHAR(20) NOT NULL DEFAULT 'NORMAL'");
  cddfts_add_column_if_missing($pdo, 'documents', 'document_date', "DATE NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_acceptance_status', "VARCHAR(30) NOT NULL DEFAULT 'NOT_SENT'");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_accepted_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_declined_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_acceptance_note', "VARCHAR(1000) NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_stage', "VARCHAR(30) NOT NULL DEFAULT 'NOT_SENT'");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_section_id', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'assigned_reviewer_id', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'review_escalated_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'documents', 'route_outcome', "VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'");
  cddfts_add_column_if_missing($pdo, 'documents', 'route_closed_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'users', 'avatar_photo', "VARCHAR(255) NULL");
  cddfts_add_column_if_missing($pdo, 'users', 'avatar_preset', "VARCHAR(32) NULL");
  cddfts_add_column_if_missing($pdo, 'users', 'division_id', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'users', 'availability_status', "VARCHAR(20) NOT NULL DEFAULT 'ACTIVE'");
  cddfts_add_column_if_missing($pdo, 'users', 'availability_note', "VARCHAR(255) NULL");
  cddfts_add_column_if_missing($pdo, 'users', 'onboarding_seen_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'users', 'onboarding_guide_version', "VARCHAR(40) NULL");
  cddfts_add_column_if_missing($pdo, 'users', 'generated_password', "VARCHAR(255) NULL");
  cddfts_ensure_user_role_storage($pdo);

  cddfts_normalize_legacy_roles($pdo);

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS divisions (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      chief_user_id INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_division_name (name),
      INDEX(chief_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS document_reviews (
      id INT AUTO_INCREMENT PRIMARY KEY,
      document_id INT NOT NULL,
      reviewer_id INT NOT NULL,
      decision VARCHAR(20) NOT NULL,
      note VARCHAR(1000) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(document_id),
      INDEX(reviewer_id),
      INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS notifications (
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      title VARCHAR(180) NOT NULL,
      body VARCHAR(255) NULL,
      link VARCHAR(255) NULL,
      is_read TINYINT(1) NOT NULL DEFAULT 0,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(user_id),
      INDEX(is_read),
      INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS organizations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      description TEXT NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_organization_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS sections (
      id INT AUTO_INCREMENT PRIMARY KEY,
      organization_id INT NOT NULL,
      name VARCHAR(150) NOT NULL,
      description TEXT NULL,
      parent_section_id INT NULL,
      chief_id INT NULL,
      position_in_chart INT NOT NULL DEFAULT 0,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX(organization_id),
      INDEX(parent_section_id),
      INDEX(chief_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS team_members (
      id INT AUTO_INCREMENT PRIMARY KEY,
      section_id INT NOT NULL,
      user_id INT NOT NULL,
      role VARCHAR(30) NOT NULL DEFAULT 'MEMBER',
      added_by INT NULL,
      joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_team_member (section_id, user_id),
      INDEX(user_id),
      INDEX(role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS team_invitations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      section_id INT NOT NULL,
      invited_user_id INT NULL,
      email VARCHAR(120) NOT NULL,
      token VARCHAR(100) NOT NULL,
      role VARCHAR(30) NOT NULL DEFAULT 'MEMBER',
      status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
      created_by INT NOT NULL,
      accepted_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      accepted_at TIMESTAMP NULL,
      rejected_at TIMESTAMP NULL,
      expires_at TIMESTAMP NULL,
      UNIQUE KEY uniq_team_invitation_token (token),
      INDEX(section_id),
      INDEX(invited_user_id),
      INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS sessions (
      id VARCHAR(128) PRIMARY KEY,
      payload MEDIUMTEXT NOT NULL,
      last_activity_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      expires_at DATETIME NOT NULL,
      INDEX(expires_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS stored_files (
      id BIGINT AUTO_INCREMENT PRIMARY KEY,
      storage_key VARCHAR(255) NOT NULL,
      kind VARCHAR(40) NOT NULL DEFAULT 'generic',
      visibility VARCHAR(20) NOT NULL DEFAULT 'private',
      original_name VARCHAR(255) NULL,
      mime_type VARCHAR(120) NULL,
      size_bytes BIGINT NOT NULL DEFAULT 0,
      content LONGBLOB NOT NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_storage_key (storage_key),
      INDEX(kind),
      INDEX(visibility),
      INDEX(created_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS document_routes (
      id INT AUTO_INCREMENT PRIMARY KEY,
      document_id INT NOT NULL,
      from_location VARCHAR(180) NULL,
      to_location VARCHAR(180) NOT NULL,
      status_snapshot VARCHAR(40) NOT NULL DEFAULT 'AVAILABLE',
      note VARCHAR(1000) NULL,
      routed_by INT NOT NULL,
      routed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(document_id),
      INDEX(routed_by),
      INDEX(routed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  cddfts_add_column_if_missing($pdo, 'permissions', 'shared_by', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'permissions', 'accepted_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'permissions', 'declined_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'permissions', 'response_note', "VARCHAR(1000) NULL");
  cddfts_add_column_if_missing($pdo, 'team_invitations', 'invited_user_id', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'team_invitations', 'accepted_by', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'team_invitations', 'accepted_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'team_invitations', 'rejected_at', "TIMESTAMP NULL");
  cddfts_add_column_if_missing($pdo, 'team_members', 'delegate_user_id', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'team_members', 'delegate_note', "VARCHAR(255) NULL");
  cddfts_add_column_if_missing($pdo, 'organizations', 'updated_at', "TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP");
  cddfts_add_column_if_missing($pdo, 'sections', 'parent_section_id', "INT NULL");
  cddfts_add_column_if_missing($pdo, 'sections', 'position_in_chart', "INT NOT NULL DEFAULT 0");

  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_owner_deleted_storage_folder', ['owner_id', 'deleted_at', 'storage_area', 'folder_id']);
  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_division_storage_deleted', ['division_id', 'storage_area', 'deleted_at']);
  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_review_acceptance_status', ['review_acceptance_status', 'division_id']);
  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_assigned_review', ['assigned_reviewer_id', 'review_stage', 'review_acceptance_status']);
  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_route_status', ['routing_status', 'route_outcome']);
  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_owner_deleted_created', ['owner_id', 'deleted_at', 'created_at']);
  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_deleted_route_created', ['deleted_at', 'routing_status', 'route_outcome', 'created_at']);
  cddfts_add_index_if_missing($pdo, 'documents', 'idx_documents_deleted_assigned_created', ['deleted_at', 'assigned_reviewer_id', 'created_at']);
  cddfts_add_index_if_missing($pdo, 'document_versions', 'idx_document_versions_doc_version', ['document_id', 'version_number']);
  cddfts_add_index_if_missing($pdo, 'document_routes', 'idx_document_routes_doc_routed', ['document_id', 'routed_at']);
  cddfts_add_index_if_missing($pdo, 'document_routes', 'idx_document_routes_doc_id_id', ['document_id', 'id']);
  cddfts_add_index_if_missing($pdo, 'notifications', 'idx_notifications_user_read_created', ['user_id', 'is_read', 'created_at']);
  cddfts_add_index_if_missing($pdo, 'permissions', 'idx_permissions_document_user', ['document_id', 'user_id']);
  cddfts_add_index_if_missing($pdo, 'permissions', 'idx_permissions_user_document', ['user_id', 'document_id']);
  cddfts_add_index_if_missing($pdo, 'permissions', 'idx_permissions_document_accepted_id', ['document_id', 'accepted_at', 'id']);
  cddfts_add_index_if_missing($pdo, 'folders', 'idx_folders_owner_deleted_storage', ['owner_id', 'deleted_at', 'storage_area']);
  cddfts_add_index_if_missing($pdo, 'folders', 'idx_folders_owner_storage_deleted_name', ['owner_id', 'storage_area', 'deleted_at', 'name']);
  // Search and routed-dashboard performance depends heavily on these indexes.
  // If queries change, revisit the indexes here at the same time.
  if (cddfts_document_fulltext_enabled()) {
    cddfts_add_fulltext_index_if_missing($pdo, 'documents', 'ft_documents_search', ['name', 'title', 'document_code', 'current_location', 'signatory', 'tags']);
  }
  cddfts_add_index_if_missing($pdo, 'sections', 'idx_sections_org_position_name', ['organization_id', 'position_in_chart', 'name']);
  cddfts_add_index_if_missing($pdo, 'team_members', 'idx_team_members_section_user', ['section_id', 'user_id'], true);
  cddfts_add_index_if_missing($pdo, 'team_invitations', 'idx_team_invitations_section_status', ['section_id', 'status']);
  cddfts_add_index_if_missing($pdo, 'team_invitations', 'idx_team_invitations_user_status', ['invited_user_id', 'status']);

  cddfts_ensure_varchar_length($pdo, 'documents', 'routing_status', 40, false, 'AVAILABLE');
  cddfts_ensure_varchar_length($pdo, 'document_routes', 'status_snapshot', 40, false, 'AVAILABLE');

  if (cddfts_env_bool('DB_AUTO_UNIFY_ROUTED_STORAGE', false)) {
    cddfts_unify_routed_storage($pdo);
  }

  cddfts_seed_base_data($pdo);
  cddfts_set_schema_version($pdo, CDDFTS_SCHEMA_VERSION);
}

function cddfts_ensure_base_schema(PDO $pdo): void {
  if (cddfts_table_exists($pdo, 'users')
    && cddfts_table_exists($pdo, 'folders')
    && cddfts_table_exists($pdo, 'documents')
    && cddfts_table_exists($pdo, 'document_versions')
    && cddfts_table_exists($pdo, 'permissions')
    && cddfts_table_exists($pdo, 'audit_logs')) {
    return;
  }

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS users(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(100) NOT NULL,
      email VARCHAR(120) UNIQUE NOT NULL,
      password VARCHAR(255) NOT NULL,
      role VARCHAR(40) NOT NULL DEFAULT 'SECTION_STAFF',
      status ENUM('ACTIVE','DISABLED') DEFAULT 'ACTIVE',
      division_id INT NULL,
      availability_status ENUM('ACTIVE','BUSY','ON_LEAVE') NOT NULL DEFAULT 'ACTIVE',
      availability_note VARCHAR(255) NULL,
      generated_password VARCHAR(255) NULL,
      onboarding_seen_at TIMESTAMP NULL,
      onboarding_guide_version VARCHAR(40) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS divisions(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(120) NOT NULL,
      chief_user_id INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS folders(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      owner_id INT NOT NULL,
      storage_area ENUM('PRIVATE','OFFICIAL') NOT NULL DEFAULT 'PRIVATE',
      deleted_at TIMESTAMP NULL,
      deleted_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(owner_id),
      INDEX(deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS documents(
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      owner_id INT NOT NULL,
      folder_id INT NULL,
      storage_area ENUM('PRIVATE','OFFICIAL') DEFAULT 'PRIVATE',
      division_id INT NULL,
      status VARCHAR(20) NOT NULL DEFAULT 'Draft',
      review_note VARCHAR(1000) NULL,
      approval_locked TINYINT(1) NOT NULL DEFAULT 0,
      submitted_at TIMESTAMP NULL,
      reviewed_at TIMESTAMP NULL,
      reviewed_by INT NULL,
      review_stage VARCHAR(30) NOT NULL DEFAULT 'NOT_SENT',
      review_section_id INT NULL,
      assigned_reviewer_id INT NULL,
      review_escalated_at TIMESTAMP NULL,
      deleted_at TIMESTAMP NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(owner_id),
      INDEX(folder_id),
      INDEX(deleted_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS document_reviews(
      id INT AUTO_INCREMENT PRIMARY KEY,
      document_id INT NOT NULL,
      reviewer_id INT NOT NULL,
      decision VARCHAR(20) NOT NULL,
      note VARCHAR(1000) NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS document_versions(
      id INT AUTO_INCREMENT PRIMARY KEY,
      document_id INT NOT NULL,
      file_path TEXT NOT NULL,
      version_number INT NOT NULL,
      created_by INT NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(document_id),
      INDEX(created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS permissions(
      id INT AUTO_INCREMENT PRIMARY KEY,
      document_id INT NOT NULL,
      user_id INT NOT NULL,
      permission ENUM('viewer','editor') NOT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_doc_user (document_id, user_id),
      INDEX(user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS audit_logs(
      id INT AUTO_INCREMENT PRIMARY KEY,
      user_id INT NOT NULL,
      category VARCHAR(30) NOT NULL DEFAULT 'SYSTEM',
      action VARCHAR(255) NOT NULL,
      document_id INT NULL,
      meta TEXT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      INDEX(category),
      INDEX(user_id),
      INDEX(created_at),
      INDEX(document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS organizations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(150) NOT NULL,
      description TEXT NULL,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_organization_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS sections (
      id INT AUTO_INCREMENT PRIMARY KEY,
      organization_id INT NOT NULL,
      name VARCHAR(150) NOT NULL,
      description TEXT NULL,
      parent_section_id INT NULL,
      chief_id INT NULL,
      position_in_chart INT NOT NULL DEFAULT 0,
      created_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      INDEX(organization_id),
      INDEX(parent_section_id),
      INDEX(chief_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS team_members (
      id INT AUTO_INCREMENT PRIMARY KEY,
      section_id INT NOT NULL,
      user_id INT NOT NULL,
      role VARCHAR(30) NOT NULL DEFAULT 'MEMBER',
      delegate_user_id INT NULL,
      delegate_note VARCHAR(255) NULL,
      added_by INT NULL,
      joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_team_member (section_id, user_id),
      INDEX(user_id),
      INDEX(role)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  $pdo->exec("
    CREATE TABLE IF NOT EXISTS team_invitations (
      id INT AUTO_INCREMENT PRIMARY KEY,
      section_id INT NOT NULL,
      invited_user_id INT NULL,
      email VARCHAR(120) NOT NULL,
      token VARCHAR(100) NOT NULL,
      role VARCHAR(30) NOT NULL DEFAULT 'MEMBER',
      status VARCHAR(20) NOT NULL DEFAULT 'PENDING',
      created_by INT NOT NULL,
      accepted_by INT NULL,
      created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
      accepted_at TIMESTAMP NULL,
      rejected_at TIMESTAMP NULL,
      expires_at TIMESTAMP NULL,
      UNIQUE KEY uniq_team_invitation_token (token),
      INDEX(section_id),
      INDEX(invited_user_id),
      INDEX(status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
  ");

  cddfts_seed_base_data($pdo);
}

function cddfts_seed_base_data(PDO $pdo): void {
  $userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
  if ($userCount === 0) {
    $stmt = $pdo->prepare("
      INSERT INTO users(name, email, password, role, status)
      VALUES(?, ?, ?, 'SUPER_ADMIN', 'ACTIVE')
    ");
    $stmt->execute([
      'Administrator',
      'admin@cdd.local',
      '$argon2id$v=19$m=65536,t=4,p=1$OXpzQ0VxQ2tKVmZTNmJaYQ$M4XguHgL3mqI5kFtrmKFPtZEjXXalcm5B+DuO8l6iKU',
    ]);
  }

  $organizationCount = (int)$pdo->query("SELECT COUNT(*) FROM organizations")->fetchColumn();
  if ($organizationCount === 0) {
    $stmt = $pdo->prepare("INSERT INTO organizations(name, description, created_by) VALUES(?, ?, ?)");
    $stmt->execute(['CDD-File-Tracking-System', 'Default organization workspace', 1]);
  }
}

function cddfts_ensure_meta_table(PDO $pdo): void {
  try {
    $pdo->exec("
      CREATE TABLE IF NOT EXISTS app_meta (
        meta_key VARCHAR(100) PRIMARY KEY,
        meta_value VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  } catch (PDOException) {
    // app_meta only stores bootstrap metadata, so if its engine state is broken
    // we can safely recreate it without risking business data tables.
    $pdo->exec("DROP TABLE IF EXISTS app_meta");
    $pdo->exec("
      CREATE TABLE app_meta (
        meta_key VARCHAR(100) PRIMARY KEY,
        meta_value VARCHAR(255) NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }
}

function cddfts_get_schema_version(PDO $pdo): int {
  try {
    $stmt = $pdo->prepare("SELECT meta_value FROM app_meta WHERE meta_key = 'schema_version' LIMIT 1");
    $stmt->execute();
    $value = $stmt->fetchColumn();
    return $value === false ? 0 : (int)$value;
  } catch (PDOException) {
    cddfts_ensure_meta_table($pdo);
    return 0;
  }
}

function cddfts_set_schema_version(PDO $pdo, int $version): void {
  $stmt = $pdo->prepare("
    INSERT INTO app_meta(meta_key, meta_value)
    VALUES('schema_version', ?)
    ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
  ");
  $stmt->execute([(string)$version]);
}

function cddfts_get_meta_value(PDO $pdo, string $key): ?string {
  try {
    $stmt = $pdo->prepare("SELECT meta_value FROM app_meta WHERE meta_key = ? LIMIT 1");
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();
    return $value === false ? null : (string)$value;
  } catch (PDOException) {
    cddfts_ensure_meta_table($pdo);
    return null;
  }
}

function cddfts_set_meta_value(PDO $pdo, string $key, string $value): void {
  try {
    $stmt = $pdo->prepare("
      INSERT INTO app_meta(meta_key, meta_value)
      VALUES(?, ?)
      ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
    ");
    $stmt->execute([$key, $value]);
  } catch (PDOException) {
    cddfts_ensure_meta_table($pdo);
    $stmt = $pdo->prepare("
      INSERT INTO app_meta(meta_key, meta_value)
      VALUES(?, ?)
      ON DUPLICATE KEY UPDATE meta_value = VALUES(meta_value)
    ");
    $stmt->execute([$key, $value]);
  }
}

function cddfts_table_exists(PDO $pdo, string $table): bool {
  $s = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  $s->execute([$table]);
  return (int)$s->fetchColumn() > 0;
}

function cddfts_ensure_audit_logs_table(PDO $pdo): void {
  try {
    $pdo->query("SELECT 1 FROM audit_logs LIMIT 1");
  } catch (PDOException) {
    $pdo->exec("DROP TABLE IF EXISTS audit_logs");
    $pdo->exec("
      CREATE TABLE audit_logs(
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(30) NOT NULL DEFAULT 'SYSTEM',
        action VARCHAR(255) NOT NULL,
        document_id INT NULL,
        meta TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX(category),
        INDEX(user_id),
        INDEX(created_at),
        INDEX(document_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
  }
}

function cddfts_backfill_audit_log_categories(PDO $pdo): void {
  if (!cddfts_table_exists($pdo, 'audit_logs')) {
    return;
  }

  $backfillKey = 'audit_log_categories_backfilled_v1';
  if (cddfts_get_meta_value($pdo, $backfillKey) === '1') {
    return;
  }

  $columnCheck = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'audit_logs'
      AND COLUMN_NAME = 'category'
  ");
  $columnCheck->execute();
  if ((int)$columnCheck->fetchColumn() === 0) {
    return;
  }

  try {
    $pdo->exec("
      UPDATE audit_logs
      SET category = CASE
        WHEN LOWER(action) LIKE '%logged in%' OR LOWER(action) LIKE '%logged out%' OR LOWER(action) LIKE '%password%' THEN 'AUTH'
        WHEN LOWER(action) LIKE '%user%' OR LOWER(action) LIKE '%account%' OR LOWER(action) LIKE '%division%' OR LOWER(action) LIKE '%profile%' THEN 'ACCOUNT'
        WHEN LOWER(action) LIKE '%folder%' OR LOWER(action) LIKE '%trash%' THEN 'FOLDER'
        WHEN LOWER(action) LIKE '%review%' OR LOWER(action) LIKE '%approved routed file%' OR LOWER(action) LIKE '%rejected routed file%' THEN 'REVIEW'
        WHEN LOWER(action) LIKE '%share%' OR LOWER(action) LIKE '%route%' OR LOWER(action) LIKE '%routed%' THEN 'ROUTING'
        WHEN LOWER(action) LIKE '%document%' OR LOWER(action) LIKE '%version%' OR LOWER(action) LIKE '%spreadsheet%' OR LOWER(action) LIKE '%word%' OR LOWER(action) LIKE '%metadata%' OR LOWER(action) LIKE '%download%' OR LOWER(action) LIKE '%upload%' THEN 'DOCUMENT'
        ELSE 'SYSTEM'
      END
      WHERE category IS NULL OR category = '' OR category = 'SYSTEM'
    ");
  } catch (PDOException) {
    cddfts_ensure_audit_logs_table($pdo);
  }

  cddfts_set_meta_value($pdo, $backfillKey, '1');
}

function cddfts_add_index_if_missing(PDO $pdo, string $table, string $indexName, array $columns, bool $unique = false): void {
  if (!cddfts_table_exists($pdo, $table) || empty($columns)) {
    return;
  }

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND INDEX_NAME = ?
  ");
  $stmt->execute([$table, $indexName]);
  if ((int)$stmt->fetchColumn() > 0) {
    return;
  }

  $columnSql = implode(', ', array_map(
    static fn(string $column): string => $column,
    $columns
  ));
  $uniqueSql = $unique ? 'UNIQUE ' : '';
  $pdo->exec("ALTER TABLE {$table} ADD {$uniqueSql}INDEX {$indexName} ({$columnSql})");
}

function cddfts_add_fulltext_index_if_missing(PDO $pdo, string $table, string $indexName, array $columns): void {
  if (!cddfts_table_exists($pdo, $table) || empty($columns)) {
    return;
  }

  $stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND INDEX_NAME = ?
  ");
  $stmt->execute([$table, $indexName]);
  if ((int)$stmt->fetchColumn() > 0) {
    return;
  }

  $columnSql = implode(', ', array_map(
    static fn(string $column): string => $column,
    $columns
  ));
  $pdo->exec("ALTER TABLE {$table} ADD FULLTEXT INDEX {$indexName} ({$columnSql})");
}

function cddfts_document_fulltext_enabled(): bool {
  return cddfts_env_bool('DB_ENABLE_FULLTEXT', true);
}

function cddfts_unify_routed_storage(PDO $pdo): void {
  // Transitional workflow: keep one routed storage area underneath the app.
  $pdo->exec("UPDATE folders SET storage_area='OFFICIAL' WHERE storage_area <> 'OFFICIAL'");
  $pdo->exec("UPDATE documents SET storage_area='OFFICIAL' WHERE storage_area <> 'OFFICIAL'");
}

function cddfts_normalize_legacy_roles(PDO $pdo): void {
  $s = $pdo->query("SELECT id, role FROM users");
  foreach ($s->fetchAll() as $row) {
    $role = strtoupper((string)($row['role'] ?? ''));
    $normalized = match ($role) {
      'SUPER_ADMIN' => 'SUPER_ADMIN',
      'ADMIN' => 'SUPER_ADMIN',
      'SECTION_ADMIN' => 'SECTION_ADMIN',
      'DIVISION_CHIEF' => 'SECTION_ADMIN',
      'SECTION_STAFF' => 'SECTION_STAFF',
      'EMPLOYEE', 'USER' => 'SECTION_STAFF',
      default => 'SECTION_STAFF',
    };
    if ($normalized !== $role) {
      $u = $pdo->prepare("UPDATE users SET role=? WHERE id=?");
      $u->execute([$normalized, (int)$row['id']]);
    }
  }
}

function cddfts_ensure_user_role_storage(PDO $pdo): void {
  if (!cddfts_table_exists($pdo, 'users')) {
    return;
  }

  $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(40) NOT NULL DEFAULT 'SECTION_STAFF'");
}

function cddfts_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
  if (!cddfts_table_exists($pdo, $table)) {
    return;
  }

  $sql = "
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ";
  $s = $pdo->prepare($sql);
  $s->execute([$table, $column]);
  if ((int)$s->fetchColumn() === 0) {
    $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
  }
}

function cddfts_ensure_varchar_length(PDO $pdo, string $table, string $column, int $minLength, bool $nullable, ?string $default = null): void {
  if (!cddfts_table_exists($pdo, $table)) {
    return;
  }

  $sql = "
    SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH, IS_NULLABLE, COLUMN_DEFAULT
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ";
  $s = $pdo->prepare($sql);
  $s->execute([$table, $column]);
  $columnInfo = $s->fetch();
  if (!$columnInfo) {
    return;
  }

  $dataType = strtolower((string)($columnInfo['DATA_TYPE'] ?? ''));
  $currentLength = (int)($columnInfo['CHARACTER_MAXIMUM_LENGTH'] ?? 0);
  if ($dataType === 'varchar' && $currentLength >= $minLength) {
    return;
  }

  $nullSql = $nullable ? 'NULL' : 'NOT NULL';
  $defaultSql = $default !== null ? " DEFAULT '" . str_replace("'", "''", $default) . "'" : '';
  $pdo->exec("ALTER TABLE {$table} MODIFY COLUMN {$column} VARCHAR({$minLength}) {$nullSql}{$defaultSql}");
}
