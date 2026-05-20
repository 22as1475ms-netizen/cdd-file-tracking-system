<?php
function require_role(string ...$roles): void {
  $current = strtoupper((string)($_SESSION['user']['role'] ?? ''));
  $allowed = array_map(static fn(string $role): string => strtoupper($role), $roles);
  if ($current === 'SUPER_ADMIN' && in_array('ADMIN', $allowed, true)) {
    return;
  }
  if (!in_array($current, $allowed, true)) {
    http_response_code(403);
    die("403 Forbidden");
  }
}
