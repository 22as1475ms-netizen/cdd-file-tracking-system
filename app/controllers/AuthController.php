<?php
require_once __DIR__ . "/../services/AuthService.php";
require_once __DIR__ . "/../helpers/csrf.php";

function login(): void {
  global $pdo;
  csrf_verify();

  if ($_SERVER['REQUEST_METHOD'] !== 'POST' && !empty($_SESSION['user']['id'])) {
    $currentUser = User::findById($pdo, (int)$_SESSION['user']['id']);
    if ($currentUser && ($currentUser['status'] ?? 'ACTIVE') === 'ACTIVE') {
      $_SESSION['user'] = $currentUser;
      if (User::usesDefaultPassword((string)($currentUser['password'] ?? ''))) {
        redirect('/account/password?force_change=1');
      }

      redirect(workspace_home_path());
    }
  }

  $error = null;
  $errCode = req_str('err', '');
  if ($errCode !== '') {
    $error = ui_message($errCode);
  }

  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $pass  = (string)($_POST['password'] ?? '');

    if (AuthService::login($pdo, $email, $pass)) {
      $currentUser = $_SESSION['user'] ?? [];
      if (User::usesDefaultPassword((string)($currentUser['password'] ?? ''))) {
        redirect('/account/password?force_change=1');
      }

      redirect(workspace_home_path());
    }
    $error = "Invalid credentials or disabled account.";
  }

  view('auth/login', ['error' => $error]);
}
