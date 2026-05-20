<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
$users = $users ?? [];
$selectedUser = $selectedUser ?? null;
$selectedUserId = (int)($selectedUserId ?? 0);
$logs = $logs ?? [];
$days = $days ?? [];
$summary = $summary ?? ['total' => 0, 'document_events' => 0, 'sharing_events' => 0];
$selectedMonth = (string)($selectedMonth ?? date('Y-m'));
$selectedCategory = (string)($selectedCategory ?? 'ALL');
$categories = $categories ?? [];
$selectedMonthLabel = (string)($selectedMonthLabel ?? '');

if (!function_exists('admin_logs_display_code_label')) {
  function admin_logs_display_code_label(array $user): string {
    $code = trim((string)($user['display_code'] ?? ''));
    if ($code !== '') {
      return $code;
    }

    return 'USR-' . str_pad((string)((int)($user['id'] ?? 0)), 3, '0', STR_PAD_LEFT);
  }
}
?>

<div class="workspace-page">
  <section class="workspace-toolbar">
    <div>
      <div class="section-eyebrow">Administration</div>
      <h1 class="drive-title">Audit Logs</h1>
      <p class="muted-copy">Drive-style per-user logs with day-based collapsible history and per-user exports.</p>
    </div>
    <div class="drive-actions">
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/admin/users"><i class="bi bi-people me-1"></i>User workspaces</a>
    </div>
  </section>

  <?php if(req_str('err') !== ''): ?>
    <div class="alert alert-danger auto-dismiss"><?= e(ui_message(req_str('err'))) ?></div>
  <?php endif; ?>

  <section class="admin-drive-layout">
    <aside class="admin-drive-sidebar">
      <div class="table-card">
        <div class="table-card__header">
          <div>
            <h2><i class="bi bi-people me-1"></i>Users</h2>
            <p>Select one user to inspect logs.</p>
          </div>
        </div>
        <div class="table-card__body admin-user-list">
          <?php foreach($users as $u): ?>
            <?php $uid = (int)$u['id']; ?>
            <a class="admin-user-chip <?= $uid === $selectedUserId ? 'is-active' : '' ?>" href="<?= BASE_URL ?>/admin/logs?user_id=<?= $uid ?>">
              <span class="admin-user-chip__avatar"><?= e(strtoupper(substr((string)$u['name'], 0, 1))) ?></span>
              <span class="admin-user-chip__meta">
                <strong><?= e($u['name']) ?></strong>
                <span><?= e(admin_logs_display_code_label($u)) ?> · <?= e($u['email']) ?></span>
              </span>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </aside>

    <div class="admin-drive-main">
      <?php if($selectedUser): ?>
        <div class="table-card table-card--logs-filters">
          <div class="table-card__header">
            <div>
              <h2><i class="bi bi-journal-text me-1"></i><?= e((string)$selectedUser['name']) ?> activity</h2>
              <p class="admin-logs-identity"><?= e(admin_logs_display_code_label($selectedUser)) ?> | <?= e((string)$selectedUser['email']) ?> | <?= e((string)$selectedUser['role']) ?> | <?= e((string)$selectedUser['status']) ?></p>
            </div>
            <div class="drive-actions">
              <span class="badge-soft <?= in_array((string)$selectedUser['role'], ['SUPER_ADMIN', 'ADMIN'], true) ? 'badge-soft--warning' : 'badge-soft--info' ?>"><?= e(role_label((string)$selectedUser['role'])) ?></span>
              <span class="status-pill <?= ((string)$selectedUser['status'] === 'ACTIVE') ? 'status-pill--active' : 'status-pill--disabled' ?>"><?= e((string)$selectedUser['status']) ?></span>
            </div>
          </div>
          <div class="table-card__body">
            <div class="detail-stat-grid">
              <article class="share-stat">
                <div class="share-stat__label">Loaded events</div>
                <div class="share-stat__value"><?= (int)$summary['total'] ?></div>
              </article>
              <article class="share-stat">
                <div class="share-stat__label">Document events</div>
                <div class="share-stat__value"><?= (int)$summary['document_events'] ?></div>
              </article>
              <article class="share-stat">
                <div class="share-stat__label">Sharing events</div>
                <div class="share-stat__value"><?= (int)$summary['sharing_events'] ?></div>
              </article>
            </div>
            <p class="muted-copy mt-3 mb-0">Showing logs for <strong><?= e($selectedMonthLabel) ?></strong> (Philippine Time).</p>

            <form method="GET" action="<?= BASE_URL ?>/admin/logs" class="drive-search admin-logs-filter mt-3">
              <input type="hidden" name="user_id" value="<?= (int)$selectedUserId ?>">
              <input class="form-control drive-input" type="month" name="month" value="<?= e($selectedMonth) ?>">
              <select class="form-select drive-input visually-hidden" name="category" id="admin-logs-category-filter">
                <option value="ALL"<?= $selectedCategory === 'ALL' ? ' selected' : '' ?>>All categories</option>
                <?php foreach($categories as $category): ?>
                  <option value="<?= e($category) ?>"<?= $selectedCategory === $category ? ' selected' : '' ?>><?= e(AuditLog::categoryLabel((string)$category)) ?></option>
                <?php endforeach; ?>
              </select>
              <div class="admin-filter-dropdown admin-logs-filter-dropdown" id="admin-logs-category-dropdown">
                <button type="button" class="admin-filter-dropdown__trigger" id="admin-logs-category-trigger" aria-haspopup="listbox" aria-expanded="false" aria-controls="admin-logs-category-menu">
                  <span class="admin-filter-dropdown__value" id="admin-logs-category-value">
                    <?= e($selectedCategory === 'ALL' ? 'All categories' : AuditLog::categoryLabel($selectedCategory)) ?>
                  </span>
                </button>
                <div class="admin-filter-dropdown__menu" id="admin-logs-category-menu" role="listbox" tabindex="-1" hidden>
                  <button type="button" class="admin-filter-dropdown__item<?= $selectedCategory === 'ALL' ? ' is-selected' : '' ?>" data-logs-filter-value="ALL" role="option" aria-selected="<?= $selectedCategory === 'ALL' ? 'true' : 'false' ?>">All categories</button>
                  <?php foreach($categories as $category): ?>
                    <button type="button" class="admin-filter-dropdown__item<?= $selectedCategory === $category ? ' is-selected' : '' ?>" data-logs-filter-value="<?= e($category) ?>" role="option" aria-selected="<?= $selectedCategory === $category ? 'true' : 'false' ?>"><?= e(AuditLog::categoryLabel((string)$category)) ?></button>
                  <?php endforeach; ?>
                </div>
              </div>
              <button class="btn btn-outline-secondary" type="submit"><i class="bi bi-funnel me-1"></i>Apply month filter</button>
            </form>

            <div class="drive-actions mt-3">
              <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin/logs/export?user_id=<?= (int)$selectedUserId ?>&month=<?= e($selectedMonth) ?>&category=<?= e($selectedCategory) ?>"><i class="bi bi-file-earmark-pdf me-1"></i>Download Activity Report PDF</a>
            </div>
          </div>
        </div>

        <div class="table-card">
          <div class="table-card__header">
            <div>
              <h2><i class="bi bi-calendar3 me-1"></i>Day-by-day timeline</h2>
              <p>Each date is collapsible with complete action history and exact time.</p>
            </div>
          </div>

          <?php if(empty($logs)): ?>
            <div class="table-empty">No logs found for this user on the selected date range.</div>
          <?php else: ?>
            <div class="table-card__body admin-folder-sections">
              <?php foreach($days as $day => $dayLogs): ?>
                <details class="folder-section" <?= $day === array_key_first($days) ? 'open' : '' ?>>
                  <summary class="folder-section__header" style="cursor:pointer; list-style:none;">
                    <div>
                      <h3 class="folder-section__title"><span class="drive-folder-glyph"><i class="bi bi-calendar-event"></i></span><?= e($day) ?></h3>
                      <p><?= count($dayLogs) ?> event(s)</p>
                    </div>
                  </summary>
                  <div class="table-responsive">
                    <table class="table workspace-table align-middle mb-0 admin-logs-table">
                      <thead>
                        <tr>
                          <th>Date</th>
                          <th>Hour</th>
                          <th>Category</th>
                          <th>Action</th>
                          <th>Document</th>
                          <th>Details</th>
                        </tr>
                      </thead>
                      <tbody>
                        <?php foreach($dayLogs as $log): ?>
                          <?php $dt = admin_datetime_pht((string)($log['created_at'] ?? '')); ?>
                          <tr>
                            <td class="text-muted" data-label="Date"><?= e($dt ? $dt->format('Y-m-d') : '-') ?></td>
                            <td class="text-muted" data-label="Hour"><?= e($dt ? $dt->format('h:i A') : '-') ?></td>
                            <td data-label="Category"><span class="admin-meta-pill admin-meta-pill--info"><?= e(AuditLog::categoryLabel((string)($log['category'] ?? 'SYSTEM'))) ?></span></td>
                            <td data-label="Action"><?= e((string)$log['action']) ?></td>
                            <td data-label="Document"><?= e((string)($log['document_id'] ?? '-')) ?></td>
                            <td data-label="Details">
                              <?php $metaItems = parse_meta_details((string)($log['meta'] ?? '')); ?>
                              <?php if(empty($metaItems)): ?>
                                <span class="meta-empty">No details</span>
                              <?php else: ?>
                                <div class="meta-list">
                                  <?php foreach($metaItems as $item): ?>
                                    <span class="meta-item">
                                      <span class="meta-item__label"><?= e((string)$item['label']) ?>:</span>
                                      <span class="meta-item__value"><?= e((string)$item['value']) ?></span>
                                    </span>
                                  <?php endforeach; ?>
                                </div>
                              <?php endif; ?>
                            </td>
                          </tr>
                        <?php endforeach; ?>
                      </tbody>
                    </table>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>
      <?php else: ?>
        <div class="table-card">
          <div class="table-empty">No users found.</div>
        </div>
      <?php endif; ?>
    </div>
  </section>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
  const categorySelect = document.getElementById('admin-logs-category-filter');
  const categoryForm = categorySelect ? categorySelect.form : null;
  const categoryDropdown = document.getElementById('admin-logs-category-dropdown');
  const categoryTrigger = document.getElementById('admin-logs-category-trigger');
  const categoryValue = document.getElementById('admin-logs-category-value');
  const categoryMenu = document.getElementById('admin-logs-category-menu');
  const categoryItems = Array.from(document.querySelectorAll('[data-logs-filter-value]'));

  function closeCategoryMenu() {
    if (!categoryDropdown || !categoryTrigger || !categoryMenu) {
      return;
    }
    categoryDropdown.classList.remove('is-open');
    categoryTrigger.setAttribute('aria-expanded', 'false');
    categoryMenu.hidden = true;
  }

  function openCategoryMenu() {
    if (!categoryDropdown || !categoryTrigger || !categoryMenu) {
      return;
    }
    categoryDropdown.classList.add('is-open');
    categoryTrigger.setAttribute('aria-expanded', 'true');
    categoryMenu.hidden = false;
  }

  function syncCategoryUI(value) {
    categoryItems.forEach(function(item) {
      const isSelected = item.getAttribute('data-logs-filter-value') === value;
      item.classList.toggle('is-selected', isSelected);
      item.setAttribute('aria-selected', isSelected ? 'true' : 'false');
      if (isSelected && categoryValue) {
        categoryValue.textContent = item.textContent || '';
      }
    });
  }

  categoryTrigger?.addEventListener('click', function() {
    if (categoryMenu?.hidden) {
      openCategoryMenu();
      return;
    }
    closeCategoryMenu();
  });

  categoryItems.forEach(function(item) {
    item.addEventListener('click', function() {
      if (!categorySelect) {
        return;
      }
      const nextValue = item.getAttribute('data-logs-filter-value') || 'ALL';
      categorySelect.value = nextValue;
      syncCategoryUI(nextValue);
      closeCategoryMenu();
      if (categoryForm) {
        if (typeof categoryForm.requestSubmit === 'function') {
          categoryForm.requestSubmit();
        } else {
          categoryForm.submit();
        }
      }
    });
  });

  document.addEventListener('click', function(event) {
    if (event.target.closest('#admin-logs-category-dropdown')) {
      return;
    }
    closeCategoryMenu();
  });

  document.addEventListener('keydown', function(event) {
    if (event.key === 'Escape') {
      closeCategoryMenu();
    }
  });

  syncCategoryUI(categorySelect?.value || 'ALL');
});
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
