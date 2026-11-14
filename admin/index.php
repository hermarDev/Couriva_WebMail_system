<?php
/**
 * Couriva - Administrator Dashboard
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_admin();

// Fetch statistics
$userCount = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
$activeUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$disabledUsers = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE status = 'disabled'")->fetchColumn();
$totalEmails = (int)$pdo->query("SELECT COUNT(*) FROM emails")->fetchColumn();
$totalAttachmentBytes = (int)$pdo->query("SELECT COALESCE(SUM(filesize), 0) FROM attachments")->fetchColumn();

// Fetch recent system activity
$logsStmt = $pdo->query("
    SELECT l.*, u.email as user_email 
    FROM system_logs l
    LEFT JOIN users u ON l.user_id = u.id
    ORDER BY l.created_at DESC
    LIMIT 10
");
$recentLogs = $logsStmt->fetchAll();

// The database figure is a rough estimate, not measured disk usage.
$estimatedDbBytes = $totalEmails * 4096;

$pageTitle = 'Administrator Dashboard';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h3 class="fw-bold mb-1"><i class="bi bi-shield-lock-fill text-primary me-2"></i>Couriva Administration</h3>
        <p class="text-muted small mb-0">System Overview, Storage Analytics, User Governance &amp; Security Logs</p>
    </div>
    <div class="d-flex gap-2">
        <a href="<?= app_base_url('admin/users.php') ?>" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm">
            <i class="bi bi-people-fill"></i>
            <span>Manage Users</span>
        </a>
        <a href="<?= app_base_url('admin/logs.php') ?>" class="btn btn-outline-secondary btn-sm d-flex align-items-center gap-1">
            <i class="bi bi-journal-text"></i>
            <span>System Logs</span>
        </a>
    </div>
</div>

<!-- Modern Gradient Metrics Cards -->
<div class="row g-3 mb-4">
    <div class="col-sm-6 col-lg-3">
        <div class="card stat-card border-0 shadow-sm h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small text-uppercase fw-bold">Total Accounts</span>
                <span class="p-2 bg-primary-subtle text-primary rounded-circle"><i class="bi bi-people-fill fs-5"></i></span>
            </div>
            <h2 class="fw-bold my-1 text-body"><?= number_format($userCount) ?></h2>
            <div class="small d-flex gap-2 mt-2">
                <span class="badge bg-success-subtle text-success border border-success-subtle"><?= $activeUsers ?> Active</span>
                <span class="badge bg-danger-subtle text-danger border border-danger-subtle"><?= $disabledUsers ?> Disabled</span>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card stat-card border-0 shadow-sm h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small text-uppercase fw-bold">Total Messages</span>
                <span class="p-2 bg-info-subtle text-info rounded-circle"><i class="bi bi-envelope-check-fill fs-5"></i></span>
            </div>
            <h2 class="fw-bold my-1 text-primary"><?= number_format($totalEmails) ?></h2>
            <span class="small text-muted">Stored messages across mailboxes</span>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card stat-card border-0 shadow-sm h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small text-uppercase fw-bold">Attachments Disk</span>
                <span class="p-2 bg-warning-subtle text-warning rounded-circle"><i class="bi bi-paperclip fs-5"></i></span>
            </div>
            <h2 class="fw-bold my-1 text-body"><?= format_bytes($totalAttachmentBytes) ?></h2>
            <span class="small text-muted">Uploaded storage utilized</span>
        </div>
    </div>

    <div class="col-sm-6 col-lg-3">
        <div class="card stat-card border-0 shadow-sm h-100 position-relative overflow-hidden">
            <div class="d-flex justify-content-between align-items-start mb-2">
                <span class="text-muted small text-uppercase fw-bold">Environment</span>
                <span class="p-2 bg-success-subtle text-success rounded-circle"><i class="bi bi-hdd-network-fill fs-5"></i></span>
            </div>
            <h4 class="fw-bold my-1 text-body"><?= PHP_OS_FAMILY ?> &bull; PHP <?= PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION ?></h4>
            <span class="small text-muted">Service health not monitored here</span>
        </div>
    </div>
</div>

<!-- Storage Breakdown Analytics Card -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-body py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
            <i class="bi bi-pie-chart-fill text-primary"></i>
            <span>System Storage Breakdown</span>
        </h5>
    </div>
    <div class="card-body p-4">
        <p class="small text-muted">Attachment sizes are summed from stored records. Database size is estimated at 4 KB per message; actual disk capacity is not measured.</p>
        <div class="row g-3">
            <div class="col-sm-6">
                <div class="fw-semibold small">Attachments</div>
                <div class="text-muted small"><?= format_bytes($totalAttachmentBytes) ?></div>
            </div>
            <div class="col-sm-6">
                <div class="fw-semibold small">Estimated database and mail index</div>
                <div class="text-muted small"><?= format_bytes($estimatedDbBytes) ?></div>
            </div>
        </div>
    </div>
</div>

<!-- Recent Activity Log Table -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-body py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
            <i class="bi bi-clock-history text-primary"></i>
            <span>Recent System Activity</span>
        </h5>
        <a href="<?= app_base_url('admin/logs.php') ?>" class="btn btn-sm btn-outline-primary">View All Logs &rarr;</a>
    </div>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="bg-body-tertiary small text-uppercase">
                <tr>
                    <th>Timestamp</th>
                    <th>Action</th>
                    <th>User</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recentLogs)): ?>
                    <tr><td colspan="6" class="text-center py-4 text-muted">No activity logged yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($recentLogs as $log): ?>
                        <tr>
                            <td class="text-nowrap small text-muted"><?= date('Y-m-d H:i:s', strtotime($log['created_at'])) ?></td>
                            <td><span class="badge bg-secondary-subtle text-secondary border font-monospace"><?= e($log['action']) ?></span></td>
                            <td class="small fw-semibold"><?= e($log['user_email'] ?? 'System / Anonymous') ?></td>
                            <td class="small font-monospace text-muted"><?= e($log['ip_address']) ?></td>
                            <td>
                                <?php if ($log['status'] === 'SUCCESS'): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">SUCCESS</span>
                                <?php elseif ($log['status'] === 'FAILED'): ?>
                                    <span class="badge bg-danger-subtle text-danger border border-danger-subtle">FAILED</span>
                                <?php elseif ($log['status'] === 'WARNING'): ?>
                                    <span class="badge bg-warning-subtle text-warning border border-warning-subtle">WARNING</span>
                                <?php else: ?>
                                    <span class="badge bg-info-subtle text-info border border-info-subtle"><?= e($log['status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td class="small text-muted text-truncate" style="max-width: 320px;" title="<?= e($log['details']) ?>"><?= e($log['details']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
