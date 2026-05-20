<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';

class DashboardRoutingPriorityTest extends TestCase {
  public function testRecipientDashboardKeepsRoutedDocumentPriorityLevel(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'rush-memo.pdf', 'OFFICIAL', (int)$owner['division_id'], [
      'document_code' => 'RUSH-001',
      'title' => 'Rush Memo',
      'signatory' => 'Admin',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
      'priority_level' => 'RUSH',
      'document_date' => '2026-05-13',
      'category' => 'Memorandum',
      'status' => 'Draft',
    ]);

    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], User::findById($this->pdo, 2), 'viewer');

    $inbox = dashboard_routed_documents_for_user($this->pdo, 2);

    $this->assertCount(1, $inbox);
    $this->assertSame('RUSH', (string)($inbox[0]['priority_level'] ?? ''));
  }

  public function testRoutedInboxQueryPaginatesResultsAndReportsTotal(): void {
    $owner = $this->actingAs(3);
    $target = User::findById($this->pdo, 2);

    for ($i = 1; $i <= 18; $i++) {
      $docId = Document::create($this->pdo, (int)$owner['id'], null, 'route-' . $i . '.pdf', 'OFFICIAL', (int)$owner['division_id'], [
        'document_code' => 'ROUTE-' . str_pad((string)$i, 3, '0', STR_PAD_LEFT),
        'title' => 'Route ' . $i,
        'signatory' => 'Section Admin',
        'current_location' => 'Admin',
        'routing_status' => 'AVAILABLE',
      ]);

      DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], $target, 'viewer');
    }

    [$pageRows, $total] = Document::listRoutedToUser($this->pdo, 2, (string)($target['name'] ?? ''), 2, 10);

    $this->assertSame(18, $total);
    $this->assertCount(8, $pageRows);
  }
}
