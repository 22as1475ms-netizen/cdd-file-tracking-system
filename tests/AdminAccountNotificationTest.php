<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/models/Division.php';
require_once __DIR__ . '/../app/controllers/AdminController.php';

class AdminAccountNotificationTest extends TestCase {
  public function testSectionAdminIsNotifiedWhenAccountIsCreatedInTheirSection(): void {
    $sectionAdminId = User::create($this->pdo, 'Section Admin', 'section-admin@cddfts.test', 'SECTION_ADMIN', 'ACTIVE', User::hashPassword('password'), 1);
    Division::updateChief($this->pdo, 1, $sectionAdminId);

    $newUserId = User::create($this->pdo, 'New Staff', 'new-staff@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword('password'), 1);
    admin_notify_account_created_under_section($this->pdo, $newUserId, 1);

    $notifications = Notification::recentAll($this->pdo, $sectionAdminId, 5);
    $this->assertCount(1, $notifications);
    $this->assertSame('New account created in your section', (string)($notifications[0]['title'] ?? ''));
    $this->assertStringContains('New Staff', (string)($notifications[0]['body'] ?? ''));
    $this->assertSame('/admin/users?user_id=' . $newUserId, (string)($notifications[0]['link'] ?? ''));
  }

  public function testSectionAssignmentChangeNotifiesAffectedUserAndNewSectionAdmin(): void {
    $newDivisionId = Division::create($this->pdo, 'New Section');
    $oldSectionAdminId = User::create($this->pdo, 'Old Section Admin', 'old-section-admin@cddfts.test', 'SECTION_ADMIN', 'ACTIVE', User::hashPassword('password'), 1);
    $newSectionAdminId = User::create($this->pdo, 'New Section Admin', 'new-section-admin@cddfts.test', 'SECTION_ADMIN', 'ACTIVE', User::hashPassword('password'), $newDivisionId);
    Division::updateChief($this->pdo, 1, $oldSectionAdminId);
    Division::updateChief($this->pdo, $newDivisionId, $newSectionAdminId);

    $staffId = User::create($this->pdo, 'Moved Staff', 'moved-staff@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword('password'), 1);
    $before = User::findById($this->pdo, $staffId);

    User::setDivision($this->pdo, $staffId, $newDivisionId);
    $after = User::findById($this->pdo, $staffId);
    admin_notify_section_assignment_changed($this->pdo, $before, $after);

    $staffNotifications = Notification::recentAll($this->pdo, $staffId, 5);
    $adminNotifications = Notification::recentAll($this->pdo, $newSectionAdminId, 5);

    $this->assertCount(1, $staffNotifications);
    $this->assertSame('Section assignment changed', (string)($staffNotifications[0]['title'] ?? ''));
    $this->assertStringContains('changed from', (string)($staffNotifications[0]['body'] ?? ''));
    $this->assertSame('/dashboard', (string)($staffNotifications[0]['link'] ?? ''));

    $this->assertCount(1, $adminNotifications);
    $this->assertSame('Account assigned to your section', (string)($adminNotifications[0]['title'] ?? ''));
    $this->assertStringContains('Moved Staff', (string)($adminNotifications[0]['body'] ?? ''));
    $this->assertSame('/admin/users?user_id=' . $staffId, (string)($adminNotifications[0]['link'] ?? ''));
  }
}
