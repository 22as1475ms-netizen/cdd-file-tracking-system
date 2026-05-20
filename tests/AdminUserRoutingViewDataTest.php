<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/controllers/AdminController.php';

class AdminUserRoutingViewDataTest extends TestCase {
  public function testWorkspaceGroupsShowDocumentsRoutedToRecipientInsteadOfOwnedDocuments(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'routing-memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Routing Memo',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $doc = Document::get($this->pdo, $docId);
    $recipient = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $recipient, 'viewer');

    $groups = build_user_workspace_groups($this->pdo, User::allNonAdmins($this->pdo));
    $groupsByUserId = [];
    foreach ($groups as $group) {
      $groupsByUserId[(int)$group['user']['id']] = $group;
    }

    $recipientGroup = $groupsByUserId[2] ?? null;
    $ownerGroup = $groupsByUserId[3] ?? null;

    $this->assertNotNull($recipientGroup, 'Recipient group should be available.');
    $this->assertNotNull($ownerGroup, 'Owner group should be available.');
    $this->assertCount(1, (array)($recipientGroup['allDocuments'] ?? []));
    $this->assertSame($docId, (int)($recipientGroup['allDocuments'][0]['id'] ?? 0));
    $this->assertSame('ROUTED', (string)($recipientGroup['allDocuments'][0]['route_state'] ?? ''));
    $this->assertSame(1, (int)($recipientGroup['summary']['document_count'] ?? 0));
    $this->assertSame(0, (int)($ownerGroup['summary']['document_count'] ?? 0));
  }

  public function testWorkspaceGroupsKeepCompletedRouteHistoryForRecipient(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'routing-memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Routing Memo',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $doc = Document::get($this->pdo, $docId);
    $recipient = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $recipient, 'viewer');

    $this->actingAs(2);
    DocumentShareService::completeRouteLifecycle($this->pdo, Document::get($this->pdo, $docId), 2);

    $groups = build_user_workspace_groups($this->pdo, User::allNonAdmins($this->pdo));
    $groupsByUserId = [];
    foreach ($groups as $group) {
      $groupsByUserId[(int)$group['user']['id']] = $group;
    }

    $recipientGroup = $groupsByUserId[2] ?? null;

    $this->assertNotNull($recipientGroup, 'Recipient group should still be available.');
    $this->assertCount(1, (array)($recipientGroup['allDocuments'] ?? []));
    $this->assertSame('COMPLETED', (string)($recipientGroup['allDocuments'][0]['route_state'] ?? ''));
    $this->assertSame(1, (int)($recipientGroup['summary']['completed_count'] ?? 0));
    $this->assertSame(0, (int)($recipientGroup['summary']['active_count'] ?? -1));
  }

  public function testRoutedWorkspacePreservesSuperAdminDocumentMetadataForSectionAdminView(): void {
    $owner = $this->actingAs(1);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'weekly-report.pdf', 'OFFICIAL', 1, [
      'document_code' => 'DOC-2026-0514',
      'title' => 'Weekly Accomplishment Report',
      'signatory' => 'Administrator',
      'document_date' => '2026-05-14',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $doc = Document::get($this->pdo, $docId);
    $sectionAdmin = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $sectionAdmin, 'viewer');

    $documents = admin_list_documents_routed_to_user($this->pdo, $sectionAdmin);

    $this->assertCount(1, $documents);
    $this->assertSame('DOC-2026-0514', (string)($documents[0]['document_code'] ?? ''));
    $this->assertSame('2026-05-14', (string)($documents[0]['document_date'] ?? ''));
    $this->assertSame('Administrator', (string)($documents[0]['signatory'] ?? ''));
    $this->assertSame('SUPER_ADMIN', (string)($documents[0]['owner_role'] ?? ''));
    $this->assertSame('', (string)($documents[0]['division_name'] ?? ''));
  }
}
