<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
$dashboardQuery = trim((string)($dashboardQuery ?? ($_GET['q'] ?? '')));
// pagination and status
$statusCounts = $statusCounts ?? [
  'waiting' => 0,
  'routed' => 0,
  'returned' => 0,
  'approved' => 0,
  'total' => 0,
];
$users = $users ?? [];
$stagedUploads = $stagedUploads ?? [];

// simple server-side pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 15; // changeable threshold
$totalFiles = count($stagedUploads);
$pages = max(1, (int)ceil($totalFiles / $perPage));
$page = $page > $pages ? $pages : $page;
$offset = ($page - 1) * $perPage;
$pagedUploads = array_slice($stagedUploads, $offset, $perPage);

function admin_dashboard_status_badge(string $routingStatus): array {
  $normalized = strtoupper(trim($routingStatus));
  return match ($normalized) {
    'NOT_ROUTED', 'AVAILABLE' => ['Waiting for route', 'badge-soft badge-soft--warning'],
    'SHARE_DECLINED', 'REVIEW_ASSIGNMENT_DECLINED', 'REJECTED' => ['Returned', 'badge-soft badge-soft--danger'],
    'APPROVED' => ['Routed', 'badge-soft badge-soft--success'],
    default => ['Routed', 'badge-soft badge-soft--info'],
  };
}
?>

<div class="workspace-page">
  <section class="workspace-toolbar admin-dashboard-toolbar">
    <div>
      <div class="section-eyebrow">Administration</div>
      <h1 class="drive-title">Routing queue.</h1>
      <p class="muted-copy">Upload PDFs, manage routing, and track file status in real-time.</p>
    </div>
    <div>
      <form method="GET" action="<?= BASE_URL ?>/admin/dashboard" class="drive-form-stack admin-dashboard-toolbar__search">
        <label class="form-label small text-uppercase text-muted mb-1" for="admin-dashboard-toolbar-search">Search</label>
        <div class="d-flex gap-2">
          <input id="admin-dashboard-toolbar-search" class="form-control drive-input" name="q" placeholder="Search files, codes, locations, statuses" value="<?= e($dashboardQuery) ?>">
          <button class="btn btn-primary btn-sm" type="submit">Search</button>
        </div>
      </form>
    </div>
  </section>

  <?php if(req_str('msg') !== ''): ?>
    <div class="alert alert-success"><?= e(ui_message(req_str('msg'))) ?></div>
  <?php endif; ?>
  <?php if(req_str('err') !== ''): ?>
    <div class="alert alert-danger"><?= e(ui_message(req_str('err'))) ?></div>
  <?php endif; ?>

  <!-- status snapshot moved to footer for a cleaner top-of-page listing -->

  <section class="table-card mb-3">
    <div class="table-card__header">
      <div>
        <h2>Files in your routing queue</h2>
        <p>Files stay here with their current workflow status until you route or resolve them.</p>
      </div>
      <div class="admin-dashboard-queue-actions">
        <label class="admin-dashboard-toolbar__upload" for="admin-pdf-upload">
          <i class="bi bi-cloud-arrow-up" aria-hidden="true"></i>
          <span>Upload PDF</span>
        </label>
        <span class="badge-soft badge-soft--info"><?= count($stagedUploads) ?> file(s)</span>
      </div>
    </div>
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
                <input class="form-control form-control-sm" type="text" name="document_code" maxlength="80" required>
              </label>
              <label class="admin-dashboard-upload-form__field">
                <span>Title</span>
                <input class="form-control form-control-sm" type="text" name="title" maxlength="255" required>
              </label>
              <label class="admin-dashboard-upload-form__field">
                <span>Signatory</span>
                <input class="form-control form-control-sm" type="text" name="signatory" maxlength="150" required>
              </label>
              <label class="admin-dashboard-upload-form__field">
                <span>Document date</span>
                <input class="form-control form-control-sm" type="date" name="document_date" required>
              </label>
              <label class="admin-dashboard-upload-form__field">
                <span>Category</span>
                <input class="form-control form-control-sm" type="text" name="category" maxlength="100" required>
              </label>
              <!-- Current location removed from upload form; uploads set to Admin automatically -->
            </div>
            <div class="admin-dashboard-upload-form__actions">
              <button class="btn btn-success btn-sm" type="submit">Submit PDF</button>
              <button class="btn btn-outline-secondary btn-sm" type="button" id="admin-upload-cancel">Cancel</button>
            </div>
          </form>
        </div>
        <div class="table-card__body">
          <div class="table-responsive">
            <table class="table workspace-table align-middle mb-0 admin-dashboard-table">
              <thead>
                <tr>
                  <th>File</th>
                  <th>Doc ID</th>
                  <th>Status</th>
                  <th>Administrator</th>
                  <th>Uploaded</th>
                  <th class="text-end">Actions</th>
                </tr>
              </thead>
              <tbody>
                <?php if(empty($pagedUploads)): ?>
                  <tr>
                    <td colspan="6" class="table-empty">No files match this view.</td>
                  </tr>
                <?php else: ?>
                  <?php foreach($pagedUploads as $su): ?>
                <?php
                  $routingStatus = (string)($su['routing_status'] ?? 'NOT_ROUTED');
                  [$statusLabel, $statusClass] = admin_dashboard_status_badge($routingStatus);
                  $routeReady = in_array(strtoupper($routingStatus), ['NOT_ROUTED', 'AVAILABLE'], true);
                  $displayName = trim((string)($su['title'] ?? $su['name'])) !== '' ? trim((string)($su['title'] ?? $su['name'])) : 'Untitled file';
                ?>
                <tr>
                  <td><a href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" class="fw-semibold text-decoration-none"><?= e($displayName) ?></a></td>
                  <td><?= e((string)($su['document_code'] ?? '')) ?></td>
                  <td><span class="<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                  <td><?= e((string)($su['current_location'] ?? '')) ?></td>
                  <td class="text-muted small"><?= e((string)($su['created_at'] ?? '')) ?></td>
                  <td class="text-end">
                    <div class="btn-group btn-group-sm" role="group" aria-label="File actions">
                      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" title="View file"><i class="bi bi-eye"></i></a>
                      <?php if($routeReady): ?>
                        <a class="btn btn-success" href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" title="Route file"><i class="bi bi-arrow-right-circle"></i></a>
                      <?php endif; ?>
                      <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/documents/view?id=<?= (int)$su['id'] ?>" title="Reassign"><i class="bi bi-arrow-repeat"></i></a>
                      <form
                        method="POST"
                        action="<?= BASE_URL ?>/admin/documents/delete"
                        class="admin-dashboard-table__action-form admin-confirm-form"
                        data-confirm-label="permanently delete this file"
                        data-confirm-message="Permanently delete this file from the routing queue? This cannot be undone."
                      >
                        <?= csrf_field() ?>
                        <input type="hidden" name="confirm_password" value="">
                        <input type="hidden" name="id" value="<?= (int)$su['id'] ?>">
                        <button class="btn btn-outline-danger" type="submit" title="Delete permanently" aria-label="Delete permanently">
                          <i class="bi bi-trash"></i>
                        </button>
                      </form>
                    </div>
                  </td>
                </tr>
                  <?php endforeach; ?>
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
  });
</script>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
