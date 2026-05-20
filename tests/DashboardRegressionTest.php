<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/helpers/view.php';
require_once __DIR__ . '/../app/helpers/redirect.php';
require_once __DIR__ . '/../app/helpers/http.php';
require_once __DIR__ . '/../app/controllers/DashboardController.php';
require_once __DIR__ . '/../app/controllers/AdminController.php';

class DashboardRegressionTest extends TestCase {
  public function testUserDashboardClampsOutOfRangePageAndStillShowsRoutedFiles(): void {
    $owner = $this->actingAs(3);
    $target = User::findById($this->pdo, 2);

    for ($i = 1; $i <= 18; $i++) {
      $docId = Document::create($this->pdo, (int)$owner['id'], null, 'route-' . $i . '.pdf', 'OFFICIAL', (int)$owner['division_id'], [
        'title' => 'Route ' . $i,
        'current_location' => 'Admin',
        'routing_status' => 'AVAILABLE',
      ]);
      DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], $target, 'viewer');
    }

    $this->actingAs(2);
    $_GET['page'] = 999;
    $_REQUEST['page'] = 999;
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    user_dashboard();
    $html = (string)ob_get_clean();

    $this->assertStringContains('Route 3', $html, 'Expected the last dashboard page to render older routed rows.');
    $this->assertStringNotContains('No routed files are assigned to you right now.', $html, 'Out-of-range pages should clamp instead of showing an empty inbox.');
  }

  public function testAdminDashboardFooterCountsUseFullFilteredQueueNotCurrentPageOnly(): void {
    $admin = $this->actingAs(1);

    for ($i = 1; $i <= 16; $i++) {
      Document::create($this->pdo, (int)$admin['id'], null, 'queue-' . $i . '.pdf', 'OFFICIAL', (int)$admin['division_id'], [
        'title' => 'Queue ' . $i,
        'current_location' => 'Admin',
        'routing_status' => 'AVAILABLE',
      ]);
    }

    Document::create($this->pdo, (int)$admin['id'], null, 'completed.pdf', 'OFFICIAL', (int)$admin['division_id'], [
      'title' => 'Completed Queue Item',
      'current_location' => 'Admin',
      'routing_status' => 'COMPLETED',
    ]);

    $_GET['page'] = 2;
    $_REQUEST['page'] = 2;
    $_SERVER['REQUEST_METHOD'] = 'GET';

    ob_start();
    admin_dashboard();
    $html = (string)ob_get_clean();

    $this->assertStringContains('<strong>16</strong>', $html, 'Waiting count should reflect the full filtered queue.');
    $this->assertStringContains('<strong>17</strong>', $html, 'Total count should reflect the full filtered queue.');
  }

  public function testDocumentSearchFallsBackWhenFulltextIndexIsMissing(): void {
    $owner = $this->actingAs(3);
    Document::create($this->pdo, (int)$owner['id'], null, 'memo-search.pdf', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Searchable Memo',
      'document_code' => 'SEARCH-001',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $this->pdo->exec("ALTER TABLE documents DROP INDEX ft_documents_search");

    $rows = Document::searchActiveForOwnerInStorage($this->pdo, (int)$owner['id'], 'OFFICIAL', 'SEARCH-001', 10);

    $this->assertCount(1, $rows);
    $this->assertSame('SEARCH-001', (string)($rows[0]['document_code'] ?? ''));
  }

  public function testDocumentSearchFallsBackWhenFulltextIsDisabledByConfig(): void {
    $previous = getenv('DB_ENABLE_FULLTEXT');
    putenv('DB_ENABLE_FULLTEXT=0');
    $_ENV['DB_ENABLE_FULLTEXT'] = '0';
    $_SERVER['DB_ENABLE_FULLTEXT'] = '0';

    try {
      $owner = $this->actingAs(3);
      Document::create($this->pdo, (int)$owner['id'], null, 'memo-tidb.pdf', 'OFFICIAL', (int)$owner['division_id'], [
        'title' => 'TiDB Search Memo',
        'document_code' => 'TIDB-001',
        'current_location' => 'Admin',
        'routing_status' => 'AVAILABLE',
      ]);

      $rows = Document::searchActiveForOwnerInStorage($this->pdo, (int)$owner['id'], 'OFFICIAL', 'TIDB-001', 10);

      $this->assertCount(1, $rows);
      $this->assertSame('TIDB-001', (string)($rows[0]['document_code'] ?? ''));
    } finally {
      if ($previous === false) {
        putenv('DB_ENABLE_FULLTEXT');
        unset($_ENV['DB_ENABLE_FULLTEXT'], $_SERVER['DB_ENABLE_FULLTEXT']);
      } else {
        putenv('DB_ENABLE_FULLTEXT=' . $previous);
        $_ENV['DB_ENABLE_FULLTEXT'] = $previous;
        $_SERVER['DB_ENABLE_FULLTEXT'] = $previous;
      }
    }
  }
}
