<!DOCTYPE html>
<html>
<head>
<title>CDD-File-Tracking-System</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">

<nav class="navbar navbar-dark bg-dark px-3">
<span class="navbar-brand">CDD-File-Tracking-System</span>
<form method="POST" action="<?= BASE_URL ?>/logout" class="js-confirm m-0" data-confirm-message="Log out now?">
  <?= csrf_field() ?>
  <button type="submit" class="btn btn-link text-white p-0 text-decoration-none" onclick="return window.confirm('Log out now?')">Logout</button>
</form>
</nav>

<div class="container mt-4"></div>
