<?php
require_once __DIR__ . '/TestCase.php';

class AvailabilityAndDelegationTest extends TestCase {
  public function testSetAvailabilityPersistsStatusAndNote(): void {
    User::setAvailability($this->pdo, 3, 'busy', 'In a meeting');

    $user = User::findById($this->pdo, 3);

    $this->assertSame('BUSY', (string)($user['availability_status'] ?? ''));
    $this->assertSame('In a meeting', (string)($user['availability_note'] ?? ''));
  }

  public function testUpdateMemberStoresDelegateSettings(): void {
    $owner = $this->actingAs(3);
    $this->pdo->prepare("INSERT INTO sections (organization_id, name, description, chief_id, created_by, created_at, updated_at) VALUES (?, ?, ?, ?, ?, NOW(), NOW())")
      ->execute([(int)($owner['division_id'] ?? 1), 'Delegation Section', 'Test section', null, (int)$owner['id']]);
    $sectionId = (int)$this->pdo->lastInsertId();

    $delegateId = User::create($this->pdo, 'Delegate Approver', 'delegate-approver@cddfts.test', 'EMPLOYEE', 'ACTIVE', password_hash('password', PASSWORD_DEFAULT), (int)($owner['division_id'] ?? 1));
    TeamMember::addMember($this->pdo, $sectionId, (int)$owner['id'], 'MEMBER', (int)$owner['id']);
    TeamMember::addMember($this->pdo, $sectionId, $delegateId, 'MEMBER', (int)$owner['id']);

    TeamMember::updateMember($this->pdo, $sectionId, (int)$owner['id'], 'TEAM_LEAD', $delegateId, 'Backup reviewer');

    $members = TeamMember::findBySection($this->pdo, $sectionId);
    $updatedMember = null;
    foreach ($members as $member) {
      if ((int)$member['user_id'] === (int)$owner['id']) {
        $updatedMember = $member;
        break;
      }
    }

    $this->assertNotNull($updatedMember, 'Expected the section member to be present.');
    $this->assertSame('TEAM_LEAD', (string)($updatedMember['role'] ?? ''));
    $this->assertSame($delegateId, (int)($updatedMember['delegate_user_id'] ?? 0));
    $this->assertSame('Backup reviewer', (string)($updatedMember['delegate_note'] ?? ''));
    $this->assertSame('Delegate Approver', (string)($updatedMember['delegate_name'] ?? ''));
  }
}
