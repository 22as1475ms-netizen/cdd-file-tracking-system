<?php
function redirect(string $path): void {
  header("Location: " . cddfts_base_url_path($path));
  exit;
}

function workspace_home_path(): string {
  $role = strtoupper((string)($_SESSION['user']['role'] ?? ''));
  if (in_array($role, ['SUPER_ADMIN', 'ADMIN', 'SECTION_ADMIN'], true)) {
    return '/admin/dashboard';
  }
  return '/dashboard';
}
