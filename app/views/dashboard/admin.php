<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
function format_dashboard_bytes(int $bytes): string {
  $units = ['B', 'KB', 'MB', 'GB', 'TB'];
  $value = $bytes;
  $unit = 0;
  while ($value >= 1024 && $unit < count($units) - 1) {
    $value /= 1024;
    $unit++;
  }
  return number_format($value, $unit === 0 ? 0 : 2) . ' ' . $units[$unit];
}

$storageUsed = (int)($storage['used'] ?? 0);
$storageLimit = max(1, (int)($storage['limit'] ?? 1));
$storagePercent = number_format((float)($storage['percent'] ?? (($storageUsed / $storageLimit) * 100)), 1);
$activitySummary = $activitySummary ?? [];
$activityByUser = $activityByUser ?? [];
$opsTotals = ['uploads' => 0, 'restores' => 0, 'deletes' => 0];
foreach ($activitySummary as $row) {
  $opsTotals['uploads'] += (int)($row['uploads'] ?? 0);
  $opsTotals['restores'] += (int)($row['restores'] ?? 0);
  $opsTotals['deletes'] += (int)($row['deletes'] ?? 0);
}
?>

<div class="workspace-page">
  <section class="workspace-hero">
    <div class="workspace-hero__content">
      <div class="workspace-hero__eyebrow">Administrator Control Panel</div>
      <h1 class="workspace-hero__title">Run CDD-File-Tracking-System like a database-backed workspace.</h1>
      <p class="workspace-hero__copy">
        Monitor users, storage, audit trails, and document activity from one command surface aligned with the
        capstone goal: centralized storage, secure collaboration, desktop editing workflow, and version control.
      </p>
      <div class="workspace-hero__actions">
        <a class="btn btn-light btn-sm" href="<?= BASE_URL ?>/admin/dashboard"><i class="bi bi-inbox me-1"></i>Open routing queue</a>
        <a class="btn btn-outline-light btn-sm" href="<?= BASE_URL ?>/admin/users"><i class="bi bi-people me-1"></i>Manage users</a>
        <!-- Organization chart removed -->
        <a class="btn btn-outline-light btn-sm" href="<?= BASE_URL ?>/admin/logs"><i class="bi bi-clipboard-data me-1"></i>View audit logs</a>
      </div>
    </div>
  </section>

  <section class="metric-grid">
    <article class="metric-card">
      <div class="metric-card__label">Total users</div>
      <div class="metric-card__value"><?= (int)($userCounts['total'] ?? 0) ?></div>
      <div class="metric-card__meta">All registered workspace accounts</div>
    </article>
    <article class="metric-card">
      <div class="metric-card__label">Active users</div>
      <div class="metric-card__value"><?= (int)($userCounts['active'] ?? 0) ?></div>
      <div class="metric-card__meta">Accounts currently enabled for access</div>
    </article>
    <article class="metric-card">
      <div class="metric-card__label">Storage used</div>
      <div class="metric-card__value"><?= e(format_dashboard_bytes($storageUsed)) ?></div>
      <div class="metric-card__meta"><?= e(format_dashboard_bytes($storageLimit)) ?> capacity monitored in system storage</div>
    </article>
    <article class="metric-card">
      <div class="metric-card__label">Capacity status</div>
      <div class="metric-card__value"><?= e($storagePercent) ?>%</div>
      <div class="metric-card__meta">Current overall usage against the configured storage limit</div>
    </article>
  </section>

  <section class="quick-links">
    <a class="quick-link" href="<?= BASE_URL ?>/admin/dashboard">
      <span class="quick-link__icon"><i class="bi bi-folder2-open"></i></span>
      <span class="quick-link__kicker">Workspace</span>
      <span class="quick-link__title">Document repository</span>
      <span class="quick-link__copy">Open user workspaces, folders, files, version details, and sharing actions.</span>
    </a>
    <a class="quick-link" href="<?= BASE_URL ?>/admin/users">
      <span class="quick-link__icon"><i class="bi bi-person-gear"></i></span>
      <span class="quick-link__kicker">Access control</span>
      <span class="quick-link__title">User management</span>
      <span class="quick-link__copy">Promote roles, disable accounts, and maintain secure access coverage.</span>
    </a>
    <a class="quick-link" href="<?= BASE_URL ?>/admin/logs">
      <span class="quick-link__icon"><i class="bi bi-journal-text"></i></span>
      <span class="quick-link__kicker">Traceability</span>
      <span class="quick-link__title">Audit monitoring</span>
      <span class="quick-link__copy">Inspect document events, sharing changes, and operational actions over time.</span>
    </a>
    <!-- Organization chart quick link removed -->
  </section>

  <section class="surface-card">
    <div class="table-card__meta">
      <div>
        <h2 class="surface-card__title">Storage summary</h2>
        <p class="surface-card__copy">System-wide storage consumption for uploaded and versioned document files.</p>
      </div>
      <span class="badge-soft badge-soft--info"><?= e($storagePercent) ?>% used</span>
    </div>
    <div class="drive-storage mt-3" style="background: var(--surface-muted); color: var(--text);">
      <div class="drive-storage__top">
        <span>Current usage</span>
        <strong><?= e(format_dashboard_bytes($storageUsed)) ?></strong>
      </div>
      <div class="drive-storage__bar" style="background: #dfe9f4;">
        <span style="width: <?= e($storagePercent) ?>%"></span>
      </div>
      <div class="drive-storage__meta" style="color: var(--text-muted);">
        <?= e(format_dashboard_bytes($storageUsed)) ?> of <?= e(format_dashboard_bytes($storageLimit)) ?> consumed across the platform
      </div>
    </div>
  </section>

  <section class="table-card">
    <div class="table-card__header">
      <div>
        <h2>Recent activity by user</h2>
        <p>Each account is separated so you can review per-user actions instead of one mixed global feed.</p>
      </div>
      <span class="badge-soft badge-soft--warning"><?= count($activityByUser) ?> user stream(s)</span>
    </div>

    <?php if(empty($activityByUser)): ?>
      <div class="table-empty">No recent activity has been recorded yet.</div>
    <?php else: ?>
      <div class="table-card__body" style="display: grid; gap: 16px;">
        <?php foreach($activityByUser as $group): ?>
          <div class="table-card">
            <div class="table-card__header">
              <div>
                <h2 class="mb-0"><?= e((string)$group['user']['name']) ?></h2>
                <p class="mb-0"><?= e((string)$group['user']['email']) ?> | <?= e((string)$group['user']['role']) ?> | <?= e((string)$group['user']['status']) ?></p>
              </div>
              <span class="badge-soft badge-soft--info"><?= count($group['logs']) ?> event(s)</span>
            </div>
            <div class="table-responsive">
              <table class="table workspace-table align-middle mb-0">
                <thead>
                  <tr>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Document</th>
                    <th>Details</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach($group['logs'] as $r): ?>
                    <tr>
                      <td class="text-muted"><?= e((string)$r['created_at']) ?></td>
                      <td><?= e((string)$r['action']) ?></td>
                      <td><?= e((string)($r['document_id'] ?? '-')) ?></td>
                      <td>
                        <?php $metaItems = parse_meta_details((string)($r['meta'] ?? '')); ?>
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
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </section>

  <section class="table-card">
    <div class="table-card__header">
      <div>
        <h2>Operational analytics (14 days)</h2>
        <p>Uploads, restores, and delete events by day.</p>
      </div>
    </div>
    <?php if(empty($activitySummary)): ?>
      <div class="table-empty">No analytics available yet.</div>
    <?php else: ?>
      <div class="table-card__body">
        <div class="analytics-summary">
          <article class="analytics-pill analytics-pill--upload">
            <span class="analytics-pill__label">Uploads</span>
            <strong class="analytics-pill__value"><?= (int)$opsTotals['uploads'] ?></strong>
          </article>
          <article class="analytics-pill analytics-pill--restore">
            <span class="analytics-pill__label">Restores</span>
            <strong class="analytics-pill__value"><?= (int)$opsTotals['restores'] ?></strong>
          </article>
          <article class="analytics-pill analytics-pill--delete">
            <span class="analytics-pill__label">Deletes</span>
            <strong class="analytics-pill__value"><?= (int)$opsTotals['deletes'] ?></strong>
          </article>
        </div>

        <div class="analytics-chart-shell">
          <canvas id="ops-analytics-chart" height="300" aria-label="Operational analytics bar chart"></canvas>
        </div>

        <details class="analytics-details mt-3">
          <summary>Show detailed table</summary>
          <div class="table-responsive mt-2">
            <table class="table workspace-table align-middle mb-0">
              <thead>
                <tr>
                  <th>Date</th>
                  <th>Uploads</th>
                  <th>Restores</th>
                  <th>Deletes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach($activitySummary as $row): ?>
                  <tr>
                    <td><?= e((string)$row['day']) ?></td>
                    <td><?= (int)$row['uploads'] ?></td>
                    <td><?= (int)$row['restores'] ?></td>
                    <td><?= (int)$row['deletes'] ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </details>
      </div>
    <?php endif; ?>
  </section>
</div>

<?php if(!empty($activitySummary)): ?>
<script>
  (function () {
    const canvas = document.getElementById('ops-analytics-chart');
    if (!canvas || typeof canvas.getContext !== 'function') return;

    const rawRows = <?= json_encode(array_map(function ($row) {
      return [
        'day' => (string)($row['day'] ?? ''),
        'uploads' => (int)($row['uploads'] ?? 0),
        'restores' => (int)($row['restores'] ?? 0),
        'deletes' => (int)($row['deletes'] ?? 0),
      ];
    }, $activitySummary), JSON_UNESCAPED_SLASHES) ?>;

    const rows = rawRows.slice().sort(function (a, b) {
      if (a.day < b.day) return -1;
      if (a.day > b.day) return 1;
      return 0;
    });
    if (!rows.length) return;

    const ctx = canvas.getContext('2d');
    const dpr = Math.max(1, window.devicePixelRatio || 1);
    const width = canvas.clientWidth || canvas.parentElement.clientWidth || 900;
    const height = 300;
    canvas.width = Math.floor(width * dpr);
    canvas.height = Math.floor(height * dpr);
    canvas.style.height = height + 'px';
    ctx.scale(dpr, dpr);
    ctx.clearRect(0, 0, width, height);

    const pad = { top: 24, right: 16, bottom: 56, left: 44 };
    const chartW = Math.max(120, width - pad.left - pad.right);
    const chartH = Math.max(120, height - pad.top - pad.bottom);
    const maxValue = Math.max(1, ...rows.map(function (r) {
      return Math.max(r.uploads, r.restores, r.deletes);
    }));

    ctx.strokeStyle = '#d5e2ef';
    ctx.lineWidth = 1;
    ctx.fillStyle = '#7693a7';
    ctx.font = '12px Segoe UI, Arial, sans-serif';

    const ticks = 4;
    for (let i = 0; i <= ticks; i++) {
      const y = pad.top + (chartH * i / ticks);
      const value = Math.round(maxValue - ((maxValue * i) / ticks));
      ctx.beginPath();
      ctx.moveTo(pad.left, y);
      ctx.lineTo(pad.left + chartW, y);
      ctx.stroke();
      ctx.fillText(String(value), 8, y + 4);
    }

    const groupW = chartW / rows.length;
    const barW = Math.max(6, Math.min(18, (groupW - 12) / 3));
    const colors = {
      uploads: '#1f7ae0',
      restores: '#11a36d',
      deletes: '#d84b5f'
    };
    const keys = ['uploads', 'restores', 'deletes'];

    rows.forEach(function (row, idx) {
      const x0 = pad.left + idx * groupW;
      keys.forEach(function (key, kIdx) {
        const value = row[key];
        const h = (value / maxValue) * chartH;
        const x = x0 + 6 + (kIdx * barW);
        const y = pad.top + chartH - h;
        ctx.fillStyle = colors[key];
        ctx.fillRect(x, y, barW - 2, h);
      });

      if (idx % Math.max(1, Math.ceil(rows.length / 7)) === 0 || idx === rows.length - 1) {
        const label = row.day.slice(5);
        ctx.save();
        ctx.translate(x0 + (groupW / 2), pad.top + chartH + 14);
        ctx.rotate(-0.35);
        ctx.fillStyle = '#6f8698';
        ctx.fillText(label, 0, 0);
        ctx.restore();
      }
    });

    const legendY = height - 18;
    let legendX = pad.left;
    keys.forEach(function (key) {
      const label = key.charAt(0).toUpperCase() + key.slice(1);
      ctx.fillStyle = colors[key];
      ctx.fillRect(legendX, legendY - 8, 10, 10);
      ctx.fillStyle = '#3f5668';
      ctx.fillText(label, legendX + 14, legendY);
      legendX += 84;
    });
  })();
</script>
<?php endif; ?>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
