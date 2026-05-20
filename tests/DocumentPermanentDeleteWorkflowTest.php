<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/controllers/DocumentController.php';
require_once __DIR__ . '/../app/models/Folder.php';
require_once __DIR__ . '/../app/models/Permission.php';
require_once __DIR__ . '/../app/models/Version.php';
require_once __DIR__ . '/../app/services/DocumentService.php';
require_once __DIR__ . '/../app/services/StorageService.php';

class DocumentPermanentDeleteWorkflowTest extends TestCase {
  public function testAdminDeletePasswordVerificationRequiresCorrectPassword(): void {
    $this->actingAs(1);

    $this->assertTrue(document_admin_reauth_ok($this->pdo, 'password'));
    $this->assertSame(false, document_admin_reauth_ok($this->pdo, 'wrong-password'));
  }

  public function testPermanentDeletePasswordVerificationRejectsNonSuperAdmin(): void {
    $adminId = User::create(
      $this->pdo,
      'Delete Admin',
      'delete-admin@cddfts.test',
      'ADMIN',
      'ACTIVE',
      User::hashPassword('password')
    );

    $this->actingAs($adminId);

    $this->assertSame(false, document_admin_reauth_ok($this->pdo, 'password'));
  }

  public function testPermanentDeletePurgesFinalizedDocumentAndRelatedRows(): void {
    $owner = $this->actingAs(3);
    $docId = DocumentService::createBlankEditableDocument(
      $this->pdo,
      (int)$owner['id'],
      'docx',
      (int)$owner['id'],
      'OFFICIAL',
      (int)$owner['division_id'],
      [
        'title' => 'Finalized Memo',
        'current_location' => 'Owner Desk',
        'routing_status' => 'AVAILABLE',
        'status' => 'Draft',
      ]
    );

    $version = Version::latest($this->pdo, $docId);
    $this->assertNotNull($version, 'Expected a version to exist before purge.');
    $filePath = DocumentService::absolutePathFromVersion((string)$version['file_path']);

    Permission::upsert($this->pdo, $docId, 2, 'viewer', (int)$owner['id']);
    Document::closeRoute($this->pdo, $docId, 'APPROVED');
    Document::updateTrackingState($this->pdo, $docId, 'Owner Desk', 'APPROVED');

    $deletedCount = purge_permanent_items($this->pdo, (int)$owner['id'], [], [$docId]);

    $this->assertSame(1, $deletedCount);
    $this->assertSame(null, Document::get($this->pdo, $docId));
    $this->assertSame(null, Version::latest($this->pdo, $docId));
    $this->assertSame(null, Permission::findRowForUser($this->pdo, $docId, 2));
    $this->assertTrue(!is_file($filePath), 'Expected the stored file to be removed after purge.');
  }

  public function testAdminPermanentDeletePurgesActiveDocumentWithoutTrashStep(): void {
    $admin = $this->actingAs(1);
    $docId = DocumentService::createBlankEditableDocument(
      $this->pdo,
      3,
      'docx',
      (int)$admin['id'],
      'OFFICIAL',
      1,
      [
        'title' => 'Queue Document',
        'current_location' => 'Routing Queue',
        'routing_status' => 'NOT_ROUTED',
        'status' => 'Draft',
      ]
    );

    $version = Version::latest($this->pdo, $docId);
    $this->assertNotNull($version, 'Expected an active document version before admin delete.');
    $filePath = DocumentService::absolutePathFromVersion((string)$version['file_path']);

    Permission::upsert($this->pdo, $docId, 2, 'viewer', (int)$admin['id']);

    $deletedCount = purge_trash_items($this->pdo, 3, [], [$docId]);

    $this->assertSame(1, $deletedCount);
    $this->assertSame(null, Document::get($this->pdo, $docId));
    $this->assertSame(null, Version::latest($this->pdo, $docId));
    $this->assertSame(null, Permission::findRowForUser($this->pdo, $docId, 2));
    $this->assertTrue(!is_file($filePath), 'Expected the stored file to be removed after direct admin delete.');
  }
}
