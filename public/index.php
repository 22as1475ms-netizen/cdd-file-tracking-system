<?php
$ROOT = dirname(__DIR__); // Project root

require $ROOT . "/app/config/app.php";
require $ROOT . "/app/config/database.php";
cddfts_bootstrap_session();

ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('display_startup_errors', APP_DEBUG ? '1' : '0');
error_reporting(APP_DEBUG ? E_ALL : 0);

require $ROOT . "/app/helpers/view.php";
require $ROOT . "/app/helpers/redirect.php";
require $ROOT . "/app/helpers/csrf.php";
require $ROOT . "/app/helpers/http.php";

function cddfts_log_throwable(Throwable $throwable): void {
  error_log('[CDD-File-Tracking-System] ' . get_class($throwable) . ': ' . $throwable->getMessage());
  error_log('[CDD-File-Tracking-System] in ' . $throwable->getFile() . ':' . $throwable->getLine());
  error_log('[CDD-File-Tracking-System] trace: ' . $throwable->getTraceAsString());
}

function cddfts_render_fatal_response(string $message, int $status = 500): void {
  if (!headers_sent()) {
    http_response_code($status);
    header('Content-Type: text/plain; charset=utf-8');
  }

  if (APP_DEBUG) {
    echo $message;
    return;
  }

  echo 'Internal Server Error';
}

set_exception_handler(static function (Throwable $throwable): void {
  cddfts_log_throwable($throwable);
  cddfts_render_fatal_response($throwable->getMessage(), 500);
});

set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
  if (!(error_reporting() & $severity)) {
    return false;
  }

  throw new ErrorException($message, 0, $severity, $file, $line);
});

register_shutdown_function(static function (): void {
  $error = error_get_last();
  if (!$error) {
    return;
  }

  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array((int)$error['type'], $fatalTypes, true)) {
    return;
  }

  $message = sprintf('%s in %s:%d', (string)$error['message'], (string)$error['file'], (int)$error['line']);
  error_log('[CDD-File-Tracking-System] shutdown fatal: ' . $message);
  cddfts_render_fatal_response($message, 500);
});

function cddfts_normalized_request_path(): string {
  $path = (string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);
  if (BASE_URL !== '' && str_starts_with($path, BASE_URL)) {
    $path = substr($path, strlen(BASE_URL));
  }
  if ($path !== '/') {
    $path = rtrim($path, '/');
  }
  return $path === '' ? '/' : $path;
}

function cddfts_web_routes(): array {
  return [
    '/' => ['controller' => 'AuthController.php', 'handler' => 'login'],
    '/login' => ['controller' => 'AuthController.php', 'handler' => 'login'],
    '/notifications/read' => ['middleware' => ['require_login.php'], 'controller' => 'NotificationController.php', 'handler' => 'notifications_mark_read'],
    '/notifications/clear' => ['middleware' => ['require_login.php'], 'controller' => 'NotificationController.php', 'handler' => 'notifications_clear_all'],
    '/dashboard' => ['middleware' => ['require_login.php'], 'controller' => 'DashboardController.php', 'handler' => 'user_dashboard'],
    '/account/password' => ['middleware' => ['require_login.php'], 'controller' => 'AccountController.php', 'handler' => 'account_password'],
    '/account/onboarding/complete' => ['middleware' => ['require_login.php'], 'controller' => 'AccountController.php', 'handler' => 'account_complete_onboarding'],
    '/media/file' => ['middleware' => ['require_login.php'], 'controller' => 'MediaController.php', 'handler' => 'serve_media_file'],
    '/documents' => ['middleware' => ['require_login.php'], 'handler' => 'cddfts_documents_redirect'],
    '/documents/upload' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'upload'],
    '/documents/view' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'view_doc'],
    '/documents/preview-data' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'document_preview_data'],
    '/documents/download' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'download_doc'],
    '/documents/file' => ['controller' => 'DocumentController.php', 'handler' => 'serve_doc_file'],
    '/documents/delete' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'soft_delete'],
    '/documents/share' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'share_doc'],
    '/documents/share/respond' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'respond_to_share'],
    '/documents/share/revoke' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'revoke_share'],
    '/documents/share/folder' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'share_folder'],
    '/admin/documents/delete' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'admin_delete_document'],
    '/documents/route' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'route_document'],
    '/documents/route/complete' => ['middleware' => ['require_login.php'], 'controller' => 'DocumentController.php', 'handler' => 'complete_route_lifecycle'],
    '/admin/users' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_users'],
    '/admin/users/export' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_export_users'],
    '/admin/users/toggle' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_toggle_user'],
    '/admin/users/create' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_create_user'],
    '/admin/divisions/create' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_create_division'],
    '/admin/users/delete' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_delete_user'],
    '/admin/users/role' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_change_role'],
    '/admin/users/password' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_change_user_password'],
    '/admin/users/password/reveal' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_reveal_user_password'],
    '/admin/users/password/default' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_reset_user_password'],
    '/admin/logs' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_logs'],
    '/admin/logs/export' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_export_logs'],
    '/admin/routed' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_routed_report'],
    '/admin/dashboard' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_dashboard'],
    '/admin/routed/export' => ['middleware' => ['require_login.php'], 'controller' => 'AdminController.php', 'handler' => 'admin_export_routed'],
    // Organization chart removed — admin organization management is available under /admin/users
  ];
}

function cddfts_require_route_files(string $root, array $route): void {
  foreach ($route['middleware'] ?? [] as $middleware) {
    require $root . "/app/middleware/" . $middleware;
  }

  if (!empty($route['controller'])) {
    require $root . "/app/controllers/" . $route['controller'];
  }
}

function cddfts_dashboard_redirect(): void {
  redirect(workspace_home_path());
}

function cddfts_documents_redirect(): void {
  // Redirect legacy workspace view to admin dashboard
  $role = strtoupper((string)($_SESSION['user']['role'] ?? ''));
  if (in_array($role, ['SUPER_ADMIN', 'ADMIN', 'SECTION_ADMIN'], true)) {
    redirect('/admin/dashboard');
  }
  redirect('/dashboard');
}

function cddfts_logout(): void {
  global $pdo;

  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    csrf_verify();
  } else {
    redirect('/');
  }
  if (!empty($_SESSION['user']['id'])) {
    require __DIR__ . "/../app/models/AuditLog.php";
    AuditLog::add($pdo, (int)$_SESSION['user']['id'], "Logged out", null, null);
  }
  session_destroy();
  redirect('/login');
}

function cddfts_dispatch_web_route(string $root, string $path): bool {
  if ($path === '/logout') {
    cddfts_logout();
    return true;
  }

  $routes = cddfts_web_routes();
  if (!isset($routes[$path])) {
    return false;
  }

  $route = $routes[$path];
  cddfts_require_route_files($root, $route);

  $handler = $route['handler'] ?? '';
  if ($handler === '' || !function_exists($handler)) {
    throw new RuntimeException('Route handler is not available for ' . $path);
  }

  $handler();
  return true;
}

$path = cddfts_normalized_request_path();

if (str_starts_with($path, '/api/')) {
  require $ROOT . "/app/controllers/ApiController.php";
  api_dispatch($_SERVER['REQUEST_METHOD'], $path);
  exit;
}

if (!cddfts_dispatch_web_route($ROOT, $path)) {
  http_response_code(404);
  echo "404 Not Found";
}
