<?php
require_once __DIR__ . '/TestCase.php';

class NotificationRoutingRulesTest extends TestCase {
  public function testAdminReceivesOnlyAllowedRoutingNotifications(): void {
    Notification::add($this->pdo, 1, 'Shared document accepted', 'Should be suppressed', '/documents/view?id=5');
    Notification::add($this->pdo, 1, 'File shared to another user', 'Allowed', '/documents/view?id=5');

    $adminNotifications = Notification::recentAll($this->pdo, 1, 10);

    $this->assertCount(1, $adminNotifications);
    $this->assertSame('File shared to another user', (string)$adminNotifications[0]['title']);
  }
}
