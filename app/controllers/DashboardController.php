<?php
require_once __DIR__ . "/../middleware/require_role.php";
require_once __DIR__ . "/../models/Document.php";
require_once __DIR__ . "/../models/Folder.php";
require_once __DIR__ . "/../models/AuditLog.php";
require_once __DIR__ . "/../models/User.php";

function user_dashboard(): void {
  global $pdo;

  $role = strtoupper((string)($_SESSION['user']['role'] ?? ''));
  if (in_array($role, ['SUPER_ADMIN', 'ADMIN', 'SECTION_ADMIN'], true)) {
    redirect('/admin/dashboard');
  }

  $uid = (int)($_SESSION['user']['id'] ?? 0);
  if ($uid <= 0) {
    redirect('/login');
  }

  $user = User::findById($pdo, $uid);
  $userName = trim((string)($user['name'] ?? ($_SESSION['user']['name'] ?? '')));
  $routeSummary = Document::summarizeRoutedToUser($pdo, $uid, $userName);
  $page = max(1, req_int('page', 1));
  $perPage = 15;
  $routedTotal = (int)($routeSummary['total'] ?? 0);
  $totalPages = max(1, (int)ceil($routedTotal / $perPage));
  $page = min($page, $totalPages);
  // Keep the dashboard list paged. Staff can accumulate a long route history,
  // and loading the whole inbox on every visit does not scale well.
  [$routedInbox] = Document::listRoutedToUser($pdo, $uid, $userName, $page, $perPage);
  [$activeRoutes] = Document::listRoutedToUser($pdo, $uid, $userName, 1, 6, '', ['ROUTED', 'UNDER_REVIEW']);
  $recentActivity = AuditLog::recentForUser($pdo, $uid, 8);

  view('dashboard/user', [
    'routeSummary' => $routeSummary,
    'unreadNotifications' => 0,
    'routedInbox' => $routedInbox,
    'activeRoutes' => $activeRoutes,
    'completedRoutes' => [],
    'recentActivity' => $recentActivity,
    'recentNotifications' => [],
    'routedPagination' => [
      'page' => $page,
      'per_page' => $perPage,
      'total' => $routedTotal,
      'pages' => $totalPages,
    ],
  ]);
}

function dashboard_routed_documents_for_user(PDO $pdo, int $userId, int $page = 1, int $perPage = 15): array {
  $user = User::findById($pdo, $userId);
  $userName = trim((string)($user['name'] ?? ''));
  [$documents] = Document::listRoutedToUser($pdo, $userId, $userName, $page, $perPage);
  return $documents;
}
