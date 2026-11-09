<?php
/**
 * Couriva - Notification Center
 * In-app notifications for new mail, account approval, schedules, and spam
 * reviews. Opening a notification marks it read and follows its stored link.
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';

require_auth();

$userId = (int)$_SESSION['user_id'];
$activeFolder = 'notifications';
$pageTitle = 'Notifications';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        set_flash('danger', 'Security token expired. Please try again.');
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'mark_all_read') {
            $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL");
            $stmt->execute([$userId]);
            set_flash('success', 'All notifications marked as read.');
        } elseif ($action === 'mark_read') {
            $notificationId = (int)($_POST['notification_id'] ?? 0);
            $stmt = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL");
            $stmt->execute([$notificationId, $userId]);
        } elseif ($action === 'delete_read') {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE user_id = ? AND read_at IS NOT NULL");
            $stmt->execute([$userId]);
            set_flash('success', 'Read notifications cleared.');
        }

        header('Location: ' . app_base_url('public/notifications.php'));
        exit;
    }
}

// Opening a notification marks it read before following the link.
if (!empty($_GET['open'])) {
    $notificationId = (int)$_GET['open'];
    $stmt = $pdo->prepare("SELECT * FROM notifications WHERE id = ? AND user_id = ? LIMIT 1");
    $stmt->execute([$notificationId, $userId]);
    $notification = $stmt->fetch();

    if ($notification) {
        if (empty($notification['read_at'])) {
            $mark = $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?");
            $mark->execute([$notificationId, $userId]);
        }
        header('Location: ' . safe_notification_link($notification['link']));
        exit;
    }

    set_flash('warning', 'That notification is no longer available.');
    header('Location: ' . app_base_url('public/notifications.php'));
    exit;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 30;
$offset = ($page - 1) * $perPage;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ?");
$countStmt->execute([$userId]);
$totalNotifications = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalNotifications / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmt = $pdo->prepare("
    SELECT * FROM notifications
    WHERE user_id = ?
    ORDER BY created_at DESC, id DESC
    LIMIT ? OFFSET ?
");
$stmt->bindValue(1, (int)$userId, PDO::PARAM_INT);
$stmt->bindValue(2, (int)$perPage, PDO::PARAM_INT);
$stmt->bindValue(3, (int)$offset, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll();

$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND read_at IS NULL");
$unreadStmt->execute([$userId]);
$unreadCount = (int)$unreadStmt->fetchColumn();

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex flex-wrap justify-content-between align-items-center mb-4 gap-2">
    <div>
        <h4 class="fw-bold mb-1"><i class="bi bi-bell-fill text-primary me-2"></i>Notifications</h4>
        <p class="text-muted small mb-0">
            <?= $unreadCount ?> unread &middot; <?= $totalNotifications ?> total
        </p>
    </div>
    <div class="d-flex flex-wrap gap-2">
        <?php if ($unreadCount > 0): ?>
            <form method="POST" action="<?= app_base_url('public/notifications.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="mark_all_read">
                <button type="submit" class="btn btn-outline-primary btn-sm">
                    <i class="bi bi-check2-all me-1"></i>Mark all read
                </button>
            </form>
        <?php endif; ?>
        <form method="POST" action="<?= app_base_url('public/notifications.php') ?>" data-confirm="Clear all read notifications?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete_read">
            <button type="submit" class="btn btn-outline-secondary btn-sm">
                <i class="bi bi-trash3 me-1"></i>Clear read
            </button>
        </form>
    </div>
</div>

<div class="card shadow-sm border-0">
    <?php if (empty($notifications)): ?>
        <div class="empty-state-container">
            <i class="bi bi-bell-slash empty-state-icon"></i>
            <div class="empty-state-title">No notifications yet</div>
            <div class="empty-state-subtitle">New mail, account decisions, scheduled sends, and spam reviews appear here.</div>
        </div>
    <?php else: ?>
        <div class="list-group list-group-flush">
            <?php foreach ($notifications as $notification): ?>
                <?php
                $isUnread = empty($notification['read_at']);
                $icon = match ($notification['type']) {
                    'new_mail' => 'bi-envelope-fill text-primary',
                    'approval' => 'bi-person-check-fill text-success',
                    'schedule' => 'bi-calendar2-check-fill text-info',
                    'spam_report', 'spam_review' => 'bi-shield-exclamation text-danger',
                    default => 'bi-info-circle-fill text-secondary',
                };
                ?>
                <div class="notification-item <?= $isUnread ? 'notification-unread' : '' ?>">
                    <div class="d-flex align-items-start gap-3">
                        <span class="notification-icon"><i class="bi <?= e($icon) ?>"></i></span>
                        <div class="flex-grow-1 min-w-0">
                            <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
                                <a href="<?= app_base_url('public/notifications.php?open=' . (int)$notification['id']) ?>" class="notification-title <?= $isUnread ? 'fw-bold' : 'fw-semibold' ?> text-decoration-none text-body">
                                    <?= e($notification['title']) ?>
                                </a>
                                <span class="text-muted small text-nowrap" title="<?= e(format_user_datetime($notification['created_at'], 'M d, Y g:i A')) ?>">
                                    <?= e(format_user_datetime($notification['created_at'], 'M d, g:i A')) ?>
                                </span>
                            </div>
                            <?php if (!empty($notification['message'])): ?>
                                <div class="text-secondary small mt-1"><?= e($notification['message']) ?></div>
                            <?php endif; ?>
                            <div class="d-flex align-items-center gap-2 mt-2">
                                <span class="badge bg-body-secondary text-secondary border text-capitalize"><?= e(str_replace('_', ' ', $notification['type'])) ?></span>
                                <?php if ($isUnread): ?>
                                    <form method="POST" action="<?= app_base_url('public/notifications.php') ?>" class="d-inline">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="action" value="mark_read">
                                        <input type="hidden" name="notification_id" value="<?= (int)$notification['id'] ?>">
                                        <button type="submit" class="btn btn-link btn-sm p-0 text-decoration-none">Mark read</button>
                                    </form>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php if ($isUnread): ?><span class="notification-unread-dot" title="Unread"></span><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-body d-flex justify-content-between align-items-center">
                <span class="small text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
                <div class="btn-group btn-group-sm">
                    <a href="<?= app_base_url('public/notifications.php?page=' . max(1, $page - 1)) ?>" class="btn btn-light border <?= $page <= 1 ? 'disabled' : '' ?>" aria-label="Previous page"><i class="bi bi-chevron-left"></i></a>
                    <a href="<?= app_base_url('public/notifications.php?page=' . min($totalPages, $page + 1)) ?>" class="btn btn-light border <?= $page >= $totalPages ? 'disabled' : '' ?>" aria-label="Next page"><i class="bi bi-chevron-right"></i></a>
                </div>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
