<?php
require_once __DIR__ . '/TestCase.php';
require_once __DIR__ . '/../app/controllers/AdminController.php';
require_once __DIR__ . '/../app/models/Division.php';

class AdminDivisionVisibilityTest extends TestCase {
  public function testDivisionGroupsKeepEmptyDivisionsExceptRecordsDivision(): void {
    Division::create($this->pdo, 'PAMBCS');
    Division::create($this->pdo, 'Records Division');

    $divisions = Division::all($this->pdo);
    $groups = build_division_user_groups($this->pdo, $divisions, []);

    $names = array_map(
      static fn(array $group): string => (string)($group['division']['name'] ?? ''),
      (array)($groups['divisions'] ?? [])
    );

    $this->assertStringContains('PAMBCS', implode('|', $names), 'Regular empty divisions should stay visible.');
    $this->assertStringNotContains('Records Division', implode('|', $names), 'Records Division should stay hidden from the admin accounts groups.');
  }
}
