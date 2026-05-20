<?php
require_once __DIR__ . '/TestCase.php';

class AuditLogCategoryTest extends TestCase {
  public function testAuditLogInfersCategoriesFromActions(): void {
    AuditLog::add($this->pdo, 1, 'Logged in');
    AuditLog::add($this->pdo, 1, 'Created division', null, 'division_id=2');
    AuditLog::add($this->pdo, 1, 'Uploaded document', 5, 'sample.pdf');
    AuditLog::add($this->pdo, 1, 'Shared document', 5, 'to=user@example.com');
    AuditLog::add($this->pdo, 1, 'Approved routed file', 5, null);

    $logs = AuditLog::allForUserWithUser($this->pdo, 1);
    $byAction = [];
    foreach ($logs as $log) {
      $byAction[(string)$log['action']] = (string)($log['category'] ?? '');
    }

    $this->assertSame('AUTH', $byAction['Logged in'] ?? '');
    $this->assertSame('ACCOUNT', $byAction['Created division'] ?? '');
    $this->assertSame('DOCUMENT', $byAction['Uploaded document'] ?? '');
    $this->assertSame('ROUTING', $byAction['Shared document'] ?? '');
    $this->assertSame('REVIEW', $byAction['Approved routed file'] ?? '');
  }

  public function testAuditLogCanFilterByCategory(): void {
    AuditLog::add($this->pdo, 1, 'Uploaded document', 1, null);
    AuditLog::add($this->pdo, 1, 'Shared document', 1, null);
    AuditLog::add($this->pdo, 1, 'Changed password', null, null);

    $documentLogs = AuditLog::allForUserWithUser($this->pdo, 1, 'DOCUMENT');
    $routingLogs = AuditLog::allForUserWithUser($this->pdo, 1, 'ROUTING');
    $authLogs = AuditLog::allForUserWithUser($this->pdo, 1, 'AUTH');

    $this->assertCount(1, $documentLogs);
    $this->assertCount(1, $routingLogs);
    $this->assertCount(1, $authLogs);
    $this->assertSame('Uploaded document', (string)$documentLogs[0]['action']);
    $this->assertSame('Shared document', (string)$routingLogs[0]['action']);
    $this->assertSame('Changed password', (string)$authLogs[0]['action']);
  }

  public function testBackfillStoresCategoriesForLegacyAuditRows(): void {
    $statement = $this->pdo->prepare("
      INSERT INTO audit_logs(user_id, category, action, document_id, meta)
      VALUES (?, ?, ?, ?, ?)
    ");
    $statement->execute([1, 'SYSTEM', 'Logged in', null, null]);
    $statement->execute([1, 'SYSTEM', 'Created division', null, 'division_id=2']);
    $statement->execute([1, 'SYSTEM', 'Shared document', 3, 'to=user@example.com']);

    $this->pdo->prepare("DELETE FROM app_meta WHERE meta_key = ?")->execute(['audit_log_categories_backfilled_v1']);
    cddfts_backfill_audit_log_categories($this->pdo);

    $logs = AuditLog::allForUserWithUser($this->pdo, 1);
    $byAction = [];
    foreach ($logs as $log) {
      $byAction[(string)$log['action']] = (string)($log['category'] ?? '');
    }

    $this->assertSame('AUTH', $byAction['Logged in'] ?? '');
    $this->assertSame('ACCOUNT', $byAction['Created division'] ?? '');
    $this->assertSame('ROUTING', $byAction['Shared document'] ?? '');
    $this->assertSame('1', cddfts_get_meta_value($this->pdo, 'audit_log_categories_backfilled_v1'));
  }
}
