<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/csrf.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>

<div class="app-auth-shell">
  <section class="auth-panel">
    <div class="auth-panel__brand">
      <img class="auth-panel__logo" src="<?= BASE_URL ?>/assets/images/logo.png" alt="CDD-File-Tracking-System logo">
      <span class="auth-panel__eyebrow">CDD-File-Tracking-System</span>
    </div>
    <h1 class="auth-panel__title">Sign in to the workflow workspace.</h1>
    <p class="auth-panel__copy">
      Route documents, review submissions, and manage accounts from one secure workspace.
      If you are still using the default password, the system will ask you to change it before continuing.
    </p>
  </section>

  <section class="auth-card">
    <div class="auth-card__brand">
      <img class="auth-card__logo" src="<?= BASE_URL ?>/assets/images/logo.png" alt="CDD-File-Tracking-System logo">
      <div class="section-eyebrow">Workspace Access</div>
    </div>
    <h2 class="auth-card__title">Use your registered email and password</h2>

    <?php if (!empty($error)): ?>
      <div class="alert alert-danger"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="POST" class="auth-form">
      <?= csrf_field() ?>
      <label class="auth-form__label">
        <span>Work email</span>
        <input class="form-control" name="email" placeholder="username@cdd.com" required>
      </label>

      <label class="auth-form__label">
        <span>Account password</span>
        <span class="auth-password-field">
          <input
            type="password"
            class="form-control auth-password-field__input"
            name="password"
            placeholder="password"
            required
            data-password-input
          >
          <button
            type="button"
            class="auth-password-field__toggle"
            data-password-toggle
            aria-label="Show password"
            aria-pressed="false"
          >
            <span>Show</span>
          </button>
        </span>
      </label>

      <button class="btn btn-primary">Sign in to CDD-File-Tracking-System</button>
    </form>

    <div class="auth-hint mt-3">
      <strong>Forgotten password?</strong>
      <span>Ask an administrator to reset your account back to the default password, then change it after sign-in.</span>
    </div>
  </section>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const passwordInput = document.querySelector('[data-password-input]');
    const toggleButton = document.querySelector('[data-password-toggle]');

    if (!passwordInput || !toggleButton) {
      return;
    }

    toggleButton.addEventListener('click', function () {
      const showing = passwordInput.type === 'text';
      passwordInput.type = showing ? 'password' : 'text';
      toggleButton.setAttribute('aria-pressed', String(!showing));
      toggleButton.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
      toggleButton.textContent = showing ? 'Show' : 'Hide';
    });
  });
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
