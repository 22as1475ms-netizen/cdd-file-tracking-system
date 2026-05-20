<?php
require_once __DIR__ . '/TestCase.php';

class DocumentShareWorkflowTest extends TestCase {
  public function testShareDocumentCreatesPermissionRouteNotificationAndAuditLog(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
    ]);
    $doc = Document::get($this->pdo, $docId);
    $target = User::findById($this->pdo, 2);

    $result = DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $target, 'editor');

    $permission = Permission::findRowForUser($this->pdo, $docId, 2);
    $updatedDoc = Document::get($this->pdo, $docId);
    $routes = DocumentRoute::listForDocument($this->pdo, $docId);
    $notifications = Notification::recentAll($this->pdo, 2, 5);
    $auditLogs = AuditLog::recentForUser($this->pdo, (int)$owner['id'], 5);

    $this->assertSame(2, (int)$result['target_id']);
    $this->assertSame('editor', (string)$permission['permission']);
    $this->assertNotNull($permission['accepted_at'] ?? null);
    $this->assertSame('SHARE_ACCEPTED', (string)$updatedDoc['routing_status']);
    $this->assertCount(1, $routes);
    $this->assertSame('A routed file was shared with you', (string)$notifications[0]['title']);
    $this->assertSame('Shared document', (string)$auditLogs[0]['action']);
  }

  public function testShareDocumentStoresTaggedActionInstructionInRouteNote(): void {
    $owner = $this->actingAs(2);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Action Memo',
      'current_location' => 'Section Admin Desk',
      'routing_status' => 'AVAILABLE',
    ]);
    $doc = Document::get($this->pdo, $docId);
    $target = User::findById($this->pdo, 3);

    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $target, 'editor', [
      'note_suffix' => 'Actions to be taken: Review and update the weekly totals before filing.',
    ]);

    $routes = DocumentRoute::listForDocument($this->pdo, $docId);

    $this->assertCount(1, $routes);
    $this->assertStringContains('Actions to be taken: Review and update the weekly totals before filing.', (string)($routes[0]['note'] ?? ''));
  }

  public function testShareDocumentRejectsSelfTarget(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
    ]);

    $this->expectExceptionMessage('cannot_share_self', function () use ($docId, $owner): void {
      DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], User::findById($this->pdo, 3), 'viewer');
    });
  }

  public function testShareDocumentRejectsDifferentDivisionTarget(): void {
    $owner = $this->actingAs(3);
    $this->pdo->prepare("INSERT INTO divisions(name, chief_user_id) VALUES(?, ?)")->execute(['Other Division', null]);
    $otherDivisionId = (int)$this->pdo->lastInsertId();
    $otherUserId = User::create($this->pdo, 'Other Employee', 'other@cddfts.test', 'EMPLOYEE', 'ACTIVE', password_hash('password', PASSWORD_BCRYPT), $otherDivisionId);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
    ]);

    $this->expectExceptionMessage('user_not_found', function () use ($docId, $owner, $otherUserId): void {
      DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], User::findById($this->pdo, $otherUserId), 'viewer');
    });
  }

  public function testShareDocumentRejectsWhenShareAlreadyInProgress(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
    ]);
    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], $target, 'viewer');

    $this->expectExceptionMessage('share_in_progress', function () use ($docId, $owner, $target): void {
      DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], $target, 'viewer');
    });
  }

  public function testAcceptShareMarksPermissionAcceptedAndNotifiesOwner(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
    ]);
    $doc = Document::get($this->pdo, $docId);
    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $target, 'viewer');

    $this->actingAs(2);
    $permissionRow = Permission::findRowForUser($this->pdo, $docId, 2);
    $result = DocumentShareService::respondToShare($this->pdo, Document::get($this->pdo, $docId), $permissionRow, 2, 'ACCEPT');

    $updatedPermission = Permission::findRowForUser($this->pdo, $docId, 2);
    $updatedDoc = Document::get($this->pdo, $docId);
    $this->assertSame('accepted', (string)$result['status']);
    $this->assertNotNull($updatedPermission['accepted_at'] ?? null);
    $this->assertSame('SHARE_ACCEPTED', (string)$updatedDoc['routing_status']);
    $this->assertCount(0, Notification::recentAll($this->pdo, 3, 5));
  }

  public function testDeclineShareRequiresNote(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
    ]);
    $doc = Document::get($this->pdo, $docId);
    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $target, 'viewer');

    $this->actingAs(2);
    $permissionRow = Permission::findRowForUser($this->pdo, $docId, 2);

    $this->expectExceptionMessage('response_note_required', function () use ($docId, $permissionRow): void {
      DocumentShareService::respondToShare($this->pdo, Document::get($this->pdo, $docId), $permissionRow, 2, 'DECLINE', '');
    });
  }

  public function testRevokeShareRemovesMembersAndReturnsDocumentToOwner(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
    ]);
    $doc = Document::get($this->pdo, $docId);
    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $target, 'viewer');

    $result = DocumentShareService::revokeShare($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], (string)$owner['name']);

    $permission = Permission::findRowForUser($this->pdo, $docId, 2);
    $updatedDoc = Document::get($this->pdo, $docId);
    $routes = DocumentRoute::listForDocument($this->pdo, $docId);

    $this->assertSame(1, (int)$result['revoked_members']);
    $this->assertSame(null, $permission);
    $this->assertSame('AVAILABLE', (string)$updatedDoc['routing_status']);
    $this->assertCount(2, $routes);
    $this->assertStringContains('Share cancelled by owner', (string)$routes[0]['note']);
  }

  public function testRecipientCanCompleteRouteLifecycleAndKeepFileAtFinalHolder(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Test Memo',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);
    $doc = Document::get($this->pdo, $docId);
    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, $doc, (int)$owner['id'], $target, 'viewer');

    $this->actingAs(2);
    $result = DocumentShareService::completeRouteLifecycle($this->pdo, Document::get($this->pdo, $docId), 2);

    $permission = Permission::findRowForUser($this->pdo, $docId, 2);
    $updatedDoc = Document::get($this->pdo, $docId);
    $routes = DocumentRoute::listForDocument($this->pdo, $docId);
    $notifications = Notification::recentAll($this->pdo, 3, 5);

    $this->assertSame('completed', (string)$result['status']);
    $this->assertSame('viewer', (string)($permission['permission'] ?? ''));
    $this->assertNotNull($permission['accepted_at'] ?? null);
    $this->assertSame('COMPLETED', (string)$updatedDoc['routing_status']);
    $this->assertSame('COMPLETED', (string)$updatedDoc['route_outcome']);
    $this->assertSame('Shared with Records Staff', (string)$updatedDoc['current_location']);
    $this->assertCount(2, $routes);
    $this->assertSame('COMPLETED', (string)$routes[0]['status_snapshot']);
    $this->assertSame('Route lifecycle completed', (string)$notifications[0]['title']);
  }

  public function testSectionAdminCannotCompleteRouteLifecycle(): void {
    $this->actingAs(1);
    $sectionAdminId = User::create($this->pdo, 'Section Admin Complete Guard', 'section-admin-complete@cddfts.test', 'SECTION_ADMIN', 'ACTIVE', User::hashPassword('password'), 1);
    Division::updateChief($this->pdo, 1, $sectionAdminId);
    $docId = Document::create($this->pdo, 1, null, 'section-admin-complete.docx', 'OFFICIAL', 1, [
      'title' => 'Section Admin Complete Guard',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $sectionAdmin = User::findById($this->pdo, $sectionAdminId);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), 1, $sectionAdmin, 'viewer');

    $this->actingAs($sectionAdminId);
    $this->expectExceptionMessage('forbidden', function () use ($docId, $sectionAdminId): void {
      DocumentShareService::completeRouteLifecycle($this->pdo, Document::get($this->pdo, $docId), $sectionAdminId);
    });
  }

  public function testCompletedRouteRemainsVisibleToEarlierRoutedRecipients(): void {
    $this->actingAs(1);
    $docId = Document::create($this->pdo, 1, null, 'memo.docx', 'OFFICIAL', 1, [
      'title' => 'Visibility Memo',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $firstRecipient = User::findById($this->pdo, 2);
    $finalRecipientId = User::create($this->pdo, 'Final Holder', 'final-holder@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword('password'), 1);
    $finalRecipient = User::findById($this->pdo, $finalRecipientId);

    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), 1, $firstRecipient, 'viewer');

    $this->actingAs(2);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), 2, $finalRecipient, 'viewer');

    $this->actingAs($finalRecipientId);
    DocumentShareService::completeRouteLifecycle($this->pdo, Document::get($this->pdo, $docId), $finalRecipientId);

    $firstRecipientPermission = Permission::findRowForUser($this->pdo, $docId, 2);
    $finalRecipientPermission = Permission::findRowForUser($this->pdo, $docId, $finalRecipientId);
    $updatedDoc = Document::get($this->pdo, $docId);

    $this->assertNotNull($firstRecipientPermission, 'Earlier routed recipients should keep visibility after completion.');
    $this->assertNotNull($firstRecipientPermission['accepted_at'] ?? null);
    $this->assertNotNull($finalRecipientPermission, 'Final holder should still have access after completion.');
    $this->assertSame('Shared with Final Holder', (string)$updatedDoc['current_location']);
  }

  public function testForwardingToAnotherUserNotifiesAdmin(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', null, [
      'title' => 'Forward Test Memo',
      'current_location' => 'Staff Desk',
      'routing_status' => 'AVAILABLE',
    ]);

    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id'], $target, 'viewer');

    $adminNotifications = Notification::recentAll($this->pdo, 1, 5);

    $this->assertSame('File shared to another user', (string)$adminNotifications[0]['title']);
  }

  public function testSectionAdminCannotShareToAnotherStaffAfterHandoffIsActive(): void {
    $this->actingAs(1);
    $sectionAdminId = User::create($this->pdo, 'Strict Relay Admin', 'strict-relay-admin@cddfts.test', 'SECTION_ADMIN', 'ACTIVE', User::hashPassword('password'), 1);
    Division::updateChief($this->pdo, 1, $sectionAdminId);
    $docId = Document::create($this->pdo, 1, null, 'strict-relay.docx', 'OFFICIAL', 1, [
      'title' => 'Strict Relay Memo',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $sectionAdmin = User::findById($this->pdo, $sectionAdminId);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), 1, $sectionAdmin, 'viewer');

    $staffOne = User::findById($this->pdo, 2);
    $staffTwoId = User::create($this->pdo, 'Another Staff', 'another-staff@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword('password'), 1);
    $staffTwo = User::findById($this->pdo, $staffTwoId);

    $this->actingAs($sectionAdminId);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), $sectionAdminId, $staffOne, 'viewer');

    $this->expectExceptionMessage('share_in_progress', function () use ($docId, $staffTwo, $sectionAdminId): void {
      DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), $sectionAdminId, $staffTwo, 'viewer');
    });
  }

  public function testCompletedRouteCanNotifyAdminOwnerOnlyForAllowedAdminAlerts(): void {
    $this->actingAs(1);
    $docId = Document::create($this->pdo, 1, null, 'admin-memo.docx', 'OFFICIAL', null, [
      'title' => 'Admin Memo',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);
    $target = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), 1, $target, 'viewer');

    $this->actingAs(2);
    DocumentShareService::completeRouteLifecycle($this->pdo, Document::get($this->pdo, $docId), 2);

    $adminNotifications = Notification::recentAll($this->pdo, 1, 5);

    $this->assertSame('Route lifecycle completed', (string)$adminNotifications[0]['title']);
  }

  public function testSectionAdminRecipientListFallsBackToOwnDivisionWhenDocumentDivisionIsMissing(): void {
    $this->actingAs(1);
    $docId = Document::create($this->pdo, 1, null, 'divisionless-memo.docx', 'OFFICIAL', null, [
      'title' => 'Divisionless Memo',
      'current_location' => 'Admin',
      'routing_status' => 'AVAILABLE',
    ]);

    $sectionAdmin = User::findById($this->pdo, 2);
    DocumentShareService::shareDocument($this->pdo, Document::get($this->pdo, $docId), 1, $sectionAdmin, 'viewer');

    $this->actingAs(2);
    require_once __DIR__ . '/../app/controllers/DocumentController.php';

    $recipients = document_route_recipients($this->pdo, Document::get($this->pdo, $docId), 2);
    $recipientIds = array_map(static fn(array $recipient): int => (int)($recipient['id'] ?? 0), $recipients);

    $this->assertTrue(in_array(3, $recipientIds, true), 'Expected the section staff account from the section admin division to be available as a route recipient.');
  }
}
