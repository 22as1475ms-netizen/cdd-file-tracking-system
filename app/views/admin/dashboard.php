<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
$dashboardQuery = trim((string)($dashboardQuery ?? ($_GET['q'] ?? '')));
$adminContext = is_array($adminContext ?? null) ? $adminContext : [];
$isSectionAdmin = !empty($adminContext['is_section_admin']);
$canUploadDocuments = array_key_exists('can_upload', $adminContext) ? (bool)$adminContext['can_upload'] : true;
$canDeleteDocuments = array_key_exists('can_delete_documents', $adminContext) ? (bool)$adminContext['can_delete_documents'] : true;
$dashboardPagination = is_array($dashboardPagination ?? null) ? $dashboardPagination : [];
$statusCounts = $statusCounts ?? [
  'waiting' => 0,
  'routed' => 0,
  'returned' => 0,
  'approved' => 0,
  'total' => 0,
];
$users = $users ?? [];
$stagedUploads = $stagedUploads ?? [];
$page = max(1, (int)($dashboardPagination['page'] ?? ($_GET['page'] ?? 1)));
$pages = max(1, (int)($dashboardPagination['pages'] ?? 1));
$totalFiles = (int)($dashboardPagination['total'] ?? count($stagedUploads));

function admin_dashboard_status_badge(string $routingStatus): array {
  $normalized = strtoupper(trim($routingStatus));
  return match ($normalized) {
    'NOT_ROUTED', 'AVAILABLE' => ['Waiting for route', 'badge-soft badge-soft--warning'],
    'SHARE_DECLINED', 'REVIEW_ASSIGNMENT_DECLINED', 'REJECTED' => ['Returned', 'badge-soft badge-soft--danger'],
    'APPROVED' => ['Approved', 'badge-soft badge-soft--success'],
    'COMPLETED' => ['Completed', 'badge-soft badge-soft--success'],
    default => ['Routed', 'badge-soft badge-soft--info'],
  };
}

function admin_dashboard_priority_tone(string $priorityLevel): string {
  return match (strtoupper(trim($priorityLevel))) {
    'RUSH', 'URGENT' => 'rush',
    'HIGH' => 'high',
    'LOW' => 'low',
    default => 'moderate',
  };
}
?>

<div class="workspace-page admin-dashboard-page">
  <section class="workspace-toolbar admin-dashboard-toolbar">
    <div>
      <div class="section-eyebrow">Administration</div>
      <h1 class="drive-title"><?= $isSectionAdmin ? 'Section routing queue.' : 'Routing queue.' ?></h1>
      <p class="muted-copy"><?= $isSectionAdmin ? 'Route files only to your own section staff and monitor section activity in real-time.' : 'Upload PDFs, manage routing, and track file status in real-time.' ?></p>
    </div>
    <form method="GET" action="<?= BASE_URL ?>/admin/dashboard" class="drive-form-stack admin-dashboard-toolbar__search" id="admin-dashboard-search-form">
      <div class="admin-dashboard-toolbar__search-row">
        <input id="admin-dashboard-toolbar-search" class="form-control drive-input" name="q" placeholder="Search files, codes, locations, statuses" value="<?= e($dashboardQuery) ?>" autocomplete="off">
        <button class="btn btn-primary btn-sm admin-dashboard-toolbar__search-button" type="submit" aria-label="Search files" title="Search files">
          <i class="bi bi-search" aria-hidden="true"></i>
        </button>
      </div>
    </form>
  </section>

  <?php if(req_str('msg') !== ''): ?>
    <div class="alert alert-success auto-dismiss"><?= e(ui_message(req_str('msg'))) ?></div>
  <?php endif; ?>
  <?php if(req_str('err') !== ''): ?>
    <div class="alert alert-danger auto-dismiss"><?= e(ui_message(req_str('err'))) ?></div>
  <?php endif; ?>

  <!-- status snapshot moved to footer for a cleaner top-of-page listing -->

  <section class="table-card mb-3">
    <div class="table-card__header">
      <div>
        <h2><?= $isSectionAdmin ? 'Files routed to your section' : 'Files in your routing queue' ?></h2>
        <p><?= $isSectionAdmin ? 'Files assigned to your section stay here until you route them to your section staff or they reach completion.' : 'Files stay here with their current workflow status until you route or resolve them.' ?></p>
      </div>
      <div class="admin-dashboard-queue-actions">
        <?php if($canUploadDocuments): ?>
          <label class="admin-dashboard-toolbar__upload" for="admin-pdf-upload">
            <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
            <span>Upload PDF</span>
          </label>
        <?php endif; ?>
        <span class="badge-soft badge-soft--info"><?= $totalFiles ?> file(s)</span>
      </div>
    </div>
        <?php if($canUploadDocuments): ?>
        <div class="admin-dashboard-upload-panel" id="admin-dashboard-upload-panel" hidden>
          <form method="POST" action="<?= BASE_URL ?>/documents/upload" enctype="multipart/form-data" class="admin-dashboard-upload-form">
            <?= csrf_field() ?>
            <input type="file" accept=".pdf" id="admin-pdf-upload" name="file" class="visually-hidden" required>
            <div class="admin-dashboard-upload-form__header">
              <div>
                <strong>Upload selected PDF</strong>
                <p class="mb-0 text-muted small">Complete the routing details before submitting the file to the queue.</p>
              </div>
              <span class="admin-dashboard-upload-form__file" id="admin-upload-file-name">No file selected</span>
            </div>
            <div class="admin-dashboard-upload-form__grid">
              <label class="admin-dashboard-upload-form__field">
                <span>Doc. ID</span>
                <input class="form-control form-control-sm" type="text" name="document_code" maxlength="80" placeholder="Optional">
              </label>
              <label class="admin-dashboard-upload-form__field">
                <span>Subject</span>
                <input class="form-control form-control-sm" type="text" name="title" maxlength="255" required>
              </label>
              <label class="admin-dashboard-upload-form__field">
                <span>Sender</span>
                <input class="form-control form-control-sm" type="text" name="signatory" maxlength="150" required>
              </label>
              <label class="admin-dashboard-upload-form__field">
                <span>Document date</span>
                <div class="admin-dashboard-date-field" data-date-picker-shell>
                  <input class="form-control form-control-sm" type="text" name="document_date" placeholder="Type or pick a date" autocomplete="off" data-date-picker required>
                  <button class="admin-dashboard-date-field__toggle" type="button" data-date-picker-toggle aria-label="Open calendar">
                    <i class="bi bi-calendar3"></i>
                  </button>
                </div>
              </label>
              <input type="hidden" name="priority_level" value="MODERATE">
              <input type="hidden" name="category" value="Incoming PDF">
              <!-- Current location removed from upload form; uploads set to Admin automatically -->
            </div>
            <div class="admin-dashboard-upload-form__actions">
              <button class="btn btn-success btn-sm" type="submit">Submit PDF</button>
              <button class="btn btn-outline-secondary btn-sm" type="button" id="admin-upload-cancel">Cancel</button>
            </div>
          </form>
        </div>
        <?php endif; ?>
        <div class="table-card__body">
          <div class="table-responsive">
            <table class="table workspace-table align-middle mb-0 admin-dashboard-table">
              <thead>
                <tr>
                  <th>File</th>
                  <th>Doc ID</th>
                  <th>Document Date</th>
                  <th>Sender</th>
                  <th>Status</th>
                  <th>Location</th>
                  <th>Uploaded</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody id="admin-dashboard-table-body">
                <?php if(empty($stagedUploads)): ?>
                  <tr data-dashboard-empty-row>
                    <td colspan="8" class="table-empty">No files match this view.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach($stagedUploads as $su): ?>
                <?php
                  $routingStatus = (string)($su['routing_status'] ?? 'NOT_ROUTED');
                  [$statusLabel, $statusClass] = admin_dashboard_status_badge($routingStatus);
                  $routeReady = in_array(strtoupper($routingStatus), ['NOT_ROUTED', 'AVAILABLE'], true);
                  $displayName = trim((string)($su['title'] ?? $su['name'])) !== '' ? trim((string)($su['title'] ?? $su['name'])) : 'Untitled file';
                  $documentDate = trim((string)($su['document_date'] ?? ''));
                  $senderName = trim((string)($su['signatory'] ?? ''));
                  $divisionName = trim((string)($su['division_name'] ?? ''));
                  $ownerRole = trim((string)($su['owner_role'] ?? ''));
                  $ownerRoleUpper = strtoupper($ownerRole);
                  $currentLocation = trim((string)($su['current_location'] ?? ''));
                  $activeRecipientName = trim((string)($su['active_recipient_name'] ?? ''));
                  $activeRecipientRole = trim((string)($su['active_recipient_role'] ?? ''));
                  $activeRecipientRoleUpper = strtoupper($activeRecipientRole);
                  $activeRecipientDivisionName = trim((string)($su['active_recipient_division_name'] ?? ''));
                  $isRoutedAway = !in_array(strtoupper($routingStatus), ['NOT_ROUTED', 'AVAILABLE'], true);

                  if ($isRoutedAway && $activeRecipientName !== '') {
                    $locationLabel = $activeRecipientDivisionName !== '' ? $activeRecipientDivisionName : $activeRecipientName;
                    $locationMeta = trim(($activeRecipientRole !== '' ? role_label($activeRecipientRole) : 'Recipient') . ($activeRecipientName !== '' ? ' | ' . $activeRecipientName : ''));
                  } else {
                    $locationLabel = $divisionName !== ''
                      ? $divisionName
                      : (in_array($ownerRoleUpper, ['SUPER_ADMIN', 'ADMIN'], true)
                        ? 'CDD'
                        : ($currentLocation !== '' ? $currentLocation : 'Admin queue'));
                    $locationMeta = $ownerRole !== '' ? role_label($ownerRole) : '';
                  }
                  $priorityLevel = strtoupper((string)($su['priority_level'] ?? 'MODERATE'));
                  $priorityTone = admin_dashboard_priority_tone($priorityLevel);
                ?>
                <tr class="admin-dashboard-row admin-dashboard-row--<?= e($priorityTone) ?>" data-dashboard-row>
                  <td data-label="File">
                    <a href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" class="fw-semibold text-decoration-none admin-priority-file admin-priority-file--<?= e($priorityTone) ?>"><?= e($displayName) ?></a>
                  </td>
                  <td data-label="Doc ID"><?= e((string)($su['document_code'] ?? '')) ?></td>
                  <td class="text-muted small" data-label="Document date"><?= e($documentDate !== '' ? $documentDate : 'Not set') ?></td>
                  <td class="text-muted small" data-label="Sender"><?= e($senderName !== '' ? $senderName : 'Not set') ?></td>
                  <td data-label="Status"><span class="<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                  <td data-label="Location">
                    <div class="admin-dashboard-location">
                      <strong><?= e($locationLabel) ?></strong>
                      <?php if($locationMeta !== ''): ?>
                        <small><?= e($locationMeta) ?></small>
                      <?php endif; ?>
                    </div>
                  </td>
                  <td class="text-muted small" data-label="Uploaded"><?= e((string)($su['created_at'] ?? '')) ?></td>
                  <td class="text-end" data-label="Actions">
                    <div class="btn-group btn-group-sm" role="group" aria-label="File actions">
                      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" title="View file"><i class="bi bi-eye"></i></a>
                      <?php if($routeReady || $isSectionAdmin): ?>
                        <a class="btn btn-success" href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" title="<?= $isSectionAdmin ? 'Route to section staff' : 'Route file' ?>"><i class="bi bi-arrow-right-circle"></i></a>
                      <?php endif; ?>
                      <?php if(!$isSectionAdmin): ?>
                        <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" title="Reassign"><i class="bi bi-arrow-repeat"></i></a>
                      <?php endif; ?>
                      <?php if($canDeleteDocuments): ?>
                        <form
                          method="POST"
                          action="<?= BASE_URL ?>/admin/documents/delete"
                          class="admin-dashboard-table__action-form admin-confirm-form js-confirm"
                          data-confirm-label="permanently delete this file"
                          data-confirm-message="Permanently delete this file from the routing queue? This cannot be undone."
                          data-confirm-title="Confirm password"
                          data-confirm-button="Delete permanently"
                          data-confirm-password="1"
                          data-confirm-password-label="Super admin password"
                        >
                          <?= csrf_field() ?>
                          <input type="hidden" name="confirm_password" value="">
                          <input type="hidden" name="id" value="<?= (int)$su['id'] ?>">
                          <button class="btn btn-outline-danger" type="submit" title="Delete permanently" aria-label="Delete permanently">
                            <i class="bi bi-trash"></i>
                          </button>
                        </form>
                      <?php endif; ?>
                    </div>
                  </td>
                </tr>
                  <?php endforeach; ?>
                  <tr data-dashboard-no-results hidden>
                    <td colspan="8" class="table-empty">No visible files match this search.</td>
                  </tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>

          <?php if($pages > 1): ?>
            <nav aria-label="Files pagination" class="mt-3">
              <ul class="pagination pagination-sm">
                <?php for($p = 1; $p <= $pages; $p++): ?>
                  <li class="page-item <?= $p === $page ? 'active' : '' ?>">
                    <a class="page-link" href="<?= BASE_URL ?>/admin/dashboard?page=<?= $p ?><?= $dashboardQuery !== '' ? '&q=' . urlencode($dashboardQuery) : '' ?>"><?= $p ?></a>
                  </li>
                <?php endfor; ?>
              </ul>
            </nav>
          <?php endif; ?>
        </div>
      </section>

</section>

<?php
// indicate to the layout that the admin status footer should be rendered outside the scrollable content
$show_admin_status_footer = true;
?>
</div>

<script>
  document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('admin-dashboard-toolbar-search');
    const searchTableBody = document.getElementById('admin-dashboard-table-body');
    const searchRows = searchTableBody ? Array.from(searchTableBody.querySelectorAll('[data-dashboard-row]')) : [];
    const searchNoResultsRow = searchTableBody ? searchTableBody.querySelector('[data-dashboard-no-results]') : null;
    const uploadPanel = document.getElementById('admin-dashboard-upload-panel');
    const uploadInput = document.getElementById('admin-pdf-upload');
    const uploadFileName = document.getElementById('admin-upload-file-name');
    const uploadCancel = document.getElementById('admin-upload-cancel');
    const titleInput = document.querySelector('input[name="title"]');

    function resetUploadPanel() {
      if (!uploadPanel || !uploadInput) {
        return;
      }
      uploadPanel.hidden = true;
      uploadInput.value = '';
      if (uploadFileName) {
        uploadFileName.textContent = 'No file selected';
      }
    }

    if (uploadInput) {
      uploadInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        if (file && file.name.toLowerCase().endsWith('.pdf')) {
          if (uploadPanel) {
            uploadPanel.hidden = false;
          }
          if (uploadFileName) {
            uploadFileName.textContent = file.name;
          }
          if (titleInput && titleInput.value.trim() === '') {
            titleInput.value = file.name.replace(/\.pdf$/i, '');
          }
          if (titleInput) {
            titleInput.focus();
          }
        } else if (file) {
          alert('Only PDF files are allowed.');
          resetUploadPanel();
        }
      });
    }

    if (uploadCancel) {
      uploadCancel.addEventListener('click', resetUploadPanel);
    }

    if (searchInput && searchRows.length > 0) {
      const applyLiveSearch = function() {
        const query = searchInput.value.trim().toLowerCase();
        let visibleCount = 0;

        searchRows.forEach(function(row) {
          const matches = query === '' || row.textContent.toLowerCase().indexOf(query) !== -1;
          row.hidden = !matches;
          if (matches) {
            visibleCount += 1;
          }
        });

        if (searchNoResultsRow) {
          searchNoResultsRow.hidden = visibleCount > 0;
        }
      };

      searchInput.addEventListener('input', applyLiveSearch);
      searchInput.addEventListener('search', applyLiveSearch);
      searchInput.form && searchInput.form.addEventListener('submit', function(event) {
        event.preventDefault();
        applyLiveSearch();
      });
      applyLiveSearch();
    }
  });
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
