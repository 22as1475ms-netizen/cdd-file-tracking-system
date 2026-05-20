<?php
require_once __DIR__ . '/TestCase.php';

class DocumentControllerRequestIdTest extends TestCase {
  public function testRequestDocumentIdPrefersPostedIdOverRequestData(): void {
    $_POST = ['id' => '42'];
    $_REQUEST = ['id' => '7'];

    require_once __DIR__ . '/../app/controllers/DocumentController.php';

    $this->assertSame(42, request_document_id());
  }

  public function testCompleteRouteRedirectReturnsToDocumentWhenCompletingRecipientKeepsReadOnlyAccess(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'redirect-check.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Redirect Check',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);
    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], $target, 'viewer');

    $this->actingAs(2);
    DocumentShareService::completeRouteLifecycle($this->pdo, Document::get($this->pdo, $docId), 2);

    require_once __DIR__ . '/../app/controllers/DocumentController.php';

    $this->assertSame('/documents/view?id=' . $docId . '&msg=route_lifecycle_completed&user_id=' . (int)$owner['id'], complete_route_redirect_path($this->pdo, $docId, 2, (int)$owner['id']));
  }
}
