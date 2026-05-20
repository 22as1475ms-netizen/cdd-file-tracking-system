<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/helpers/redirect.php';
require_once __DIR__ . '/../app/helpers/http.php';
require_once __DIR__ . '/../app/middleware/require_role.php';

class SuperAdminRoleMigrationTest extends TestCase {
  public function testSeededAdministratorIsSuperAdmin(): void {
    $admin = User::findById($this->pdo, 1);

    $this->assertSame('SUPER_ADMIN', (string)($admin['role'] ?? ''));
    $this->assertSame('CDD Super Admin', role_label((string)$admin['role']));
  }

  public function testSuperAdminUsesAdminWorkspaceHome(): void {
    $this->actingAs(1);

    $this->assertSame('/admin/dashboard', workspace_home_path());
  }

  public function testLegacyUserRolesNormalizeToSectionRoles(): void {
    $sectionAdminId = User::create($this->pdo, 'Legacy Chief', 'legacy-chief@cddfts.test', 'DIVISION_CHIEF', 'ACTIVE', password_hash('password', PASSWORD_DEFAULT), 1);
    $sectionStaffId = User::create($this->pdo, 'Legacy Staff', 'legacy-staff@cddfts.test', 'EMPLOYEE', 'ACTIVE', password_hash('password', PASSWORD_DEFAULT), 1);

    $this->assertSame('SECTION_ADMIN', (string)User::findById($this->pdo, $sectionAdminId)['role']);
    $this->assertSame('SECTION_STAFF', (string)User::findById($this->pdo, $sectionStaffId)['role']);
    $this->assertSame('Section Admin', role_label('SECTION_ADMIN'));
    $this->assertSame('Section Staff', role_label('SECTION_STAFF'));
  }

  public function testCreatedAccountCanUseGeneratedPassword(): void {
    $password = 'JordanM_2468';
    $userId = User::create($this->pdo, 'Generated Account', 'generated-account@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword($password), 1, $password);
    $user = User::findById($this->pdo, $userId);

    $this->assertTrue(password_verify($password, (string)($user['password'] ?? '')));
    $this->assertSame($password, (string)($user['generated_password'] ?? ''));
  }

  public function testDisplayCodesStayRoleBasedWithoutChangingPrimaryKeys(): void {
    $sectionAdminId = User::create($this->pdo, 'Display Admin', 'display-admin@cddfts.test', 'SECTION_ADMIN', 'ACTIVE', User::hashPassword('password'), 1);
    $staffOneId = User::create($this->pdo, 'Display Staff One', 'display-staff-one@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword('password'), 1);
    $staffTwoId = User::create($this->pdo, 'Display Staff Two', 'display-staff-two@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword('password'), 1);

    $users = User::attachDisplayCodes($this->pdo, [
      User::findById($this->pdo, 1),
      User::findById($this->pdo, $sectionAdminId),
      User::findById($this->pdo, $staffOneId),
      User::findById($this->pdo, $staffTwoId),
    ]);

    $byId = [];
    foreach ($users as $user) {
      $byId[(int)$user['id']] = (string)($user['display_code'] ?? '');
    }

    $this->assertSame('SUP-001', $byId[1] ?? '');
    $this->assertTrue(str_starts_with((string)($byId[$sectionAdminId] ?? ''), 'SEC-'));
    $this->assertTrue(str_starts_with((string)($byId[$staffOneId] ?? ''), 'STF-'));
    $this->assertTrue(str_starts_with((string)($byId[$staffTwoId] ?? ''), 'STF-'));
    $this->assertSame(
      ((int)substr((string)$byId[$staffOneId], 4)) + 1,
      (int)substr((string)$byId[$staffTwoId], 4)
    );
  }

  public function testMetaDetailsRenderUserReferencesAsDisplayCodes(): void {
    $staffId = User::create($this->pdo, 'Meta Staff', 'meta-staff@cddfts.test', 'SECTION_STAFF', 'ACTIVE', User::hashPassword('password'), 1);
    $parsed = parse_meta_details('user_id=' . $staffId);

    $this->assertCount(1, $parsed);
    $this->assertSame('User', (string)($parsed[0]['label'] ?? ''));
    $this->assertTrue(str_starts_with((string)($parsed[0]['value'] ?? ''), 'STF-'));
  }
}
