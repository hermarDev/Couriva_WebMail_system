<?php
/**
 * Couriva - Administrator System Audit Logs
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_admin();

$perPage = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $perPage;

$filterAction = trim($_GET['action'] ?? '');
$filterStatus = trim($_GET['status'] ?? '');
$filterDateFrom = trim($_GET['date_from'] ?? '');
$filterDateTo = trim($_GET['date_to'] ?? '');
$search = trim($_GET['search'] ?? '');

$where = [];
$params = [];

if (!empty($filterAction)) {
    $where[] = "l.action = ?";
    $params[] = $filterAction;
}

if (!empty($filterStatus)) {
    $where[] = "l.status = ?";
    $params[] = $filterStatus;
}

if (!empty($filterDateFrom) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateFrom)) {
    $where[] = "l.created_at >= ?";
    $params[] = $filterDateFrom . ' 00:00:00';
}

if (!empty($filterDateTo) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterDateTo)) {
    $where[] = "l.created_at <= ?";
    $params[] = $filterDateTo . ' 23:59:59';
}

if (!empty($search)) {
    $where[] = "(l.details LIKE ? OR l.ip_address LIKE ? OR u.email LIKE ?)";
    $term = '%' . $search . '%';
    $params[] = $term;
    $params[] = $term;
    $params[] = $term;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("
    SELECT COUNT(*) 
    FROM system_logs l
    LEFT JOIN users u ON l.user_id = u.id
    {$whereSql}
");
$countStmt->execute($params);
$totalLogs = (int)$countStmt->fetchColumn();
$totalPages = ceil($totalLogs / $perPage);

$logsStmt = $pdo->prepare("
    SELECT l.*, u.email as user_email, u.first_name, u.last_name
    FROM system_logs l
    LEFT JOIN users u ON l.user_id = u.id
    {$whereSql}
    ORDER BY l.created_at DESC
    LIMIT {$perPage} OFFSET {$offset}
");
$logsStmt->execute($params);
$logs = $logsStmt->fetchAll();

// Get unique actions for filter dropdown
$actions = $pdo->query("SELECT DISTINCT action FROM system_logs ORDER BY action ASC")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = 'System Audit Logs';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-journal-text text-primary me-2"></i>System Audit Logs</h4>
        <p class="text-muted small mb-0">Complete historical security, access, and operational event trails</p>
    </div>
    <a href="<?= app_base_url('admin/index.php') ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
        <i class="bi bi-arrow-left"></i>
        <span>Dashboard</span>
    </a>
</div>

<!-- Filters Bar -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-body p-3">
        <form method="GET" action="<?= app_base_url('admin/logs.php') ?>" class="row g-2 align-items-center">
            <div class="col-md-3">
                <input type="text" name="search" class="form-control form-control-sm" placeholder="Search IP, details, or user email..." value="<?= e($search) ?>">
            </div>
            <div class="col-md-2">
                <select name="action" class="form-select form-select-sm">
                    <option value="">All Actions</option>
                    <?php foreach ($actions as $act): ?>
                        <option value="<?= e($act) ?>" <?= $filterAction === $act ? 'selected' : '' ?>><?= e($act) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <select name="status" class="form-select form-select-sm">
                    <option value="">All Statuses</option>
                    <option value="SUCCESS" <?= $filterStatus === 'SUCCESS' ? 'selected' : '' ?>>SUCCESS</option>
                    <option value="FAILED" <?= $filterStatus === 'FAILED' ? 'selected' : '' ?>>FAILED</option>
                    <option value="INFO" <?= $filterStatus === 'INFO' ? 'selected' : '' ?>>INFO</option>
                    <option value="WARNING" <?= $filterStatus === 'WARNING' ? 'selected' : '' ?>>WARNING</option>
                </select>
            </div>
            <div class="col-md-2">
                <input type="date" name="date_from" class="form-control form-control-sm" title="From Date" value="<?= e($filterDateFrom) ?>">
            </div>
            <div class="col-md-2">
                <input type="date" name="date_to" class="form-control form-control-sm" title="To Date" value="<?= e($filterDateTo) ?>">
            </div>
            <div class="col-md-1 d-flex gap-1">
                <button type="submit" class="btn btn-sm btn-primary w-100" title="Filter"><i class="bi bi-search"></i></button>
                <?php if (!empty($filterAction) || !empty($filterStatus) || !empty($search) || !empty($filterDateFrom) || !empty($filterDateTo)): ?>
                    <a href="<?= app_base_url('admin/logs.php') ?>" class="btn btn-sm btn-light border" title="Reset"><i class="bi bi-x-lg"></i></a>
                <?php endif; ?>
            </div>
        </form>
    </div>
</div>

<!-- Logs Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase">
                <tr>
                    <th>ID</th>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>Status</th>
                    <th>User</th>
                    <th>IP Address</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="7" class="text-center py-5 text-muted">No audit logs matching query criteria.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $l): ?>
                        <tr>
                            <td class="text-muted small"><?= $l['id'] ?></td>
                            <td class="text-nowrap small text-muted"><?= date('Y-m-d H:i:s', strtotime($l['created_at'])) ?></td>
                            <td><span class="badge bg-secondary font-monospace"><?= e($l['action']) ?></span></td>
                            <td>
                                <?php if ($l['status'] === 'SUCCESS'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">SUCCESS</span>
                                <?php elseif ($l['status'] === 'FAILED'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">FAILED</span>
                                <?php else: ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle"><?= e($l['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small">
                                <?= $l['user_email'] ? e($l['user_email']) : '<span class="text-muted font-italic">System</span>' ?>
                            </td>
                            <td class="small font-monospace"><?= e($l['ip_address']) ?></td>
                            <td class="small text-muted"><?= e($l['details']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <div class="card-footer bg-white py-2 d-flex justify-content-between align-items-center">
            <span class="small text-muted">Showing page <?= $page ?> of <?= $totalPages ?> (<?= $totalLogs ?> records)</span>
            <div class="btn-group btn-group-sm">
                <a href="?page=<?= max(1, $page - 1) ?>&action=<?= urlencode($filterAction) ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>" class="btn btn-light border <?= $page <= 1 ? 'disabled' : '' ?>">Previous</a>
                <a href="?page=<?= min($totalPages, $page + 1) ?>&action=<?= urlencode($filterAction) ?>&status=<?= urlencode($filterStatus) ?>&search=<?= urlencode($search) ?>" class="btn btn-light border <?= $page >= $totalPages ? 'disabled' : '' ?>">Next</a>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
