<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
$routes = $routes ?? [];
$selectedMonth = (string)($selectedMonth ?? date('Y-m'));
$selectedMonthLabel = (string)($selectedMonthLabel ?? '');
?>

<div class="workspace-page">
  <section class="workspace-toolbar">
    <div>
      <div class="section-eyebrow">Administration</div>
      <h1 class="drive-title">Routed Files Report</h1>
      <p class="muted-copy">Consolidated record of all routed files for the selected month.</p>
    </div>
    <div class="drive-actions">
      <a class="btn btn-outline-secondary btn-sm" href="<?= BASE_URL ?>/admin/users"><i class="bi bi-people me-1"></i>User workspaces</a>
      <a class="btn btn-primary btn-sm" href="<?= BASE_URL ?>/admin/routed/export?month=<?= e($selectedMonth) ?>"><i class="bi bi-file-earmark-arrow-down me-1"></i>Download CSV</a>
    </div>
  </section>

  <?php if(req_str('err') !== ''): ?>
    <div class="alert alert-danger auto-dismiss"><?= e(ui_message(req_str('err'))) ?></div>
  <?php endif; ?>

  <section class="admin-drive-layout">
    <div class="admin-drive-main">
      <div class="table-card">
        <div class="table-card__header">
          <div>
            <h2><i class="bi bi-journal-text me-1"></i>Routed activity</h2>
            <p>Showing routed files for <strong><?= e($selectedMonthLabel) ?></strong>.</p>
          </div>
        </div>
        <div class="table-card__body">
          <form method="GET" action="<?= BASE_URL ?>/admin/routed" class="drive-search mb-3">
            <input class="form-control drive-input" type="month" name="month" value="<?= e($selectedMonth) ?>">
            <button class="btn btn-outline-secondary mt-2" type="submit"><i class="bi bi-funnel me-1"></i>Apply month filter</button>
          </form>

          <?php if(empty($routes)): ?>
            <div class="table-empty">No routed files found for this period.</div>
          <?php else: ?>
            <div class="table-responsive">
              <table class="table workspace-table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Date</th>
                    <th>Document</th>
                    <th>From</th>
                    <th>To</th>
                    <th>Status</th>
                    <th>Note</th>
                    <th>Routed By</th>
                    <th>Owner</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($routes as $r): ?>
                    <tr>
                      <td class="text-muted"><?= e((string)($r['routed_at'] ?? $r['created_at'] ?? '-')) ?></td>
                      <td><?= e((string)($r['doc_name'] ?? '')) ?> (<?= e((string)($r['document_id'] ?? '-')) ?>)</td>
                      <td><?= e((string)($r['from_location'] ?? '')) ?></td>
                      <td><?= e((string)($r['to_location'] ?? '')) ?></td>
                      <td><?= e((string)($r['status_snapshot'] ?? '')) ?></td>
                      <td><?= e((string)($r['note'] ?? '')) ?></td>
                      <td><?= e((string)($r['routed_by_name'] ?? '')) ?> <?= e((string)($r['routed_by_email'] ?? '')) ?></td>
                      <td><?= e((string)($r['owner_name'] ?? '')) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>
</div>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
