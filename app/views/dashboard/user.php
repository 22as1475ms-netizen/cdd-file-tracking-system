<?php require __DIR__ . "/../layouts/header.php"; ?>
<?php require_once __DIR__ . "/../../helpers/http.php"; ?>
<?php
$user = $_SESSION['user'] ?? [];
$displayName = trim((string)($user['name'] ?? 'User'));
$firstName = $displayName !== '' ? (string)preg_replace('/\s+.*/', '', $displayName) : 'User';
$sectionName = trim((string)($user['division_name'] ?? ''));
$sectionLabel = $sectionName !== '' ? $sectionName . ' Section Staff' : 'Section Staff';
$staffLocationLabel = $sectionName !== '' ? $sectionName : 'Current holder';
$staffLocationMeta = 'Section Staff | ' . strtoupper($displayName !== '' ? $displayName : 'Current User');
$routeSummary = is_array($routeSummary ?? null) ? $routeSummary : [];
$incomingCount = (int)($routeSummary['incoming'] ?? 0);
$completedCount = (int)($routeSummary['completed'] ?? 0);
$routedTotal = (int)($routeSummary['total'] ?? 0);
$unreadNotifications = (int)($unreadNotifications ?? 0);
$routedInbox = is_array($routedInbox ?? null) ? $routedInbox : [];
$activeRoutes = is_array($activeRoutes ?? null) ? $activeRoutes : [];
$completedRoutes = is_array($completedRoutes ?? null) ? $completedRoutes : [];
$recentNotifications = is_array($recentNotifications ?? null) ? $recentNotifications : [];
$routedPagination = is_array($routedPagination ?? null) ? $routedPagination : [];
$routedPage = max(1, (int)($routedPagination['page'] ?? 1));
$routedPages = max(1, (int)($routedPagination['pages'] ?? 1));

if (!function_exists('staff_dashboard_route_badge')) {
  function staff_dashboard_route_badge(string $state): string {
    return match (strtoupper(trim($state))) {
      'COMPLETED' => 'success',
      'UNDER_REVIEW' => 'warning',
      'RETURNED' => 'secondary',
      default => 'primary',
    };
  }
}

if (!function_exists('staff_dashboard_status_badge')) {
  function staff_dashboard_status_badge(string $state): array {
    return match (strtoupper(trim($state))) {
      'COMPLETED' => ['Completed', 'badge-soft badge-soft--success'],
      'UNDER_REVIEW' => ['Under Review', 'badge-soft badge-soft--warning'],
      'RETURNED' => ['Returned', 'badge-soft badge-soft--danger'],
      default => ['Routed', 'badge-soft badge-soft--info'],
    };
  }
}

if (!function_exists('staff_dashboard_priority_badge')) {
  function staff_dashboard_priority_badge(string $priority): string {
    return match (strtoupper(trim($priority))) {
      'RUSH', 'URGENT' => 'danger',
      'HIGH' => 'warning',
      'MODERATE', 'NORMAL' => 'info',
      default => 'secondary',
    };
  }
}

if (!function_exists('staff_dashboard_priority_tone')) {
  function staff_dashboard_priority_tone(string $priority): string {
    return match (strtoupper(trim($priority))) {
      'RUSH', 'URGENT' => 'rush',
      'HIGH' => 'high',
      'LOW' => 'low',
      default => 'moderate',
    };
  }
}

if (!function_exists('staff_dashboard_extract_instruction')) {
  function staff_dashboard_extract_instruction(string $note): string {
    $trimmed = trim($note);
    if ($trimmed === '') {
      return '';
    }

    $prefix = 'Actions to be taken:';
    $position = stripos($trimmed, $prefix);
    if ($position === false) {
      return '';
    }

    return trim(substr($trimmed, $position + strlen($prefix)));
  }
}

if (!function_exists('staff_dashboard_route_sender')) {
  function staff_dashboard_route_sender(array $doc): string {
    $sharedByName = trim((string)($doc['shared_by_name'] ?? ''));
    if ($sharedByName !== '') {
      return $sharedByName;
    }

    $lastRouteByName = trim((string)($doc['last_route_by_name'] ?? ''));
    return $lastRouteByName !== '' ? $lastRouteByName : 'Admin';
  }
}

if (!function_exists('staff_dashboard_route_timestamp')) {
  function staff_dashboard_route_timestamp(array $doc): string {
    $acceptedAt = trim((string)($doc['accepted_at'] ?? ''));
    if ($acceptedAt !== '') {
      return $acceptedAt;
    }

    return trim((string)($doc['last_route_at'] ?? ''));
  }
}

if (!function_exists('staff_dashboard_datetime')) {
  function staff_dashboard_datetime(string $value, string $fallback = 'Not set'): string {
    $trimmed = trim($value);
    if ($trimmed === '') {
      return $fallback;
    }

    try {
      $dt = new DateTimeImmutable($trimmed, new DateTimeZone('Asia/Manila'));
    } catch (Throwable $_e) {
      return $trimmed;
    }

    return $dt->setTimezone(new DateTimeZone('Asia/Manila'))->format('Y-m-d g:i:s A');
  }
}

$nextRoute = $activeRoutes[0] ?? null;
?>

<div class="workspace-page staff-dashboard">
  <?php if(req_str('msg') !== ''): ?>
    <div class="alert alert-success auto-dismiss mb-3"><?= e(ui_message(req_str('msg'))) ?></div>
  <?php endif; ?>
  <?php if(req_str('err') !== ''): ?>
    <div class="alert alert-danger auto-dismiss mb-3"><?= e(ui_message(req_str('err'))) ?></div>
  <?php endif; ?>

  <section class="staff-dashboard__hero">
    <div class="staff-dashboard__hero-main">
      <span class="staff-dashboard__eyebrow"><?= e($sectionLabel) ?></span>
      <h1 class="staff-dashboard__title">Hello <?= e($firstName) ?>, here is what needs your attention.</h1>
      <p class="staff-dashboard__copy">
        Open the routed file, follow the admin instruction, finish the required action, and complete the route once your work is done.
      </p>
    </div>
    <div class="staff-dashboard__hero-side">
      <div class="staff-dashboard__spotlight">
        <div class="staff-dashboard__spotlight-label">Current Status</div>
        <?php if($nextRoute): ?>
          <?php $nextRouteInstruction = staff_dashboard_extract_instruction((string)($nextRoute['last_route_note'] ?? '')); ?>
          <?php $nextRouteDocId = (int)($nextRoute['id'] ?? 0); ?>
          <?php $nextRouteDocExt = strtolower((string)pathinfo((string)($nextRoute['name'] ?? ''), PATHINFO_EXTENSION)); ?>
          <?php $nextRouteExternal = in_array($nextRouteDocExt, ['doc', 'docx', 'xls', 'xlsx'], true); ?>
          <?php $nextRouteSender = staff_dashboard_route_sender($nextRoute); ?>
          <?php $nextRouteAt = staff_dashboard_datetime(staff_dashboard_route_timestamp($nextRoute), 'Not set'); ?>
          <strong class="staff-dashboard__spotlight-title"><?= $incomingCount ?> routed file<?= $incomingCount === 1 ? '' : 's' ?> waiting</strong>
          <p class="staff-dashboard__spotlight-meta">
            Latest file: <?= e((string)($nextRoute['title'] ?? $nextRoute['name'] ?? 'Untitled file')) ?>
          </p>
          <p class="staff-dashboard__spotlight-meta">
            Routed by <?= e($nextRouteSender) ?><?= $nextRouteAt !== 'Not set' ? ' on ' . $nextRouteAt : '' ?>.
          </p>
          <p class="staff-dashboard__spotlight-instruction">
            <?= e($nextRouteInstruction !== '' ? $nextRouteInstruction : 'No action note was added. Open the file and review the route details before marking it complete.') ?>
          </p>
          <a class="btn btn-dark" href="<?= BASE_URL ?>/documents/view?id=<?= $nextRouteDocId ?><?= $nextRouteExternal ? '&open_editor=1' : '' ?>">Open Next File</a>
        <?php else: ?>
          <strong class="staff-dashboard__spotlight-title">No routed files waiting right now</strong>
          <p class="staff-dashboard__spotlight-instruction">You are caught up. New routed files and their actions to be taken will appear here when they arrive.</p>
        <?php endif; ?>
      </div>
    </div>
  </section>

  <section class="staff-dashboard__metrics">
    <article class="staff-dashboard__metric">
      <span>Need action</span>
      <strong><?= $incomingCount ?></strong>
      <small>active routed files currently assigned to you</small>
    </article>
    <article class="staff-dashboard__metric">
      <span>In workflow</span>
      <strong><?= $incomingCount ?></strong>
      <small>routed documents currently active in your workflow</small>
    </article>
    <article class="staff-dashboard__metric">
      <span>Completed this cycle</span>
      <strong><?= $completedCount ?></strong>
      <small>files already marked routing completed</small>
    </article>
  </section>

  <section id="routing-inbox" class="surface-card dashboard-panel mb-3">
    <div class="table-card__meta">
      <div>
        <h2 class="surface-card__title mb-1">Routed Files</h2>
        <p class="surface-card__copy mb-0">Use this full list when you want to scan all routed files assigned to you, including completed ones.</p>
      </div>
      <span class="badge-soft badge-soft--primary"><?= $routedTotal ?> total</span>
    </div>

    <?php if(empty($routedInbox)): ?>
      <div class="table-empty px-0 pb-0">No routed files are assigned to you right now.</div>
    <?php else: ?>
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
              <th>Routing Date and Time</th>
              <th class="text-end">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach($routedInbox as $doc): ?>
              <?php
                $docId = (int)($doc['id'] ?? 0);
                $docExt = strtolower((string)pathinfo((string)($doc['name'] ?? ''), PATHINFO_EXTENSION));
                $openInExternalEditor = in_array($docExt, ['doc', 'docx', 'xls', 'xlsx'], true);
                $priorityTone = staff_dashboard_priority_tone((string)($doc['priority_level'] ?? 'MODERATE'));
                [$statusLabel, $statusClass] = staff_dashboard_status_badge((string)($doc['route_state'] ?? 'ROUTED'));
                $displayName = trim((string)($doc['title'] ?? $doc['name'])) !== '' ? trim((string)($doc['title'] ?? $doc['name'])) : 'Untitled file';
                $docCodeLabel = trim((string)($doc['document_code'] ?? ''));
                $docDateLabel = trim((string)($doc['document_date'] ?? '')) !== '' ? (string)$doc['document_date'] : 'Not set';
                $senderLabel = trim((string)($doc['signatory'] ?? '')) !== '' ? (string)$doc['signatory'] : 'Not set';
                $routeSentAt = staff_dashboard_route_timestamp($doc);
                $routingDateTimeLabel = staff_dashboard_datetime($routeSentAt, 'Not set');
                $isCompletedRoute = strtoupper((string)($doc['route_state'] ?? 'ROUTED')) === 'COMPLETED';
              ?>
              <tr class="admin-dashboard-row admin-dashboard-row--<?= e($priorityTone) ?>" data-dashboard-row>
                <td>
                  <a href="<?= BASE_URL ?>/documents/view?id=<?= $docId ?><?= $openInExternalEditor ? '&open_editor=1' : '' ?>" class="fw-semibold text-decoration-none admin-priority-file admin-priority-file--<?= e($priorityTone) ?>"><?= e($displayName) ?></a>
                </td>
                <td class="text-muted small"><?= e($docCodeLabel) ?></td>
                <td class="text-muted small"><?= e($docDateLabel) ?></td>
                <td class="text-muted small"><?= e($senderLabel) ?></td>
                <td><span class="<?= e($statusClass) ?>"><?= e($statusLabel) ?></span></td>
                <td>
                  <div class="admin-dashboard-location">
                    <strong><?= e($staffLocationLabel) ?></strong>
                    <small><?= e($staffLocationMeta) ?></small>
                  </div>
                </td>
                <td class="text-muted small"><?= e($routingDateTimeLabel) ?></td>
                <td class="text-end">
                  <div class="btn-group btn-group-sm" role="group" aria-label="File actions">
                    <a class="btn btn-outline-secondary" href="<?= BASE_URL ?>/documents/view?id=<?= $docId ?><?= $openInExternalEditor ? '&open_editor=1' : '' ?>" title="View file"><i class="bi bi-eye"></i></a>
                    <?php if(!$isCompletedRoute): ?>
                      <form method="POST" action="<?= BASE_URL ?>/documents/route/complete" class="d-inline">
                        <?= csrf_field() ?>
                        <input type="hidden" name="id" value="<?= $docId ?>">
                        <button class="btn btn-success" type="submit" title="Mark route complete"><i class="bi bi-check-circle"></i></button>
                      </form>
                    <?php endif; ?>
                  </div>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php if($routedPages > 1): ?>
        <nav aria-label="Routed files pagination" class="mt-3">
          <ul class="pagination pagination-sm mb-0">
            <?php for($p = 1; $p <= $routedPages; $p++): ?>
              <li class="page-item <?= $p === $routedPage ? 'active' : '' ?>">
                <a class="page-link" href="<?= BASE_URL ?>/dashboard?page=<?= $p ?>#routing-inbox"><?= $p ?></a>
              </li>
            <?php endfor; ?>
          </ul>
        </nav>
      <?php endif; ?>
    <?php endif; ?>
  </section>

</div>

<?php require __DIR__ . "/../layouts/footer.php"; ?>
