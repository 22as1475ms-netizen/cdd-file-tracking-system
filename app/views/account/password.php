<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/csrf.php"; ?>

<div class="details-shell" style="max-width: 720px; margin: 0 auto;">
  <div class="details-shell__header">
    <div>
      <div class="details-shell__eyebrow">Account</div>
      <h1 class="details-shell__title">Profile and Password</h1>
    </div>
  </div>

  <?php if(!empty($profileMsg)): ?><div class="alert alert-success auto-dismiss"><?= e($profileMsg) ?></div><?php endif; ?>
  <?php if((($_GET['msg'] ?? '') === 'updated') && empty($profileMsg)): ?><div class="alert alert-success auto-dismiss">Profile updated.</div><?php endif; ?>
  <?php if(!empty($profileError)): ?><div class="alert alert-danger auto-dismiss"><?= e($profileError) ?></div><?php endif; ?>
  <?php if (!empty($forceChange)): ?><div class="alert alert-warning">Your account is still using the default password. Change it now before continuing to the rest of the workspace.</div><?php endif; ?>

  <?php $profileName = (string)($currentUser['name'] ?? ($_SESSION['user']['name'] ?? '')); ?>
  <?php $profileInitials = avatar_initials($profileName); ?>
  <?php $profilePhoto = avatar_photo_url($currentUser ?? ($_SESSION['user'] ?? [])); ?>
  <?php $profilePreset = avatar_preset_key($currentUser ?? ($_SESSION['user'] ?? [])); ?>

  <form method="POST" enctype="multipart/form-data" class="drive-form-stack mb-4">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="profile">
    <div class="profile-avatar-editor mb-2">
      <label class="profile-avatar-editor__label" for="profile-avatar-upload">
        <span class="profile-avatar-editor__avatar app-user-pill__avatar <?= e($profilePreset) ?>">
          <?php if($profilePhoto): ?>
            <img src="<?= e($profilePhoto) ?>" alt="<?= e($profileName) ?>">
          <?php else: ?>
            <?= e($profileInitials) ?>
          <?php endif; ?>
          <span class="profile-avatar-editor__badge" aria-hidden="true"><i class="bi bi-pencil-square"></i></span>
        </span>
      </label>
      <div class="profile-avatar-editor__copy">
        <strong>Profile picture</strong>
        <span>Click the avatar to upload and replace your photo directly.</span>
      </div>
      <input class="profile-avatar-editor__input" id="profile-avatar-upload" type="file" name="avatar_photo" accept="image/png,image/jpeg,image/webp">
    </div>
    <label class="drive-label mb-0">Name</label>
    <input class="form-control drive-input" type="text" name="name" value="<?= e($profileName) ?>" required maxlength="120">
    <label class="drive-label mb-0">Or choose an avatar</label>
    <div class="avatar-preset-grid">
      <?php foreach(($avatarPresets ?? []) as $presetKey): ?>
        <label class="avatar-preset-label">
          <input class="avatar-preset-radio" type="radio" name="avatar_preset" value="<?= e($presetKey) ?>" <?= $profilePreset === $presetKey ? 'checked' : '' ?>>
          <span class="avatar-preset-swatch <?= e($presetKey) ?>"><?= e($profileInitials) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <label class="form-check mt-1">
      <input class="form-check-input" type="checkbox" name="use_preset" value="1">
      <span class="form-check-label">Use selected preset avatar</span>
    </label>
    <div class="drive-actions">
      <button class="btn btn-primary">Update profile</button>
    </div>
  </form>

  <?php if(!empty($msg)): ?><div class="alert alert-success auto-dismiss"><?= e($msg) ?></div><?php endif; ?>
  <?php if(!empty($error)): ?><div class="alert alert-danger auto-dismiss"><?= e($error) ?></div><?php endif; ?>

  <form method="POST" class="drive-form-stack" id="account-password-form">
    <?= csrf_field() ?>
    <input type="hidden" name="form_action" value="password">
    <label class="auth-form__label">
      <span>Current password</span>
      <span class="auth-password-field">
        <input class="form-control drive-input auth-password-field__input" type="password" name="current_password" placeholder="Current password" required data-password-input>
        <button type="button" class="auth-password-field__toggle" data-password-toggle aria-label="Show current password" aria-pressed="false"><span>Show</span></button>
      </span>
    </label>
    <label class="auth-form__label">
      <span>New password</span>
      <span class="auth-password-field">
        <input class="form-control drive-input auth-password-field__input" type="password" name="new_password" placeholder="New password" required minlength="8" autocomplete="new-password" data-password-input>
        <button type="button" class="auth-password-field__toggle" data-password-toggle aria-label="Show new password" aria-pressed="false"><span>Show</span></button>
      </span>
    </label>
    <label class="auth-form__label">
      <span>Confirm new password</span>
      <span class="auth-password-field">
        <input class="form-control drive-input auth-password-field__input" type="password" name="new_password_confirm" placeholder="Confirm new password" required minlength="8" autocomplete="new-password" data-password-input>
        <button type="button" class="auth-password-field__toggle" data-password-toggle aria-label="Show confirm password" aria-pressed="false"><span>Show</span></button>
      </span>
    </label>
    <div class="password-health" id="password-health" aria-live="polite">
      <div class="password-health__bar" aria-hidden="true">
        <span class="password-health__fill" id="password-health-fill"></span>
      </div>
      <div class="password-health__summary" id="password-health-summary">Use at least 8 characters with uppercase, lowercase, and a number.</div>
    </div>
    <div class="drive-actions">
      <button class="btn btn-primary" id="account-password-submit">Update password</button>
      <a class="btn btn-light" href="<?= BASE_URL . workspace_home_path() ?>">Back</a>
    </div>
  </form>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('account-password-form');
    if (!form) {
      return;
    }

    const currentInput = form.querySelector('input[name="current_password"]');
    const nextInput = form.querySelector('input[name="new_password"]');
    const confirmInput = form.querySelector('input[name="new_password_confirm"]');
    const passwordInputs = Array.from(form.querySelectorAll('[data-password-input]'));
    const toggleButtons = Array.from(form.querySelectorAll('[data-password-toggle]'));
    const fill = document.getElementById('password-health-fill');
    const summary = document.getElementById('password-health-summary');
    const submitButton = document.getElementById('account-password-submit');
    const updateState = function () {
      const current = currentInput ? currentInput.value : '';
      const next = nextInput ? nextInput.value : '';
      const confirm = confirmInput ? confirmInput.value : '';

      const state = {
        length: next.length >= 8,
        case: /[a-z]/.test(next) && /[A-Z]/.test(next),
        number: /\d/.test(next),
        match: next !== '' && confirm !== '' && next === confirm && next !== current
      };

      const requirementPasses = [state.length, state.case, state.number].filter(Boolean).length;
      const matchBonus = state.match ? 1 : 0;
      const score = requirementPasses + matchBonus;
      const percent = Math.max(0, score * 25);
      fill.style.width = next === '' && confirm === '' ? '0%' : percent + '%';

      let tone = 'is-weak';
      if (score >= 4) {
        tone = 'is-strong';
      } else if (score >= 2) {
        tone = 'is-medium';
      }
      fill.className = 'password-health__fill ' + tone;

      if (next === '' && confirm === '') {
        summary.textContent = 'Use at least 8 characters with uppercase, lowercase, and a number.';
      } else if (next !== '' && confirm !== '' && next === current) {
        summary.textContent = 'New password must be different from your current password.';
      } else if (score === 4) {
        summary.textContent = 'Password looks good and confirmation matches.';
      } else if (confirm !== '' && next !== confirm) {
        summary.textContent = 'Passwords do not match yet.';
      } else if (!state.length) {
        summary.textContent = 'Use at least 8 characters.';
      } else if (!state.case) {
        summary.textContent = 'Add both uppercase and lowercase letters.';
      } else if (!state.number) {
        summary.textContent = 'Add at least one number.';
      } else {
        summary.textContent = 'Confirm the new password to continue.';
      }

      if (submitButton) {
        submitButton.disabled = !(state.length && state.case && state.number && state.match);
      }
    };

    [currentInput, nextInput, confirmInput].forEach(function (input) {
      if (!input) return;
      input.addEventListener('input', updateState);
    });

    toggleButtons.forEach(function (button, index) {
      const input = passwordInputs[index];
      if (!button || !input) {
        return;
      }

      button.addEventListener('click', function () {
        const showing = input.type === 'text';
        input.type = showing ? 'password' : 'text';
        button.setAttribute('aria-pressed', String(!showing));
        button.setAttribute('aria-label', showing ? 'Show password' : 'Hide password');
        button.querySelector('span').textContent = showing ? 'Show' : 'Hide';
      });
    });

    updateState();
  });
</script>

<script>
  document.addEventListener('DOMContentLoaded', function () {
    const avatarShell = document.querySelector('.profile-avatar-editor__avatar');
    const fileInput = document.getElementById('profile-avatar-upload');
    const nameInput = document.querySelector('input[name="name"]');
    const presetRadios = Array.from(document.querySelectorAll('.avatar-preset-radio'));
    if (!avatarShell) return;

    const PRESETS = ['preset-ocean','preset-sunset','preset-forest','preset-plum','preset-slate','preset-amber'];

    function computeInitials(name) {
      const src = String(name || '').trim();
      if (!src) return 'U';
      const parts = src.split(/\s+/).filter(Boolean);
      const first = parts[0] ? parts[0].charAt(0).toUpperCase() : '';
      const second = parts.length > 1 ? parts[1].charAt(0).toUpperCase() : '';
      return (first + second) || 'U';
    }

    function setPresetClass(preset) {
      avatarShell.classList.remove(...PRESETS);
      if (preset && PRESETS.includes(preset)) {
        avatarShell.classList.add(preset);
      }
      // also update header avatar preset class
      const headerAvatar = document.querySelector('.app-user-pill--button .app-user-pill__avatar');
      if (headerAvatar) {
        headerAvatar.classList.remove(...PRESETS);
        if (preset && PRESETS.includes(preset)) headerAvatar.classList.add(preset);
      }
    }

    function showInitials() {
      const badge = avatarShell.querySelector('.profile-avatar-editor__badge');
      const img = avatarShell.querySelector('img');
      if (img) img.remove();
      const initials = computeInitials(nameInput ? nameInput.value : '');
      avatarShell.textContent = initials;
      if (badge) avatarShell.appendChild(badge);
      // update header initials
      const headerAvatar = document.querySelector('.app-user-pill--button .app-user-pill__avatar');
      if (headerAvatar) {
        const headerBadge = headerAvatar.querySelector('img');
        if (headerBadge) headerBadge.remove();
        headerAvatar.textContent = initials;
      }
    }

    function showImageSrc(src, alt) {
      const badge = avatarShell.querySelector('.profile-avatar-editor__badge');
      avatarShell.textContent = '';
      const img = document.createElement('img');
      img.src = src;
      img.alt = alt || '';
      avatarShell.appendChild(img);
      if (badge) avatarShell.appendChild(badge);
      // also update header image
      const headerAvatar = document.querySelector('.app-user-pill--button .app-user-pill__avatar');
      if (headerAvatar) {
        // remove existing content and add img
        headerAvatar.textContent = '';
        const himg = document.createElement('img');
        himg.src = src;
        himg.alt = alt || '';
        headerAvatar.appendChild(himg);
      }
    }

    const initialPreset = (document.querySelector('.avatar-preset-radio:checked') || {}).value || <?= json_encode($profilePreset) ?>;
    setPresetClass(initialPreset);

    fileInput && fileInput.addEventListener('change', function () {
      const f = this.files && this.files[0];
      if (!f) {
        showInitials();
        return;
      }
      const reader = new FileReader();
      reader.onload = function (ev) {
        showImageSrc(ev.target.result, nameInput ? nameInput.value : '');
      };
      reader.readAsDataURL(f);
    });

    nameInput && nameInput.addEventListener('input', function () {
      if (!avatarShell.querySelector('img')) {
        showInitials();
      }
    });

    presetRadios.forEach(function (r) {
      r.addEventListener('change', function () {
        if (this.checked) setPresetClass(this.value);
      });
    });
  });
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
