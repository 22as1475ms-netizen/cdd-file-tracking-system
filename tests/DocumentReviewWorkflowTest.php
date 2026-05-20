<?php
require_once __DIR__ . '/TestCase.php';

class DocumentReviewWorkflowTest extends TestCase {
  private function createReviewSection(int $memberId = 3, ?int $sectionChiefId = null): int {
    $sectionChiefId ??= User::create($this->pdo, 'Section Reviewer', 'section-reviewer@cddfts.test', 'EMPLOYEE', 'ACTIVE', password_hash('password', PASSWORD_DEFAULT), 1);
    $this->pdo->prepare("INSERT INTO sections (organization_id, name, description, chief_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
      ->execute([1, 'Records Review', 'Review section', $sectionChiefId, 1]);
    $sectionId = (int)$this->pdo->lastInsertId();
    TeamMember::addMember($this->pdo, $sectionId, $sectionChiefId, 'SECTION_CHIEF', 1);
    TeamMember::addMember($this->pdo, $sectionId, $memberId, 'MEMBER', 1);
    return $sectionChiefId;
  }

  public function testSubmitForReviewUpdatesDocumentAndCreatesQueueArtifacts(): void {
    $owner = $this->actingAs(3);
    $sectionChiefId = $this->createReviewSection((int)$owner['id']);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);
    $doc = Document::get($this->pdo, $docId);

    $result = DocumentReviewService::submitForReview($this->pdo, $doc, (int)$owner['id']);

    $updatedDoc = Document::get($this->pdo, $docId);
    $routes = DocumentRoute::listForDocument($this->pdo, $docId);
    $notifications = Notification::recentAll($this->pdo, $sectionChiefId, 5);
    $auditLogs = AuditLog::recentForUser($this->pdo, (int)$owner['id'], 5);

    $this->assertSame($docId, (int)$result['document_id']);
    $this->assertSame($sectionChiefId, (int)$result['chief_user_id']);
    $this->assertSame('To be reviewed', (string)$updatedDoc['status']);
    $this->assertSame('PENDING_REVIEW_ACCEPTANCE', (string)$updatedDoc['routing_status']);
    $this->assertSame('PENDING', (string)$updatedDoc['review_acceptance_status']);
    $this->assertSame('SECTION_REVIEW', (string)$updatedDoc['review_stage']);
    $this->assertSame($sectionChiefId, (int)$updatedDoc['assigned_reviewer_id']);
    $this->assertCount(1, $routes);
    $this->assertSame('Routed file awaiting section review', (string)$notifications[0]['title']);
    $this->assertSame('Submitted routed file for section review', (string)$auditLogs[0]['action']);
  }

  public function testSubmitForReviewRequiresAssignedSectionChief(): void {
    $owner = $this->actingAs(3);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);

    $this->expectExceptionMessage('section_chief_required', function () use ($docId, $owner): void {
      DocumentReviewService::submitForReview($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id']);
    });
  }

  public function testSubmitForReviewRejectsNonOwnerWithoutAdminRole(): void {
    $owner = $this->actingAs(3);
    $this->createReviewSection((int)$owner['id']);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);
    $this->actingAs(2);

    $this->expectExceptionMessage('forbidden', function () use ($docId): void {
      DocumentReviewService::submitForReview($this->pdo, Document::get($this->pdo, $docId), 2);
    });
  }

  public function testSubmitForReviewRejectsLockedDocument(): void {
    $owner = $this->actingAs(3);
    $this->createReviewSection((int)$owner['id']);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);
    $this->pdo->prepare('UPDATE documents SET approval_locked = 1 WHERE id = ?')->execute([$docId]);

    $this->expectExceptionMessage('approval_locked', function () use ($docId, $owner): void {
      DocumentReviewService::submitForReview($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id']);
    });
  }

  public function testFinalizeReviewApprovalLocksDocumentAndLogsDecision(): void {
    $owner = $this->actingAs(3);
    $sectionChiefId = $this->createReviewSection((int)$owner['id']);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);
    DocumentReviewService::submitForReview($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id']);
    Document::acceptReviewAssignment($this->pdo, $docId);
    $this->actingAs($sectionChiefId);

    $result = DocumentReviewService::finalizeDecision($this->pdo, Document::get($this->pdo, $docId), $sectionChiefId, 'APPROVED');

    $updatedDoc = Document::get($this->pdo, $docId);
    $reviews = DocumentReview::listForDocument($this->pdo, $docId);
    $notifications = Notification::recentAll($this->pdo, 3, 5);

    $this->assertSame('APPROVED', (string)$result['decision']);
    $this->assertSame('Approved', (string)$updatedDoc['status']);
    $this->assertSame('APPROVED', (string)$updatedDoc['routing_status']);
    $this->assertSame(1, (int)$updatedDoc['approval_locked']);
    $this->assertSame('FINAL', (string)$updatedDoc['review_stage']);
    $this->assertCount(1, $reviews);
    $this->assertSame('Routed file approved', (string)$notifications[0]['title']);
  }

  public function testSectionChiefCanEscalateToDivisionChiefForFinalReview(): void {
    $owner = $this->actingAs(3);
    $sectionChiefId = $this->createReviewSection((int)$owner['id']);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);
    DocumentReviewService::submitForReview($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id']);
    Document::acceptReviewAssignment($this->pdo, $docId);
    $this->actingAs($sectionChiefId);

    $result = DocumentReviewService::escalateToDivisionChief($this->pdo, Document::get($this->pdo, $docId), $sectionChiefId, 'Needs division approval');

    $updatedDoc = Document::get($this->pdo, $docId);
    $this->assertSame(2, (int)$result['division_chief_id']);
    $this->assertSame('DIVISION_REVIEW', (string)$updatedDoc['review_stage']);
    $this->assertSame(2, (int)$updatedDoc['assigned_reviewer_id']);
    $this->assertSame('PENDING', (string)$updatedDoc['review_acceptance_status']);
    $this->assertSame('PENDING_REVIEW_ACCEPTANCE', (string)$updatedDoc['routing_status']);
  }

  public function testFinalizeReviewRejectRequiresReason(): void {
    $owner = $this->actingAs(3);
    $sectionChiefId = $this->createReviewSection((int)$owner['id']);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);
    DocumentReviewService::submitForReview($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id']);
    Document::acceptReviewAssignment($this->pdo, $docId);
    $this->actingAs($sectionChiefId);

    $this->expectExceptionMessage('reject_note_required', function () use ($docId, $sectionChiefId): void {
      DocumentReviewService::finalizeDecision($this->pdo, Document::get($this->pdo, $docId), $sectionChiefId, 'REJECTED', '');
    });
  }

  public function testFinalizeReviewRejectsInvalidDecision(): void {
    $owner = $this->actingAs(3);
    $sectionChiefId = $this->createReviewSection((int)$owner['id']);
    $docId = Document::create($this->pdo, (int)$owner['id'], null, 'memo.docx', 'OFFICIAL', (int)$owner['division_id'], [
      'title' => 'Policy Memo',
      'current_location' => 'Owner Desk',
      'routing_status' => 'AVAILABLE',
      'status' => 'Draft',
    ]);
    DocumentReviewService::submitForReview($this->pdo, Document::get($this->pdo, $docId), (int)$owner['id']);
    Document::acceptReviewAssignment($this->pdo, $docId);
    $this->actingAs($sectionChiefId);

    $this->expectExceptionMessage('decision_invalid', function () use ($docId, $sectionChiefId): void {
      DocumentReviewService::finalizeDecision($this->pdo, Document::get($this->pdo, $docId), $sectionChiefId, 'MAYBE');
    });
  }
}
