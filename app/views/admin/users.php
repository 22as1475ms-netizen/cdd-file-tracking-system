<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/csrf.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
$adminContext = is_array($adminContext ?? null) ? $adminContext : [];
$isSectionAdmin = !empty($adminContext['is_section_admin']);
$canCreateDivisions = array_key_exists('can_create_divisions', $adminContext) ? (bool)$adminContext['can_create_divisions'] : true;
$canCreateAccounts = array_key_exists('can_create_accounts', $adminContext) ? (bool)$adminContext['can_create_accounts'] : true;
$canViewPasswords = array_key_exists('can_view_passwords', $adminContext) ? (bool)$adminContext['can_view_passwords'] : true;
$canShowAccountActions = array_key_exists('can_show_account_actions', $adminContext) ? (bool)$adminContext['can_show_account_actions'] : true;
$canExportUsers = array_key_exists('can_export_users', $adminContext) ? (bool)$adminContext['can_export_users'] : true;
$accountColumnCount = 4 + ($canViewPasswords ? 1 : 0) + ($canShowAccountActions ? 1 : 0);
$groupsByUserId = [];
foreach ($workspaceGroups as $group) {
  $groupsByUserId[(int)$group['user']['id']] = $group;
}
$defaultUserId = !empty($workspaceGroups) ? (int)$workspaceGroups[0]['user']['id'] : 0;
$requestedUserId = req_int('user_id', 0);
$selectedUserId = isset($groupsByUserId[$requestedUserId]) ? $requestedUserId : $defaultUserId;
$selectedGroup = $groupsByUserId[$selectedUserId] ?? ($workspaceGroups[0] ?? null);
$selectedSection = req_str('section', '');  // Read section filter from URL
$selectedUser = $selectedGroup['user'] ?? null;
$selectedSummary = $selectedGroup['summary'] ?? [];
$selectedPanel = ($userPanels ?? [])[$selectedUserId] ?? ['activity_summary' => []];
$selectedActivity = $selectedPanel['activity_summary'] ?? [];
$visibleDivisions = array_values(array_map(
  static fn(array $group): array => (array)($group['division'] ?? []),
  (array)($divisionGroups['divisions'] ?? [])
));

if (!function_exists('admin_view_datetime')) {
  function admin_view_datetime(?string $value, string $fallback = 'No recent activity'): string {
    $raw = trim((string)$value);
    if ($raw === '') {
      return $fallback;
    }
    try {
      $dt = new DateTimeImmutable($raw, new DateTimeZone('Asia/Manila'));
    } catch (Throwable $_e) {
      return $fallback;
    }
    return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('M d, Y g:i A');
  }
}

if (!function_exists('admin_badge_class')) {
  function admin_badge_class(string $value, string $type = 'status'): string {
    $normalized = strtoupper(trim($value));
    if ($type === 'area') {
      return 'is-official';
    }
    if ($type === 'priority') {
      return match ($normalized) {
        'RUSH', 'URGENT' => 'is-danger',
        'HIGH' => 'is-warning',
        'MODERATE', 'NORMAL' => 'is-info',
        default => 'is-neutral',
      };
    }
    return match ($normalized) {
      'APPROVED', 'ACTIVE', 'SHARE_ACCEPTED', 'IN_REVIEW', 'COMPLETED' => 'is-success',
      'REJECTED', 'DECLINED', 'DISABLED' => 'is-danger',
      'PENDING_REVIEW_ACCEPTANCE', 'PENDING_SHARE_ACCEPTANCE', 'NOT_SENT', 'DRAFT' => 'is-warning',
      default => 'is-neutral',
    };
  }
}

if (!function_exists('admin_role_badge')) {
  function admin_role_badge(string $role): string {
    $normalized = strtoupper(trim($role));
    $label = role_label($normalized);
    $tone = match ($normalized) {
      'SUPER_ADMIN', 'ADMIN' => 'super',
      'SECTION_ADMIN', 'DIVISION_CHIEF' => 'admin',
      default => 'staff',
    };
    $icon = match ($tone) {
      'super' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.4 19 6v5.3c0 4.4-2.9 8.4-7 9.7-4.1-1.3-7-5.3-7-9.7V6l7-2.6Z"/><path d="m8.5 12 2.2 2.2 4.8-5.1"/></svg>',
      'admin' => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 18.5 6v5c0 4-2.6 7.6-6.5 9-3.9-1.4-6.5-5-6.5-9V6L12 3.5Z"/><path d="M9 11.4h6"/><path d="M12 8.4v6"/></svg>',
      default => '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3.5 18 6v5.2c0 3.8-2.4 7.1-6 8.3-3.6-1.2-6-4.5-6-8.3V6l6-2.5Z"/><path d="M9.7 11.8 11.3 13.4 14.4 10.1"/></svg>',
    };

    return '<span class="admin-role-badge admin-role-badge--' . e($tone) . '">' . $icon . '<span>' . e($label) . '</span></span>';
  }
}

if (!function_exists('admin_password_cell')) {
  function admin_password_cell(array $user): string {
    $userId = (int)($user['id'] ?? 0);
    $hasStoredPassword = trim((string)($user['generated_password'] ?? '')) !== '';
    $label = $hasStoredPassword ? 'Reveal password' : 'Password not stored';
    $button = '<button type="button" class="admin-password-reveal" data-password-reveal data-user-id="' . $userId . '" data-user-name="' . e((string)($user['name'] ?? '')) . '" data-user-email="' . e((string)($user['email'] ?? '')) . '" ' . ($hasStoredPassword ? '' : 'disabled ') . 'aria-label="' . e($label) . '"><i class="bi bi-eye"></i></button>';
    return '<span class="admin-password-mask" data-password-mask>********</span>' . $button;
  }
}

if (!function_exists('admin_meta_pill')) {
  function admin_meta_pill(string $label, string $tone = 'neutral'): string {
    $allowedTones = ['neutral', 'success', 'warning', 'danger', 'info'];
    $resolvedTone = in_array($tone, $allowedTones, true) ? $tone : 'neutral';
    return '<span class="admin-meta-pill admin-meta-pill--' . e($resolvedTone) . '">' . e($label) . '</span>';
  }
}

if (!function_exists('admin_status_tone')) {
  function admin_status_tone(string $status): string {
    return match (strtoupper(trim($status))) {
      'ACTIVE', 'APPROVED', 'COMPLETED' => 'success',
      'DISABLED', 'REJECTED', 'DECLINED' => 'danger',
      default => 'warning',
    };
  }
}

if (!function_exists('admin_display_code_label')) {
  function admin_display_code_label(array $user): string {
    $code = trim((string)($user['display_code'] ?? ''));
    if ($code !== '') {
      return $code;
    }

    return 'USR-' . str_pad((string)((int)($user['id'] ?? 0)), 3, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('admin_account_controls')) {
  function admin_account_controls(array $user, array $divisions): string {
    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
      return '';
    }
    if ($userId === (int)($_SESSION['user']['id'] ?? 0)) {
      return '<span class="admin-account-row-note">Current account</span>';
    }

    $role = (string)($user['role'] ?? 'SECTION_STAFF');
    $divisionId = (int)($user['division_id'] ?? 0);
    $status = (string)($user['status'] ?? 'ACTIVE');
    $roleOptions = [
      'SECTION_STAFF' => 'Section Staff',
      'SECTION_ADMIN' => 'Section Admin',
      'SUPER_ADMIN' => 'Super Admin',
    ];
    $roleSelect = '<select class="form-select form-select-sm" name="role">';
    foreach ($roleOptions as $value => $label) {
      $selected = match ($value) {
        'SECTION_STAFF' => in_array($role, ['SECTION_STAFF', 'EMPLOYEE'], true),
        'SECTION_ADMIN' => in_array($role, ['SECTION_ADMIN', 'DIVISION_CHIEF'], true),
        'SUPER_ADMIN' => in_array($role, ['SUPER_ADMIN', 'ADMIN'], true),
        default => false,
      };
      $roleSelect .= '<option value="' . e($value) . '"' . ($selected ? ' selected' : '') . '>' . e($label) . '</option>';
    }
    $roleSelect .= '</select>';

    $divisionSelect = '<select class="form-select form-select-sm" name="division_id"><option value="0">No section</option>';
    foreach ($divisions as $division) {
      $id = (int)($division['id'] ?? 0);
      $divisionSelect .= '<option value="' . $id . '"' . ($divisionId === $id ? ' selected' : '') . '>' . e((string)($division['name'] ?? 'Section')) . '</option>';
    }
    $divisionSelect .= '</select>';

    $toggleStatus = $status === 'ACTIVE' ? 'DISABLED' : 'ACTIVE';
    $toggleLabel = $status === 'ACTIVE' ? 'Disable' : 'Enable';

    return '
      <details class="admin-account-row-controls">
        <summary>Manage</summary>
        <div class="admin-account-row-menu">
          <form method="POST" action="' . BASE_URL . '/admin/users/role?id=' . $userId . '" class="admin-account-row-form admin-confirm-form" data-confirm-label="update this account role">
            ' . csrf_field() . '
            <input type="hidden" name="confirm_password" value="">
            <label><span>Role</span>' . $roleSelect . '</label>
            <label><span>Section</span>' . $divisionSelect . '</label>
            <button class="btn btn-sm btn-outline-dark" type="submit">Save</button>
          </form>
          <form method="POST" action="' . BASE_URL . '/admin/users/password?id=' . $userId . '" class="admin-account-row-form admin-confirm-form" data-confirm-label="change this account password">
            ' . csrf_field() . '
            <input type="hidden" name="confirm_password" value="">
            <label><span>New password</span><input class="form-control form-control-sm" type="password" name="new_password" minlength="8" required data-password-input></label>
            <label><span>Confirm</span><input class="form-control form-control-sm" type="password" name="new_password_confirm" minlength="8" required data-password-input></label>
            <button class="btn btn-sm btn-outline-secondary" type="submit">Change password</button>
          </form>
          <div class="admin-account-row-actions">
            <form method="POST" action="' . BASE_URL . '/admin/users/password/default?id=' . $userId . '" class="admin-confirm-form" data-confirm-label="reset this account password to the default password" data-confirm-message="Reset this account password back to the default password?">
              ' . csrf_field() . '
              <input type="hidden" name="confirm_password" value="">
              <button class="btn btn-sm btn-outline-warning" type="submit">Reset</button>
            </form>
            <form method="POST" action="' . BASE_URL . '/admin/users/toggle?id=' . $userId . '" class="admin-confirm-form" data-confirm-label="change this account status">
              ' . csrf_field() . '
              <input type="hidden" name="confirm_password" value="">
              <input type="hidden" name="status" value="' . e($toggleStatus) . '">
              <button class="btn btn-sm btn-outline-primary" type="submit">' . e($toggleLabel) . '</button>
            </form>
            <form method="POST" action="' . BASE_URL . '/admin/users/delete?id=' . $userId . '" class="admin-confirm-form" data-confirm-label="delete this account" data-confirm-message="Permanently delete this account and all owned files? This cannot be undone.">
              ' . csrf_field() . '
              <input type="hidden" name="confirm_password" value="">
              <button class="btn btn-sm btn-outline-danger" type="submit">Delete</button>
            </form>
          </div>
        </div>
      </details>
    ';
  }
}

if ($selectedUser) {
  $selectedUserPhoto = avatar_photo_url($selectedUser);
  $selectedUserPreset = avatar_preset_key($selectedUser);
  $selectedUserInitials = avatar_initials((string)$selectedUser['name']);
  $selectedJoined = admin_view_datetime((string)($selectedUser['created_at'] ?? ''), 'Date unavailable');
}
?>

<div class="workspace-page admin-users-page admin-users-page--split">

  <?php if(req_str('msg') !== ''): ?>
    <?php if(req_str('msg') === 'user_created' && req_str('created_password') !== ''): ?>
      <div class="alert alert-success auto-dismiss">
        Account created for <?= e(req_str('created_email', 'the new user')) ?>.
        Password: <strong><?= e(req_str('created_password')) ?></strong>
      </div>
    <?php else: ?>
      <div class="alert alert-success auto-dismiss"><?= e(ui_message(req_str('msg'))) ?></div>
    <?php endif; ?>
  <?php endif; ?>
  <?php if(req_str('err') !== ''): ?>
    <div class="alert alert-danger auto-dismiss"><?= e(ui_message(req_str('err'))) ?></div>
  <?php endif; ?>

  <div class="admin-drive-layout<?= $isSectionAdmin ? ' admin-drive-layout--section-admin' : '' ?>">
    <aside class="admin-drive-sidebar<?= $isSectionAdmin ? ' admin-drive-sidebar--section-admin' : '' ?>">
      <?php if($canCreateDivisions): ?>
      <div class="table-card">
        <details class="admin-collapsible">
          <summary class="table-card__header admin-collapsible__summary admin-panel-summary admin-panel-summary--division">
            <div class="admin-panel-summary__content">
              <div class="admin-panel-summary__topline">
                <span class="admin-panel-summary__icon"><i class="bi bi-diagram-3"></i></span>
                <span class="admin-panel-summary__tag">Structure</span>
              </div>
              <h2>Create division</h2>
              <p>Set the department structure before assigning employees.</p>
            </div>
          </summary>
          <div class="table-card__body admin-setup-card__body">
            <div class="admin-setup-card__intro">
              <span class="admin-setup-card__eyebrow">Organization Setup</span>
              <p>Start with the section name and manage its Section Admin later from the accounts workspace.</p>
            </div>
            <form method="POST" action="<?= BASE_URL ?>/admin/divisions/create" class="drive-form-stack admin-confirm-form admin-setup-form" data-confirm-label="create this division">
              <?= csrf_field() ?>
              <input type="hidden" name="confirm_password" value="">
              <input type="hidden" name="chief_user_id" value="0">
              <div class="admin-setup-form__stack">
                <label class="admin-setup-field">
                  <span class="admin-setup-field__label">Division name</span>
                  <input class="form-control drive-input" name="name" placeholder="e.g. PAMBCS" required>
                </label>
              </div>
              <div class="admin-setup-card__footer">
                <p class="admin-setup-card__hint">You can assign the Section Admin later from the accounts workspace.</p>
                <button class="btn btn-primary admin-setup-card__submit" type="submit">Create division</button>
              </div>
            </form>
            <section class="admin-existing-divisions" aria-label="Created divisions">
              <div class="admin-existing-divisions__header">
                <span class="admin-existing-divisions__title">Created divisions</span>
                <span class="admin-existing-divisions__count"><?= count($visibleDivisions ?? []) ?></span>
              </div>
              <?php if(!empty($visibleDivisions ?? [])): ?>
                <div class="admin-existing-divisions__list">
                  <?php foreach(($visibleDivisions ?? []) as $division): ?>
                    <?php $chiefName = trim((string)($division['chief_name'] ?? '')); ?>
                    <article class="admin-existing-divisions__item">
                      <div class="admin-existing-divisions__item-main">
                        <strong><?= e((string)($division['name'] ?? 'Unnamed division')) ?></strong>
                        <span><?= $chiefName !== '' ? e($chiefName) : 'Section Admin not assigned yet' ?></span>
                      </div>
                    </article>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="admin-existing-divisions__empty">Created divisions will appear here after you add them.</p>
              <?php endif; ?>
            </section>
          </div>
        </details>
      </div>
      <?php endif; ?>

      <?php if($canCreateAccounts): ?>
      <div class="table-card">
        <details class="admin-collapsible">
          <summary class="table-card__header admin-collapsible__summary admin-panel-summary admin-panel-summary--account">
            <div class="admin-panel-summary__content">
              <div class="admin-panel-summary__topline">
                <span class="admin-panel-summary__icon"><i class="bi bi-person-plus"></i></span>
                <span class="admin-panel-summary__tag">Provisioning</span>
              </div>
              <h2>Create account</h2>
              <p>Auto-generated email and password based on username</p>
            </div>
          </summary>
          <div class="table-card__body admin-setup-card__body">
            <div class="admin-setup-card__intro">
              <span class="admin-setup-card__eyebrow">Account Provisioning</span>
              <p>Enter a full name once and the login email and password will be generated automatically.</p>
            </div>
            <form method="POST" action="<?= BASE_URL ?>/admin/users/create" class="drive-form-stack admin-setup-form" id="admin-create-account-form">
              <?= csrf_field() ?>
              <input type="hidden" name="email" id="admin-create-email-hidden">
              <input type="hidden" name="password" id="admin-create-password-hidden">
              <label class="admin-setup-field">
                <span class="admin-setup-field__label">Full name (username)</span>
                <input class="form-control drive-input" name="name" id="admin-create-name" placeholder="e.g. Jordan Martinez" required>
              </label>
              <div class="admin-create-suggestions">
                <div class="admin-suggestion-field">
                  <label class="admin-setup-field__label">
                    <span>Generated email</span>
                  </label>
                  <div id="admin-email-options" class="admin-suggestion-options"></div>
                </div>
                <div class="admin-suggestion-field">
                  <label class="admin-setup-field__label">
                    <span>Generated password</span>
                  </label>
                  <div id="admin-password-options" class="admin-suggestion-options"></div>
                </div>
              </div>
              <div class="admin-setup-form__grid">
                <label class="admin-setup-field">
                  <span class="admin-setup-field__label">Role</span>
                  <input type="hidden" name="role" id="admin-create-role" value="SECTION_STAFF">
                  <div class="admin-role-picker" id="admin-role-picker" role="radiogroup" aria-label="Account role">
                    <button type="button" class="admin-role-picker__option is-active" data-role-option="SECTION_STAFF" data-role-caption="Assigned to a division workspace." aria-pressed="true">
                      <span class="admin-role-picker__title">Section Staff</span>
                    </button>
                    <button type="button" class="admin-role-picker__option" data-role-option="SECTION_ADMIN" data-role-caption="Leads one division and receives section-level files." aria-pressed="false">
                      <span class="admin-role-picker__title">Section Admin</span>
                    </button>
                    <button type="button" class="admin-role-picker__option" data-role-option="SUPER_ADMIN" data-role-caption="CDD Super Admin with no division assignment." aria-pressed="false">
                      <span class="admin-role-picker__title">Super Admin</span>
                    </button>
                  </div>
                  <small class="admin-setup-select__meta" id="admin-create-role-meta">Assigned to a division workspace.</small>
                </label>
                <label class="admin-setup-field">
                  <span class="admin-setup-field__label">Division</span>
                  <input type="hidden" name="division_id" id="admin-create-division-hidden" value="">
                  <span class="admin-setup-select-wrap">
                  <select class="form-select drive-input admin-setup-select" name="division_id" id="admin-create-division">
                    <option value="0">No division</option>
                    <?php foreach(($visibleDivisions ?? []) as $division): ?>
                      <option value="<?= (int)$division['id'] ?>"><?= e((string)$division['name']) ?></option>
                    <?php endforeach; ?>
                  </select>
                  </span>
                  <small class="admin-setup-select__meta" id="admin-create-division-meta">Choose the section this account belongs to.</small>
                </label>
              </div>
              <div class="admin-setup-card__footer">
                <p class="admin-setup-card__hint">The generated email and password will be used when you submit.</p>
                <button class="btn btn-primary admin-setup-card__submit" type="submit">Create account</button>
              </div>
            </form>
          </div>
        </details>
      </div>
      <?php endif; ?>

      <div class="table-card">
        <details class="admin-collapsible" open>
          <summary class="table-card__header admin-collapsible__summary admin-panel-summary admin-panel-summary--accounts">
            <div class="admin-panel-summary__content">
              <div class="admin-panel-summary__topline">
                <span class="admin-panel-summary__icon"><i class="bi bi-people"></i></span>
                <span class="admin-panel-summary__tag">Oversight</span>
              </div>
              <h2>Accounts</h2>
              <p>Select an account to inspect routed documents assigned to that staff member.</p>
            </div>
          </summary>
          <?php if(!$isSectionAdmin): ?>
            <div class="admin-users-filter">
              <label class="admin-users-filter__label">
                <span class="admin-users-filter__title">Filter by section</span>
                <select id="admin-section-filter" class="form-select form-select-sm admin-users-filter__select visually-hidden">
                  <option value=""<?= $selectedSection === '' ? ' selected' : '' ?>>All sections</option>
                  <?php foreach(($divisionGroups['divisions'] ?? []) as $group): ?>
                    <option value="<?= e((string)$group['division']['name']) ?>"<?= $selectedSection === (string)$group['division']['name'] ? ' selected' : '' ?>><?= e((string)$group['division']['name']) ?></option>
                  <?php endforeach; ?>
                  <?php if(!empty($divisionGroups['unassigned'] ?? [])): ?>
                    <option value="__unassigned"<?= $selectedSection === '__unassigned' ? ' selected' : '' ?>>No Section</option>
                  <?php endif; ?>
                </select>
                <div class="admin-filter-dropdown" id="admin-section-filter-dropdown">
                  <button type="button" class="admin-filter-dropdown__trigger" id="admin-section-filter-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="admin-section-filter-menu">
                    <span class="admin-filter-dropdown__value" id="admin-section-filter-value">
                      <?php
                        $selectedSectionLabel = 'All sections';
                        if ($selectedSection === '__unassigned') {
                          $selectedSectionLabel = 'No Section';
                        } elseif ($selectedSection !== '') {
                          $selectedSectionLabel = $selectedSection;
                        }
                      ?>
                      <?= e($selectedSectionLabel) ?>
                    </span>
                  </button>
                  <div class="admin-filter-dropdown__menu" id="admin-section-filter-menu" role="listbox" tabindex="-1" hidden>
                    <button type="button" class="admin-filter-dropdown__item<?= $selectedSection === '' ? ' is-selected' : '' ?>" data-filter-value="" role="option" aria-selected="<?= $selectedSection === '' ? 'true' : 'false' ?>">All sections</button>
                    <?php foreach(($divisionGroups['divisions'] ?? []) as $group): ?>
                      <?php $optionValue = (string)$group['division']['name']; ?>
                      <button type="button" class="admin-filter-dropdown__item<?= $selectedSection === $optionValue ? ' is-selected' : '' ?>" data-filter-value="<?= e($optionValue) ?>" role="option" aria-selected="<?= $selectedSection === $optionValue ? 'true' : 'false' ?>"><?= e($optionValue) ?></button>
                    <?php endforeach; ?>
                    <?php if(!empty($divisionGroups['unassigned'] ?? [])): ?>
                      <button type="button" class="admin-filter-dropdown__item<?= $selectedSection === '__unassigned' ? ' is-selected' : '' ?>" data-filter-value="__unassigned" role="option" aria-selected="<?= $selectedSection === '__unassigned' ? 'true' : 'false' ?>">No Section</button>
                    <?php endif; ?>
                  </div>
                </div>
              </label>
            </div>
          <?php endif; ?>
          <div class="table-responsive admin-users-table-wrapper">
            <table class="table workspace-table align-middle mb-0 admin-users-table">
              <thead class="admin-users-table__head">
                <tr>
                  <th>Name</th>
                  <th>Role</th>
                  <th>Email</th>
                  <?php if($canViewPasswords): ?>
                    <th>Password</th>
                  <?php endif; ?>
                  <th>Status</th>
                  <?php if($canShowAccountActions): ?>
                    <th>Actions</th>
                  <?php endif; ?>
                </tr>
              </thead>
              <tbody>
                <?php foreach(($divisionGroups['divisions'] ?? []) as $group): ?>
                  <?php
                    $sectionName = (string)$group['division']['name'];
                    $sectionUserCount = count((array)($group['staff'] ?? [])) + (!empty($group['chief']) ? 1 : 0);
                  ?>
                  <tr class="admin-users-table__section-header" data-section="<?= e($sectionName) ?>">
                    <td colspan="<?= (int)$accountColumnCount ?>">
                      <button
                        class="admin-users-table__section-toggle"
                        type="button"
                        data-section-toggle="<?= e($sectionName) ?>"
                        aria-expanded="true"
                      >
                        <span class="admin-users-table__section-toggle-label">
                          <strong><?= e($sectionName) ?></strong>
                          <small><?= (int)$sectionUserCount ?> account<?= $sectionUserCount === 1 ? '' : 's' ?></small>
                        </span>
                      </button>
                    </td>
                  </tr>
                  <?php if($group['chief']): ?>
                    <tr class="admin-users-table__row<?= $selectedUser && (int)$selectedUser['id'] === (int)$group['chief']['id'] ? ' is-active' : '' ?>" data-section="<?= e($sectionName) ?>" data-user-id="<?= (int)$group['chief']['id'] ?>">
                      <td data-label="Name">
                        <div class="admin-users-table__name">
                          <span class="admin-user-avatar app-user-pill__avatar <?= e(avatar_preset_key($group['chief'])) ?>">
                            <?php $chiefPhoto = avatar_photo_url($group['chief']); ?>
                            <?php if($chiefPhoto): ?>
                              <img src="<?= e($chiefPhoto) ?>" alt="<?= e((string)$group['chief']['name']) ?>">
                            <?php else: ?>
                              <?= e(avatar_initials((string)$group['chief']['name'])) ?>
                            <?php endif; ?>
                          </span>
                          <div>
                            <strong><?= e((string)$group['chief']['name']) ?></strong>
                            <small><?= e(admin_display_code_label($group['chief'])) ?></small>
                          </div>
                        </div>
                      </td>
                      <td data-label="Role"><?= admin_role_badge((string)$group['chief']['role']) ?></td>
                      <td data-label="Email"><?= e((string)$group['chief']['email']) ?></td>
                      <?php if($canViewPasswords): ?>
                        <td data-label="Password"><?= admin_password_cell($group['chief']) ?></td>
                      <?php endif; ?>
                      <td data-label="Status"><?= admin_meta_pill((string)($group['chief']['status'] ?? 'ACTIVE'), admin_status_tone((string)($group['chief']['status'] ?? 'ACTIVE'))) ?></td>
                      <?php if($canShowAccountActions): ?>
                        <td data-label="Actions"><?= admin_account_controls($group['chief'], $visibleDivisions ?? []) ?></td>
                      <?php endif; ?>
                    </tr>
                  <?php endif; ?>
                  <?php foreach($group['staff'] as $u): ?>
                    <tr class="admin-users-table__row<?= $selectedUser && (int)$selectedUser['id'] === (int)$u['id'] ? ' is-active' : '' ?>" data-section="<?= e($sectionName) ?>" data-user-id="<?= (int)$u['id'] ?>">
                      <td data-label="Name">
                        <div class="admin-users-table__name">
                          <span class="admin-user-avatar app-user-pill__avatar <?= e(avatar_preset_key($u)) ?>">
                            <?php $userPhoto = avatar_photo_url($u); ?>
                            <?php if($userPhoto): ?>
                              <img src="<?= e($userPhoto) ?>" alt="<?= e((string)$u['name']) ?>">
                            <?php else: ?>
                              <?= e(avatar_initials((string)$u['name'])) ?>
                            <?php endif; ?>
                          </span>
                          <div>
                            <strong><?= e((string)$u['name']) ?></strong>
                            <small><?= e(admin_display_code_label($u)) ?></small>
                          </div>
                        </div>
                      </td>
                      <td data-label="Role"><?= admin_role_badge((string)$u['role']) ?></td>
                      <td data-label="Email"><?= e((string)$u['email']) ?></td>
                      <?php if($canViewPasswords): ?>
                        <td data-label="Password"><?= admin_password_cell($u) ?></td>
                      <?php endif; ?>
                      <td data-label="Status"><?= admin_meta_pill((string)($u['status'] ?? 'ACTIVE'), admin_status_tone((string)($u['status'] ?? 'ACTIVE'))) ?></td>
                      <?php if($canShowAccountActions): ?>
                        <td data-label="Actions"><?= admin_account_controls($u, $visibleDivisions ?? []) ?></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                <?php endforeach; ?>

                <?php if(!empty($divisionGroups['unassigned'] ?? [])): ?>
                  <?php $unassignedCount = count((array)$divisionGroups['unassigned']); ?>
                  <tr class="admin-users-table__section-header" data-section="__unassigned">
                    <td colspan="<?= (int)$accountColumnCount ?>">
                      <button
                        class="admin-users-table__section-toggle"
                        type="button"
                        data-section-toggle="__unassigned"
                        aria-expanded="true"
                      >
                        <span class="admin-users-table__section-toggle-label">
                          <strong>No Section</strong>
                          <small><?= (int)$unassignedCount ?> account<?= $unassignedCount === 1 ? '' : 's' ?></small>
                        </span>
                      </button>
                    </td>
                  </tr>
                  <?php foreach($divisionGroups['unassigned'] as $u): ?>
                    <tr class="admin-users-table__row<?= $selectedUser && (int)$selectedUser['id'] === (int)$u['id'] ? ' is-active' : '' ?>" data-section="__unassigned" data-user-id="<?= (int)$u['id'] ?>">
                      <td data-label="Name">
                        <div class="admin-users-table__name">
                          <span class="admin-user-avatar app-user-pill__avatar <?= e(avatar_preset_key($u)) ?>">
                            <?php $unassignedPhoto = avatar_photo_url($u); ?>
                            <?php if($unassignedPhoto): ?>
                              <img src="<?= e($unassignedPhoto) ?>" alt="<?= e((string)$u['name']) ?>">
                            <?php else: ?>
                              <?= e(avatar_initials((string)$u['name'])) ?>
                            <?php endif; ?>
                          </span>
                          <div>
                            <strong><?= e((string)$u['name']) ?></strong>
                            <small><?= e(admin_display_code_label($u)) ?></small>
                          </div>
                        </div>
                      </td>
                      <td data-label="Role"><?= admin_role_badge((string)$u['role']) ?></td>
                      <td data-label="Email"><?= e((string)$u['email']) ?></td>
                      <?php if($canViewPasswords): ?>
                        <td data-label="Password"><?= admin_password_cell($u) ?></td>
                      <?php endif; ?>
                      <td data-label="Status"><?= admin_meta_pill((string)($u['status'] ?? 'ACTIVE'), admin_status_tone((string)($u['status'] ?? 'ACTIVE'))) ?></td>
                      <?php if($canShowAccountActions): ?>
                        <td data-label="Actions"><?= admin_account_controls($u, $visibleDivisions ?? []) ?></td>
                      <?php endif; ?>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    </aside>

    <div class="admin-drive-main">
      <?php if($selectedUser): ?>
        <div class="table-card">
          <div class="table-card__header">
            <div>
              <h2><?= e((string)$selectedUser['name']) ?></h2>
              <p class="admin-user-heading-meta"><span><?= e(admin_display_code_label($selectedUser)) ?></span><span><?= e((string)$selectedUser['email']) ?></span><?= admin_role_badge((string)$selectedUser['role']) ?><span><?= e((string)($selectedUser['division_name'] ?? 'No division')) ?></span></p>
            </div>
          </div>
          <div class="table-card__body">
            <details class="admin-collapsible-section" open>
              <summary class="admin-collapsible-section__summary">
                <span>Workspace Overview</span>
                <small>Identity, workload, and key metrics</small>
              </summary>
              <section class="admin-employee-spotlight">
              <div class="admin-employee-spotlight__hero">
                <div class="admin-employee-spotlight__profile">
                  <span class="admin-employee-spotlight__avatar app-user-pill__avatar <?= e($selectedUserPreset) ?>">
                    <?php if($selectedUserPhoto): ?>
                      <img src="<?= e($selectedUserPhoto) ?>" alt="<?= e((string)$selectedUser['name']) ?>">
                    <?php else: ?>
                      <?= e($selectedUserInitials) ?>
                    <?php endif; ?>
                  </span>
                  <div class="admin-employee-spotlight__identity">
                    <p class="admin-employee-spotlight__eyebrow">Employee routing overview</p>
                    <h3><?= e((string)$selectedUser['name']) ?></h3>
                    <p class="admin-user-heading-meta">
                      <?= admin_meta_pill(admin_display_code_label($selectedUser), 'neutral') ?>
                      <?= admin_role_badge((string)($selectedUser['role'] ?? 'SECTION_STAFF')) ?>
                      <?= admin_meta_pill((string)($selectedUser['division_name'] ?? 'No division'), 'info') ?>
                      <?= admin_meta_pill((string)($selectedUser['status'] ?? 'ACTIVE'), admin_status_tone((string)($selectedUser['status'] ?? 'ACTIVE'))) ?>
                      <?= admin_meta_pill('Receiver view only', 'neutral') ?>
                    </p>
                    <div class="admin-employee-spotlight__meta">
                      <span><i class="bi bi-envelope me-1"></i><?= e((string)$selectedUser['email']) ?></span>
                      <span><i class="bi bi-calendar2-week me-1"></i>Joined <?= e($selectedJoined) ?></span>
                      <span><i class="bi bi-clock-history me-1"></i><?= e(admin_view_datetime((string)($selectedActivity['last_seen_at'] ?? ''), 'No sign-in log yet')) ?></span>
                    </div>
                  </div>
                </div>
                <div class="admin-employee-spotlight__highlights">
                  <article class="admin-overview-stat admin-overview-stat--documents">
                    <span class="admin-overview-stat__label">Routed documents</span>
                    <strong><?= (int)($selectedSummary['document_count'] ?? 0) ?></strong>
                    <small>documents currently assigned or shared to this account</small>
                  </article>
                  <article class="admin-overview-stat admin-overview-stat--tracking">
                    <span class="admin-overview-stat__label">Tracking</span>
                    <strong><?= (int)($selectedSummary['tracking_count'] ?? 0) ?></strong>
                    <small>admin-routed records tied to this staff member</small>
                  </article>
                  <article class="admin-overview-stat admin-overview-stat--workflow">
                    <span class="admin-overview-stat__label">Under workflow</span>
                    <strong><?= (int)($selectedSummary['under_workflow_count'] ?? 0) ?></strong>
                    <small>documents currently moving through review handling</small>
                  </article>
                </div>
              </div>

              <div class="admin-overview-metrics">
                <article class="admin-overview-metric-card admin-overview-metric-card--active">
                  <span>Active routes</span>
                  <strong><?= (int)($selectedSummary['active_count'] ?? 0) ?></strong>
                  <small>routed files still active for this receiver</small>
                </article>
                <article class="admin-overview-metric-card admin-overview-metric-card--workflow">
                  <span>Under workflow</span>
                  <strong><?= (int)($selectedSummary['under_workflow_count'] ?? 0) ?></strong>
                  <small>routes currently being processed in review</small>
                </article>
                <article class="admin-overview-metric-card admin-overview-metric-card--completed">
                  <span>Completed routes</span>
                  <strong><?= (int)($selectedSummary['completed_count'] ?? 0) ?></strong>
                  <small>routes already finalized by the workflow</small>
                </article>
              </div>

              <div class="admin-employee-panels">
                <details class="admin-employee-panel admin-collapsible-section" open>
                  <summary class="admin-collapsible-section__summary admin-collapsible-section__summary--panel">
                    <span>Routed Files</span>
                  </summary>
                  <div class="admin-file-snapshot-list admin-collapsible-section__body">
                    <?php if(!empty($selectedGroup['allDocuments'] ?? [])): ?>
                      <?php foreach(($selectedGroup['allDocuments'] ?? []) as $document): ?>
                        <article class="admin-file-snapshot">
                          <div class="admin-file-snapshot__main">
                            <div class="admin-file-snapshot__title-row">
                              <strong><?= e((string)$document['name']) ?></strong>
                            </div>
                          </div>
                          <div class="admin-file-snapshot__stats">
                            <span><?= e(admin_view_datetime((string)($document['last_route_at'] ?? ''), 'No routed timestamp')) ?></span>
                          </div>
                        </article>
                      <?php endforeach; ?>
                    <?php else: ?>
                      <p class="admin-empty-state">No routed files are assigned to this account yet.</p>
                    <?php endif; ?>
                  </div>
                </details>
              </div>
            </section>
            </details>

          </div>
        </div>
      <?php else: ?>
        <div class="table-card">
          <div class="table-card__body">
            <p class="admin-empty-state mb-0">Select an account from the Accounts list to view the routed documents assigned to that staff member.</p>
          </div>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  const nameInput = document.getElementById('admin-create-name');
  const emailHidden = document.getElementById('admin-create-email-hidden');
  const passwordHidden = document.getElementById('admin-create-password-hidden');
  const roleSelect = document.getElementById('admin-create-role');
  const rolePicker = document.getElementById('admin-role-picker');
  const roleButtons = Array.from(document.querySelectorAll('[data-role-option]'));
  const roleMeta = document.getElementById('admin-create-role-meta');
  const divisionSelect = document.getElementById('admin-create-division');
  const divisionHidden = document.getElementById('admin-create-division-hidden');
  const divisionMeta = document.getElementById('admin-create-division-meta');
  const emailOptionsContainer = document.getElementById('admin-email-options');
  const passwordOptionsContainer = document.getElementById('admin-password-options');
  const form = document.getElementById('admin-create-account-form');

  function generateEmail(name) {
    const trimmed = name.trim();
    if (!trimmed) return '';
    
    const parts = trimmed.split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '';
    
    const firstName = parts[0].toLowerCase();
    const lastInitial = (parts[parts.length - 1] || '').charAt(0).toLowerCase();
    
    return firstName + lastInitial + '@cdd.com';
  }

  function generatePassword(name) {
    const trimmed = name.trim();
    if (!trimmed) return '';
    
    const parts = trimmed.split(/\s+/).filter(Boolean);
    if (parts.length === 0) return '';
    
    const firstName = parts[0];
    const firstChar = firstName.charAt(0).toUpperCase();
    const rest = firstName.slice(1).toLowerCase();
    const year = new Date().getFullYear();
    
    return firstChar + rest + '@' + year;
  }

  function renderValue(container, value) {
    container.innerHTML = '';
    if (!value) {
      return;
    }

    const optionDiv = document.createElement('div');
    optionDiv.className = 'admin-suggestion-option';
    optionDiv.innerHTML = `
      <div class="admin-suggestion-option__label">
        <span class="admin-suggestion-option__display">${value}</span>
        <button type="button" class="admin-copy-btn-inline" data-copy-text="${value}" aria-label="Copy"><span>Copy</span></button>
      </div>
    `;
    container.appendChild(optionDiv);
  }

  function updateSuggestions() {
    const name = nameInput?.value || '';
    const emailValue = generateEmail(name);
    const passwordValue = generatePassword(name);

    renderValue(emailOptionsContainer, emailValue);
    renderValue(passwordOptionsContainer, passwordValue);

    emailHidden.value = emailValue;
    passwordHidden.value = passwordValue;

    attachCopyListeners();
  }

  function attachCopyListeners() {
    document.querySelectorAll('.admin-copy-btn-inline').forEach(button => {
      button.addEventListener('click', function(e) {
        e.preventDefault();
        const copyText = this.getAttribute('data-copy-text');
        navigator.clipboard.writeText(copyText).then(() => {
          const originalText = this.querySelector('span').textContent;
          this.querySelector('span').textContent = 'Copied!';
          setTimeout(() => {
            this.querySelector('span').textContent = originalText;
          }, 1500);
        });
      });
    });
  }

  function syncRoleAndDivisionState() {
    if (!roleSelect || !divisionSelect || !divisionHidden) {
      return;
    }

    const selectedRole = roleSelect.value;
    const isSuperAdmin = selectedRole === 'SUPER_ADMIN';
    const selectedRoleButton = roleButtons.find(function(button) {
      return button.getAttribute('data-role-option') === selectedRole;
    });
    const roleCaption = selectedRoleButton ? (selectedRoleButton.getAttribute('data-role-caption') || '') : '';

    roleButtons.forEach(function(button) {
      const isActive = button.getAttribute('data-role-option') === selectedRole;
      button.classList.toggle('is-active', isActive);
      button.setAttribute('aria-pressed', isActive ? 'true' : 'false');
    });

    if (roleMeta) {
      roleMeta.textContent = roleCaption;
    }

    if (isSuperAdmin) {
      divisionSelect.value = '0';
      divisionSelect.disabled = true;
      divisionSelect.removeAttribute('name');
      divisionHidden.name = 'division_id';
      divisionHidden.value = '0';
      divisionSelect.dataset.locked = '1';
      if (divisionMeta) {
        divisionMeta.textContent = 'Locked to CDD Super Admin. Division assignment is disabled.';
      }
      return;
    }

    divisionSelect.disabled = false;
    divisionSelect.name = 'division_id';
    divisionHidden.name = '';
    divisionHidden.value = '';
    delete divisionSelect.dataset.locked;
    if (divisionMeta) {
      divisionMeta.textContent = selectedRole === 'SECTION_ADMIN'
        ? 'Choose the division this section admin will lead.'
        : 'Choose the section this account belongs to.';
    }
  }

  if (nameInput) {
    nameInput.addEventListener('input', updateSuggestions);
  }

  roleButtons.forEach(function(button) {
    button.addEventListener('click', function() {
      if (!roleSelect) {
        return;
      }
      roleSelect.value = button.getAttribute('data-role-option') || 'SECTION_STAFF';
      syncRoleAndDivisionState();
    });
  });

  rolePicker?.addEventListener('keydown', function(event) {
    if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown'].includes(event.key)) {
      return;
    }
    event.preventDefault();
    const currentIndex = roleButtons.findIndex(function(button) {
      return button.classList.contains('is-active');
    });
    if (currentIndex === -1) {
      return;
    }
    const direction = (event.key === 'ArrowRight' || event.key === 'ArrowDown') ? 1 : -1;
    const nextIndex = (currentIndex + direction + roleButtons.length) % roleButtons.length;
    roleButtons[nextIndex].focus();
    roleButtons[nextIndex].click();
  });

  if (form) {
    form.addEventListener('submit', function() {
      const name = nameInput?.value || '';
      emailHidden.value = generateEmail(name);
      passwordHidden.value = generatePassword(name);
      syncRoleAndDivisionState();
    });
  }

  updateSuggestions();
  syncRoleAndDivisionState();
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  document.querySelectorAll('.admin-confirm-form').forEach(function(form) {
    if (form.dataset.adminConfirmBound === '1') {
      return;
    }
    form.dataset.adminConfirmBound = '1';
    form.addEventListener('submit', async function(event) {
      if (form.dataset.confirmed === '1') {
        form.dataset.confirmed = '0';
        return;
      }

      event.preventDefault();
      const label = form.getAttribute('data-confirm-label') || 'continue';
      const result = await window.cddftsConfirmModal({
        title: 'Confirm password',
        message: form.getAttribute('data-confirm-message') || 'Enter your super admin password to ' + label + '.',
        confirmText: 'Confirm',
        requirePassword: true,
        passwordLabel: 'Super admin password'
      });
      if (!result.ok || !result.password) {
        return;
      }

      const hidden = form.querySelector('input[name="confirm_password"]');
      if (hidden) {
        hidden.value = result.password;
      }
      form.dataset.confirmed = '1';
      HTMLFormElement.prototype.submit.call(form);
    });
  });

  document.querySelectorAll('[data-password-input]').forEach(function(input) {
    input.addEventListener('dblclick', function() {
      input.type = input.type === 'password' ? 'text' : 'password';
    });
  });

  const manageMenus = document.querySelectorAll('.admin-account-row-controls');
  manageMenus.forEach(function(menu) {
    const summary = menu.querySelector('summary');
    if (!summary) {
      return;
    }

    summary.addEventListener('click', function() {
      manageMenus.forEach(function(otherMenu) {
        if (otherMenu !== menu) {
          otherMenu.removeAttribute('open');
        }
      });
    });
  });

  document.addEventListener('click', function(event) {
    if (event.target.closest('.admin-account-row-controls')) {
      return;
    }
    manageMenus.forEach(function(menu) {
      menu.removeAttribute('open');
    });
  });

  document.addEventListener('keydown', function(event) {
    if (event.key !== 'Escape') {
      return;
    }
    manageMenus.forEach(function(menu) {
      menu.removeAttribute('open');
    });
  });
});
</script>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const csrfToken = '<?= e(csrf_token()) ?>';
  document.querySelectorAll('[data-password-reveal]').forEach(function(button) {
    button.addEventListener('click', async function(event) {
      event.preventDefault();
      event.stopPropagation();
      const userId = button.getAttribute('data-user-id') || '';
      const userName = button.getAttribute('data-user-name') || 'this account';
      const userEmail = button.getAttribute('data-user-email') || '';
      if (!userId) {
        return;
      }

      const result = await window.cddftsConfirmModal({
        title: 'Reveal Stored Password',
        message: 'Confirm your password to reveal the stored password for ' + userName + (userEmail ? ' (' + userEmail + ')' : '') + '.',
        helperText: 'Only generated and stored passwords can be revealed from this screen.',
        warningText: 'This will display the password in plain text.',
        confirmText: 'Reveal Password',
        requirePassword: true,
        passwordLabel: 'Confirm your password'
      });
      if (!result.ok || !result.password) {
        return;
      }

      const body = new URLSearchParams();
      body.set('_csrf', csrfToken);
      body.set('id', userId);
      body.set('confirm_password', result.password);
      const response = await fetch('<?= BASE_URL ?>/admin/users/password/reveal', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: body.toString()
      });
      const payload = await response.json().catch(function() { return {}; });
      if (!response.ok || !payload.ok) {
        alert(payload.error === 'password_not_stored' ? 'No stored generated password is available for this account yet. Reset the password to store one.' : 'Password confirmation failed.');
        return;
      }

      const cell = button.closest('td');
      const mask = cell ? cell.querySelector('[data-password-mask]') : null;
      if (mask) {
        mask.textContent = payload.password;
        mask.classList.add('is-revealed');
      }
      button.innerHTML = '<i class="bi bi-eye-slash"></i>';
      button.setAttribute('aria-label', 'Password revealed');
      button.disabled = true;
    });
  });

  const filterSelect = document.getElementById('admin-section-filter');
  const filterDropdown = document.getElementById('admin-section-filter-dropdown');
  const filterTrigger = document.getElementById('admin-section-filter-trigger');
  const filterValue = document.getElementById('admin-section-filter-value');
  const filterMenu = document.getElementById('admin-section-filter-menu');
  const filterItems = Array.from(document.querySelectorAll('[data-filter-value]'));
  const tableRows = document.querySelectorAll('.admin-users-table tbody tr');
  const rowButtons = document.querySelectorAll('.admin-users-table tbody .admin-users-table__row');
  const sectionHeaders = document.querySelectorAll('.admin-users-table tbody .admin-users-table__section-header');
  const sectionToggleButtons = document.querySelectorAll('[data-section-toggle]');
  const activeRow = document.querySelector('.admin-users-table tbody .admin-users-table__row.is-active');
  const sectionStorageKey = 'cddfts-admin-collapsed-sections';
  const collapsedSections = new Set();

  try {
    const storedSections = JSON.parse(localStorage.getItem(sectionStorageKey) || '[]');
    if (Array.isArray(storedSections)) {
      storedSections.forEach(function(sectionName) {
        if (typeof sectionName === 'string' && sectionName !== '') {
          collapsedSections.add(sectionName);
        }
      });
    }
  } catch (_err) {}

  if (activeRow) {
    const activeSection = activeRow.getAttribute('data-section') || '';
    if (activeSection !== '') {
      collapsedSections.delete(activeSection);
    }
  }

  function persistCollapsedSections() {
    try {
      localStorage.setItem(sectionStorageKey, JSON.stringify(Array.from(collapsedSections)));
    } catch (_err) {}
  }

  function syncSectionToggleState() {
    sectionToggleButtons.forEach(function(button) {
      const sectionName = button.getAttribute('data-section-toggle') || '';
      button.setAttribute('aria-expanded', collapsedSections.has(sectionName) ? 'false' : 'true');
    });
  }

  function closeFilterMenu() {
    if (!filterDropdown || !filterTrigger || !filterMenu) {
      return;
    }
    filterDropdown.classList.remove('is-open');
    filterTrigger.setAttribute('aria-expanded', 'false');
    filterMenu.hidden = true;
  }

  function openFilterMenu() {
    if (!filterDropdown || !filterTrigger || !filterMenu) {
      return;
    }
    filterDropdown.classList.add('is-open');
    filterTrigger.setAttribute('aria-expanded', 'true');
    filterMenu.hidden = false;
  }

  function syncFilterDropdownUI(value) {
    filterItems.forEach(function(item) {
      const isSelected = item.getAttribute('data-filter-value') === value;
      item.classList.toggle('is-selected', isSelected);
      item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      if (isSelected && filterValue) {
        filterValue.textContent = item.textContent || '';
      }
    });
  }
  
  // Function to apply the filter
  function applyFilter(selectedSection) {
    tableRows.forEach(row => {
      const rowSection = row.getAttribute('data-section');
      const matchesFilter = selectedSection === '' || rowSection === selectedSection;
      const isHeader = row.classList.contains('admin-users-table__section-header');
      const isCollapsed = rowSection !== null && collapsedSections.has(rowSection);
      if (matchesFilter && (!isCollapsed || isHeader)) {
        row.style.display = '';
      } else {
        row.style.display = 'none';
      }
    });

    syncSectionToggleState();
  }
  
  // Apply initial filter on page load (just UI, no redirect)
  applyFilter(filterSelect?.value || '');
  syncFilterDropdownUI(filterSelect?.value || '');

  filterTrigger?.addEventListener('click', function() {
    if (filterMenu?.hidden) {
      openFilterMenu();
      return;
    }
    closeFilterMenu();
  });

  filterItems.forEach(function(item) {
    item.addEventListener('click', function() {
      if (!filterSelect) {
        return;
      }
      const nextValue = item.getAttribute('data-filter-value') || '';
      filterSelect.value = nextValue;
      syncFilterDropdownUI(nextValue);
      closeFilterMenu();
      filterSelect.dispatchEvent(new Event('change', { bubbles: true }));
    });
  });

  document.addEventListener('click', function(event) {
    if (event.target.closest('#admin-section-filter-dropdown')) {
      return;
    }
    closeFilterMenu();
  });

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      closeFilterMenu();
    }
  });

  sectionToggleButtons.forEach(function(button) {
    button.addEventListener('click', function(event) {
      event.preventDefault();
      event.stopPropagation();
      const sectionName = button.getAttribute('data-section-toggle') || '';
      if (sectionName === '') {
        return;
      }

      if (collapsedSections.has(sectionName)) {
        collapsedSections.delete(sectionName);
      } else {
        collapsedSections.add(sectionName);
      }

      persistCollapsedSections();
      applyFilter(filterSelect?.value || '');
    });
  });
  
  // Apply filter on dropdown change and check if selected account is hidden
  filterSelect?.addEventListener('change', function(e) {
    syncFilterDropdownUI(e.target.value);
    applyFilter(e.target.value);
    
    // After filter is applied, check if active row is now hidden
    const currentActiveRow = document.querySelector('.admin-users-table tbody .admin-users-table__row.is-active');
    if (currentActiveRow && window.getComputedStyle(currentActiveRow).display === 'none') {
      // Selected account is now hidden by the filter, so switch to the first visible account instead.
      const selectedSection = e.target.value;
      const firstVisibleRow = Array.from(rowButtons).find(function(row) {
        return window.getComputedStyle(row).display !== 'none';
      });
      const params = new URLSearchParams();
      if (firstVisibleRow) {
        const nextUserId = firstVisibleRow.getAttribute('data-user-id');
        if (nextUserId) {
          params.set('user_id', nextUserId);
        }
      }
      if (selectedSection) params.set('section', selectedSection);
      window.location.href = '<?= BASE_URL ?>/admin/users?' + params.toString();
    }
  });
  
  // Add click handlers to rows to preserve section filter in URL
  rowButtons.forEach(row => {
    row.style.cursor = 'pointer';
    row.addEventListener('click', function(e) {
      if (e.target.closest('button, a, input, select, label, form, details, summary')) {
        return;
      }
      const userId = row.getAttribute('data-user-id');
      const section = filterSelect?.value || '';
      if (userId) {
        const params = new URLSearchParams();
        params.set('user_id', userId);
        if (section) params.set('section', section);
        window.location.href = '<?= BASE_URL ?>/admin/users?' + params.toString();
      }
    });
  });
});
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
