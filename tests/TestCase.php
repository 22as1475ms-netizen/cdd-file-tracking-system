<?php

abstract class TestCase {
  protected PDO $pdo;

  public function setUp(): void {
    cddfts_test_reset_database();
    $this->pdo = $GLOBALS['pdo'];
    $_SESSION = [];
  }

  protected function actingAs(int $userId): array {
    $user = User::findById($this->pdo, $userId);
    if (!$user) {
      throw new RuntimeException('User not found for test.');
    }

    $_SESSION['user'] = $user;
    return $user;
  }

  protected function assertTrue(bool $condition, string $message = 'Expected condition to be true.'): void {
    if (!$condition) {
      throw new RuntimeException($message);
    }
  }

  protected function assertSame(mixed $expected, mixed $actual, string $message = ''): void {
    if ($expected !== $actual) {
      $detail = $message !== '' ? $message . ' ' : '';
      throw new RuntimeException($detail . 'Expected ' . var_export($expected, true) . ', got ' . var_export($actual, true) . '.');
    }
  }

  protected function assertNotNull(mixed $value, string $message = 'Expected value to be present.'): void {
    if ($value === null) {
      throw new RuntimeException($message);
    }
  }

  protected function assertCount(int $expected, array $items, string $message = ''): void {
    $actual = count($items);
    if ($actual !== $expected) {
      $detail = $message !== '' ? $message . ' ' : '';
      throw new RuntimeException($detail . 'Expected count ' . $expected . ', got ' . $actual . '.');
    }
  }

  protected function assertStringContains(string $needle, string $haystack, string $message = ''): void {
    if (!str_contains($haystack, $needle)) {
      $detail = $message !== '' ? $message . ' ' : '';
      throw new RuntimeException($detail . 'Did not find "' . $needle . '" in "' . $haystack . '".');
    }
  }

  protected function assertStringNotContains(string $needle, string $haystack, string $message = ''): void {
    if (str_contains($haystack, $needle)) {
      $detail = $message !== '' ? $message . ' ' : '';
      throw new RuntimeException($detail . 'Did not expect to find "' . $needle . '" in "' . $haystack . '".');
    }
  }

  protected function expectExceptionMessage(string $expectedMessage, callable $callback): void {
    try {
      $callback();
    } catch (Throwable $e) {
      $this->assertSame($expectedMessage, $e->getMessage(), 'Unexpected exception message.');
      return;
    }

    throw new RuntimeException('Expected exception "' . $expectedMessage . '" was not thrown.');
  }
}

