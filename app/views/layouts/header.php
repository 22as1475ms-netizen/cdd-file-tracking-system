<?php
require_once __DIR__ . "/../../config/app.php";
require_once __DIR__ . "/../../helpers/http.php";
require_once __DIR__ . "/../../models/User.php";

$currentPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$baseRootPath = BASE_URL !== '' ? BASE_URL : '/';
$isAuthPage = $currentPath === $baseRootPath
  || $currentPath === rtrim($baseRootPath, '/') . '/'
  || $currentPath === (BASE_URL . '/login')
  || str_ends_with($currentPath, '/login');
$isWorkspacePage = $currentPath === (BASE_URL . '/documents') || str_ends_with($currentPath, '/documents');
$user = $_SESSION['user'] ?? null;
$userRole = strtoupper((string)($user['role'] ?? ''));
$isAdminArea = $user && in_array($userRole, ['SUPER_ADMIN', 'ADMIN', 'SECTION_ADMIN'], true);
$initials = $user ? avatar_initials((string)$user['name']) : '';
$avatarPhoto = $user ? avatar_photo_url($user) : null;
$avatarPreset = $user ? avatar_preset_key($user) : 'preset-ocean';
$headerRoleLabel = $user ? role_label((string)$user['role']) : '';
if ($user && $userRole === 'SECTION_ADMIN') {
  $divisionName = trim((string)($user['division_name'] ?? ''));
  if ($divisionName !== '') {
    $headerRoleLabel = $divisionName . ' Section Admin';
  }
}
$onboardingVersionMap = [
  'SUPER_ADMIN' => 'super-admin-v1',
  'ADMIN' => 'admin-v1',
  'SECTION_ADMIN' => 'section-admin-v1',
  'SECTION_STAFF' => 'section-staff-v1',
  'DIVISION_CHIEF' => 'division-chief-v1',
  'EMPLOYEE' => 'employee-v1',
];
$currentGuideVersion = $user ? ($onboardingVersionMap[strtoupper((string)($user['role'] ?? 'EMPLOYEE'))] ?? 'employee-v1') : '';
$shouldAutoOpenGuide = $user && (($user['onboarding_guide_version'] ?? '') !== $currentGuideVersion);
$unreadCount = 0;
$unreadItems = [];
$notificationTone = static function (array $n): array {
  $haystack = strtolower(trim(((string)($n['title'] ?? '')) . ' ' . ((string)($n['body'] ?? ''))));
  if ($haystack !== '' && (str_contains($haystack, 'reject') || str_contains($haystack, 'denied') || str_contains($haystack, 'failed') || str_contains($haystack, 'error'))) {
    return ['danger', 'bi-x-circle-fill'];
  }
  if ($haystack !== '' && (str_contains($haystack, 'approved') || str_contains($haystack, 'accepted') || str_contains($haystack, 'success'))) {
    return ['success', 'bi-check-circle-fill'];
  }
  if ($haystack !== '' && (str_contains($haystack, 'review') || str_contains($haystack, 'pending') || str_contains($haystack, 'request'))) {
    return ['warning', 'bi-exclamation-circle-fill'];
  }
  return ['info', 'bi-bell-fill'];
};
$notificationBootstrapItems = [];
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= e(APP_NAME) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&display=swap" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css" rel="stylesheet">
  <script>
    (function () {
      try {
        var storedMode = localStorage.getItem('cddfts-color-mode');
        var storedPerformanceMode = localStorage.getItem('cddfts-performance-mode');
        var validPerformanceModes = ['auto', 'lite', 'full'];
        var performanceMode = validPerformanceModes.indexOf(storedPerformanceMode) >= 0 ? storedPerformanceMode : 'auto';
        var prefersReducedMotion = false;
        var saveData = false;
        var deviceMemory = 8;
        var hardwareConcurrency = 8;
        try {
          prefersReducedMotion = !!(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
          saveData = !!(navigator.connection && navigator.connection.saveData);
          deviceMemory = Number(navigator.deviceMemory || 8);
          hardwareConcurrency = Number(navigator.hardwareConcurrency || 8);
        } catch (_perfErr) {}
        var computedPerformanceTier = performanceMode === 'lite'
          ? 'lite'
          : (performanceMode === 'full'
            ? 'full'
            : ((prefersReducedMotion || saveData || deviceMemory <= 4 || hardwareConcurrency <= 4) ? 'lite' : 'full'));
        document.documentElement.setAttribute('data-color-mode', storedMode === 'dark' ? 'dark' : 'light');
        document.documentElement.setAttribute('data-performance-mode', performanceMode);
        document.documentElement.setAttribute('data-performance-tier', computedPerformanceTier);
        window.cddftsPerformance = {
          mode: performanceMode,
          tier: computedPerformanceTier,
          prefersReducedMotion: prefersReducedMotion,
          saveData: saveData,
          deviceMemory: deviceMemory,
          hardwareConcurrency: hardwareConcurrency
        };
      } catch (_err) {
        document.documentElement.setAttribute('data-color-mode', 'light');
        document.documentElement.setAttribute('data-performance-mode', 'auto');
        document.documentElement.setAttribute('data-performance-tier', 'full');
      }
    })();
  </script>
  <link href="<?= BASE_URL ?>/assets/css/app.css" rel="stylesheet">
</head>
<body class="app-body <?= $isAuthPage ? 'app-body--auth' : '' ?>">
<?php if(!$isAuthPage): ?>
<nav class="navbar navbar-expand-lg app-nav">
  <div class="container-fluid px-4 px-lg-5">
    <div class="app-nav__inner w-100">
      <a class="app-brand" href="<?= BASE_URL . workspace_home_path() ?>">
        <span class="app-brand__logo">
          <img src="<?= BASE_URL ?>/assets/images/logo.png" alt="<?= e(APP_NAME) ?> logo">
        </span>
        <span>
          <span class="d-block app-brand__name"><?= e(APP_NAME) ?></span>
          <span class="d-block app-brand__meta">Workspace</span>
        </span>
      </a>

      <?php if($isAdminArea): ?>
        <nav class="app-nav__admin" aria-label="Admin navigation">
          <a class="app-nav__admin-link <?= $currentPath === BASE_URL . '/admin/dashboard' ? 'is-active' : '' ?>" href="<?= BASE_URL ?>/admin/dashboard"><i class="bi bi-folder2-open"></i><span>Route Files</span></a>
          <a class="app-nav__admin-link <?= $currentPath === BASE_URL . '/admin/users' ? 'is-active' : '' ?>" href="<?= BASE_URL ?>/admin/users"><i class="bi bi-people"></i><span>Accounts</span></a>
          <?php if(in_array($userRole, ['SUPER_ADMIN', 'ADMIN'], true)): ?>
            <a class="app-nav__admin-link <?= $currentPath === BASE_URL . '/admin/logs' ? 'is-active' : '' ?>" href="<?= BASE_URL ?>/admin/logs"><i class="bi bi-clipboard-data"></i><span>Audit Logs</span></a>
          <?php endif; ?>
        </nav>
      <?php endif; ?>

      <?php if($user && $isWorkspacePage): ?>
        <?php
          $navTab = trim((string)($_GET['tab'] ?? 'private'));
          $navUserId = (int)($_GET['user_id'] ?? 0);
        ?>
        <form class="app-nav-search" method="GET" action="<?= BASE_URL ?>/documents">
          <input type="hidden" name="tab" value="<?= e($navTab !== '' ? $navTab : 'private') ?>">
          <?php if($navUserId > 0 && $navTab !== 'shared'): ?>
            <input type="hidden" name="user_id" value="<?= (int)$navUserId ?>">
          <?php endif; ?>
          <span class="app-nav-search__shell">
            <i class="bi bi-search app-nav-search__icon" aria-hidden="true"></i>
            <input class="form-control app-nav-search__input" type="search" name="q" placeholder="Search tabs, files, folders, settings" value="<?= e((string)($_GET['q'] ?? '')) ?>" autocomplete="off" aria-expanded="false" aria-controls="app-nav-search-suggestions">
            <button class="btn btn-primary btn-sm app-nav-search__btn" type="submit">Search</button>
            <div class="app-nav-search__suggestions d-none" id="app-nav-search-suggestions" role="listbox" aria-label="Search suggestions"></div>
          </span>
        </form>
      <?php endif; ?>

      <div class="app-nav__cluster">
        <?php if($user): ?>
          <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm app-notification-btn" data-bs-toggle="dropdown" type="button" id="app-alert-toggle" aria-label="Notifications">
              <i class="bi bi-bell"></i>
              <span id="app-alert-dot" class="app-notification-dot <?= $unreadCount > 0 ? '' : 'd-none' ?>" aria-hidden="true"></span>
              <span id="app-alert-count" class="visually-hidden"><?= (int)$unreadCount ?></span>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-2 app-notification-menu" style="min-width: 340px;">
              <div id="app-alert-items">
                <?php if(empty($unreadItems)): ?>
                  <div class="text-muted small px-2 py-1">No unread notifications.</div>
                <?php else: ?>
                    <?php foreach($unreadItems as $n): ?>
                      <?php [$tone, $icon] = $notificationTone($n); ?>
                      <?php $rawLink = trim(Notification::resolveDestination($n)); ?>
                    <?php if($rawLink !== ''): ?>
                      <?php $href = (str_starts_with($rawLink, 'http://') || str_starts_with($rawLink, 'https://')) ? $rawLink : (BASE_URL . (str_starts_with($rawLink, '/') ? $rawLink : ('/' . $rawLink))); ?>
                      <a class="dropdown-item app-notification-item app-notification-item--<?= e($tone) ?> <?= (int)($n['is_read'] ?? 0) === 1 ? 'is-read' : '' ?>" href="<?= e($href) ?>">
                        <span class="app-notification-item__icon"><i class="bi <?= e($icon) ?>"></i></span>
                        <span class="app-notification-item__content">
                          <strong><?= e((string)$n['title']) ?></strong>
                          <small><?= e((string)($n['body'] ?? '')) ?></small>
                        </span>
                      </a>
                    <?php else: ?>
                      <div class="dropdown-item app-notification-item app-notification-item--<?= e($tone) ?> <?= (int)($n['is_read'] ?? 0) === 1 ? 'is-read' : '' ?>">
                        <span class="app-notification-item__icon"><i class="bi <?= e($icon) ?>"></i></span>
                        <span class="app-notification-item__content">
                          <strong><?= e((string)$n['title']) ?></strong>
                          <small><?= e((string)($n['body'] ?? '')) ?></small>
                        </span>
                      </div>
                    <?php endif; ?>
                  <?php endforeach; ?>
                <?php endif; ?>
              </div>
              <div class="dropdown-divider"></div>
              <form method="POST" action="<?= BASE_URL ?>/notifications/read" class="mb-1" id="mark-all-read-form">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-light w-100">Mark all read</button>
              </form>
              <form method="POST" action="<?= BASE_URL ?>/notifications/clear" id="clear-all-notifications-form">
                <?= csrf_field() ?>
                <button class="btn btn-sm btn-outline-danger w-100">Clear all notifications</button>
              </form>
            </div>
          </div>
          <div class="dropdown">
            <button class="app-user-pill app-user-pill--button" data-bs-toggle="dropdown" type="button" aria-expanded="false">
              <span class="app-user-pill__avatar <?= e($avatarPreset) ?>">
                <?php if($avatarPhoto): ?>
                  <img src="<?= e($avatarPhoto) ?>" alt="<?= e($user['name']) ?>">
                <?php else: ?>
                  <?= e($initials) ?>
                <?php endif; ?>
              </span>
              <span class="app-user-pill__meta">
                <strong><?= e($user['name']) ?></strong>
                <span><?= e($headerRoleLabel) ?></span>
              </span>
              <i class="bi bi-chevron-down ms-1"></i>
            </button>
            <div class="dropdown-menu dropdown-menu-end p-2">
              <a class="dropdown-item" href="<?= BASE_URL ?>/account/password"><i class="bi bi-person-gear me-2"></i>Profile</a>
              <button class="dropdown-item app-theme-toggle" type="button" id="toggle-color-mode" role="switch" aria-checked="false">
                <span class="app-theme-toggle__copy">
                  <i class="bi bi-moon-stars" id="toggle-color-mode-icon"></i>
                  <span>
                    <strong>Dark mode</strong>
                    <small id="toggle-color-mode-label">Off</small>
                  </span>
                </span>
                <span class="app-theme-toggle__switch" aria-hidden="true"><span class="app-theme-toggle__thumb"></span></span>
              </button>
              <form method="POST" action="<?= BASE_URL ?>/logout" class="js-confirm m-0" data-confirm-message="Log out now?">
                <?= csrf_field() ?>
                <button type="submit" class="dropdown-item text-danger"><i class="bi bi-box-arrow-right me-2"></i>Logout</button>
              </form>
            </div>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>
<?php endif; ?>
<script>
  (function () {
    const navSearchForm = document.querySelector('.app-nav-search');
    if (!navSearchForm) return;

    const navSearchInput = navSearchForm.querySelector('input[name="q"]');
    const suggestionPanel = document.getElementById('app-nav-search-suggestions');
    if (!navSearchInput) return;

    let lastSubmittedValue = String(navSearchInput.value || '');
    let latestSuggestionQuery = '';

    function getNavSearchDataset() {
      if (!Array.isArray(window.cddftsNavSearchDataset)) {
        return [];
      }
      return window.cddftsNavSearchDataset;
    }

    function escapeHtml(value) {
      return String(value == null ? '' : value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
    }

    function closeSuggestions() {
      if (!suggestionPanel) return;
      suggestionPanel.classList.add('d-none');
      suggestionPanel.innerHTML = '';
      navSearchInput.setAttribute('aria-expanded', 'false');
    }

    function openSuggestions() {
      if (!suggestionPanel) return;
      suggestionPanel.classList.remove('d-none');
      navSearchInput.setAttribute('aria-expanded', 'true');
    }

    function buildSuggestionMarkup(items) {
      return items.map(function (item) {
        if (item.action) {
          return ''
            + '<button class="app-nav-search__suggestion app-nav-search__suggestion--button" type="button" data-search-action="' + escapeHtml(item.action) + '" role="option">'
            + '  <span class="app-nav-search__suggestion-copy">'
            + '    <strong>' + escapeHtml(item.label || '') + '</strong>'
            + '    <small>' + escapeHtml(item.meta || '') + '</small>'
            + '  </span>'
            + '  <i class="bi bi-magic app-nav-search__suggestion-icon" aria-hidden="true"></i>'
            + '</button>';
        }
        return ''
          + '<a class="app-nav-search__suggestion" href="' + escapeHtml(item.href || '#') + '" role="option">'
          + '  <span class="app-nav-search__suggestion-copy">'
          + '    <strong>' + escapeHtml(item.label || '') + '</strong>'
          + '    <small>' + escapeHtml(item.meta || '') + '</small>'
          + '  </span>'
          + '  <i class="bi bi-arrow-up-right app-nav-search__suggestion-icon" aria-hidden="true"></i>'
          + '</a>';
      }).join('');
    }

    function renderSuggestions(query) {
      if (!suggestionPanel) return;
      const trimmedQuery = String(query || '').trim().toLowerCase();
      latestSuggestionQuery = trimmedQuery;
      if (trimmedQuery.length < 1) {
        closeSuggestions();
        return;
      }

      const matches = getNavSearchDataset().filter(function (item) {
        const haystack = String(item.keywords || '').toLowerCase();
        return haystack.indexOf(trimmedQuery) !== -1;
      }).slice(0, 8);

      if (!matches.length) {
        suggestionPanel.innerHTML = '<div class="app-nav-search__empty">No matching items yet.</div>';
        openSuggestions();
        return;
      }

      suggestionPanel.innerHTML = buildSuggestionMarkup(matches);
      openSuggestions();
    }

    navSearchInput.addEventListener('input', function () {
      renderSuggestions(navSearchInput.value);
    });

    navSearchInput.addEventListener('keydown', function (event) {
      if (event.key !== 'Enter') {
        return;
      }
      lastSubmittedValue = '__force_submit__';
      closeSuggestions();
    });

    navSearchForm.addEventListener('submit', function () {
      closeSuggestions();
      lastSubmittedValue = String(navSearchInput.value || '');
    });

    if (suggestionPanel) {
      suggestionPanel.addEventListener('click', function (event) {
        const actionTrigger = event.target.closest('[data-search-action]');
        if (!actionTrigger) {
          return;
        }
        const action = actionTrigger.getAttribute('data-search-action') || '';
        closeSuggestions();
        if (action === 'toggle-dark-mode') {
          const toggleColorModeBtn = document.getElementById('toggle-color-mode');
          if (toggleColorModeBtn) {
            toggleColorModeBtn.click();
          }
          return;
        }
        if (action === 'logout') {
          const logoutForm = document.querySelector('form[action="<?= BASE_URL ?>/logout"]');
          if (logoutForm) {
            if (typeof logoutForm.requestSubmit === 'function') {
              logoutForm.requestSubmit();
              return;
            }
            logoutForm.submit();
          }
        }
      });
    }

    navSearchInput.addEventListener('focus', function () {
      renderSuggestions(navSearchInput.value);
    });

    document.addEventListener('click', function (event) {
      if (navSearchForm.contains(event.target)) {
        return;
      }
      closeSuggestions();
    });

    window.addEventListener('cddfts-nav-search-data-ready', function () {
      if (document.activeElement === navSearchInput || latestSuggestionQuery) {
        renderSuggestions(navSearchInput.value);
      }
    });
  })();
</script>
<div class="container-fluid app-shell">
<script>
  window.cddftsInitialNotifications = <?= json_encode($notificationBootstrapItems, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  window.cddftsNotificationViewerId = <?= (int)($user['id'] ?? 0) ?>;
</script>
