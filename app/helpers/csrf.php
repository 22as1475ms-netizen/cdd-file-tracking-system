<?php
function csrf_token(): string {
  if (!isset($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(16));
  }
  return $_SESSION['_csrf'];
}

function csrf_field(): string {
  $t = htmlspecialchars(csrf_token());
  return '<input type="hidden" name="_csrf" value="'.$t.'">';
}

function csrf_request_token(): string {
  if (isset($_POST['_csrf'])) {
    return trim((string)$_POST['_csrf']);
  }

  if (isset($_SERVER['HTTP_X_CSRF_TOKEN'])) {
    return trim((string)$_SERVER['HTTP_X_CSRF_TOKEN']);
  }

  if (isset($_SERVER['HTTP_X_XSRF_TOKEN'])) {
    return trim((string)$_SERVER['HTTP_X_XSRF_TOKEN']);
  }

  return '';
}

function csrf_verify(?array $methods = null): void {
  $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
  $allowedMethods = $methods ?? ['POST'];
  $normalizedMethods = array_map(static fn(string $value): string => strtoupper(trim($value)), $allowedMethods);
  if (!in_array($method, $normalizedMethods, true)) {
    return;
  }

  $token = csrf_request_token();
  $ok = $token !== ''
    && isset($_SESSION['_csrf'])
    && hash_equals((string)$_SESSION['_csrf'], $token);
  if (!$ok) {
    unset($_SESSION['_csrf']);
    csrf_fail_response();
  }
}

function csrf_fail_response(): void {
  if (csrf_prefers_json_response()) {
    http_response_code(419);
    header('Content-Type: application/json; charset=UTF-8');
    echo json_encode([
      'error' => 'session_expired',
      'message' => 'Your session expired. Please refresh and try again.',
    ]);
    exit;
  }

  http_response_code(302);
  header('Location: ' . cddfts_base_url_path(csrf_failure_redirect_path()));
  exit;
}

function csrf_prefers_json_response(): bool {
  $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
  $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
  $requestUri = (string)($_SERVER['REQUEST_URI'] ?? '');

  if ($requestedWith === 'xmlhttprequest') {
    return true;
  }

  if (str_contains($accept, 'application/json')) {
    return true;
  }

  return str_starts_with($requestUri, '/api/') || str_contains($requestUri, '/api/');
}

function csrf_failure_redirect_path(): string {
  $fallback = '/login?err=session_expired';
  $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));
  if ($referer === '') {
    return $fallback;
  }

  $refererParts = parse_url($referer);
  $refererHost = strtolower((string)($refererParts['host'] ?? ''));
  $requestHost = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  if ($refererHost !== '' && $requestHost !== '' && $refererHost !== $requestHost) {
    return $fallback;
  }

  $path = (string)($refererParts['path'] ?? '');
  if ($path === '') {
    return $fallback;
  }

  $query = [];
  parse_str((string)($refererParts['query'] ?? ''), $query);
  $query['err'] = 'session_expired';
  $queryString = http_build_query($query);

  return $path . ($queryString !== '' ? '?' . $queryString : '');
}
