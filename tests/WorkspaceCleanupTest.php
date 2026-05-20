<?php
require_once __DIR__ . '/TestCase.php';

class WorkspaceCleanupTest extends TestCase {
  public function testRetiredMessagingFilesAndRoutesAreGone(): void {
    $this->assertSame(false, file_exists(__DIR__ . '/../app/models/ChatMessage.php'));
    $this->assertSame(false, file_exists(__DIR__ . '/../app/models/DocumentMessage.php'));

    $apiController = (string)file_get_contents(__DIR__ . '/../app/controllers/ApiController.php');
    $footerView = (string)file_get_contents(__DIR__ . '/../app/views/layouts/footer.php');
    $databaseConfig = (string)file_get_contents(__DIR__ . '/../app/config/database.php');

    $this->assertSame(false, str_contains($apiController, '/api/chat/'));
    $this->assertSame(false, str_contains($databaseConfig, 'document_messages'));
    $this->assertSame(false, str_contains($footerView, 'global-chat'));
  }
}
