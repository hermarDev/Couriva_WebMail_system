<?php
/**
 * Couriva - Feature Acceptance Test Suite
 *
 * Covers the features added on top of the original mailbox:
 *  - @couriva.local identity helpers
 *  - pending registration -> administrator approval / rejection
 *  - labels, custom folders, archive, snooze
 *  - scheduled send cancellation and delivery
 *  - notifications and safe links
 *  - sender blocking and spam report review
 *
 * Run with:
 *   DB_NAME=webmail_test php tests/couriva_feature_test.php
 */

declare(strict_types=1);

if (getenv('DB_NAME') === false) {
    putenv('DB_NAME=webmail_test');
}
// Keep scheduled and ordinary delivery fully local in tests.
putenv('MAIL_DRIVER=database');

define('WEBMAIL_INIT', true);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Models/Attachment.php';
require_once __DIR__ . '/../app/Services/AttachmentService.php';
require_once __DIR__ . '/../app/Services/SmtpTransport.php';
require_once __DIR__ . '/../app/Services/MailService.php';
require_once __DIR__ . '/../app/Services/OrganizationService.php';
require_once __DIR__ . '/../app/Services/ScheduledMailService.php';
require_once __DIR__ . '/../app/Services/SpamService.php';
require_once __DIR__ . '/../app/Services/AccountService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

use App\Models\Email;
use App\Models\User;
use App\Services\AccountService;
use App\Services\AuthService;
use App\Services\MailService;
use App\Services\OrganizationService;
use App\Services\ScheduledMailService;
use App\Services\SpamService;

$pdo = getDbConnection();
$passed = 0;
$failed = 0;

function assertCouriva(bool $condition, string $name, string $details = ''): void
{
    global $passed, $failed;

    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] {$name}\n";
        return;
    }

    $failed++;
    echo "[\033[31mFAIL\033[0m] {$name}" . ($details !== '' ? " — {$details}" : '') . "\n";
}

function courivaSearchIds(MailService $service, int $userId, string $folder, array $criteria = []): array
{
    $result = $service->searchEmails($userId, $folder, $criteria, 1, 100);
    return array_map(static fn (array $email): int => (int)$email['id'], $result['emails']);
}

$suffix = time() . '_' . bin2hex(random_bytes(3));
$createdUserIds = [];
$createdEmailIds = [];

$userModel = new User($pdo);
$emailModel = new Email($pdo);
$mailConfig = require __DIR__ . '/../config/mail.php';
$mailConfig['driver'] = 'database';
$mailService = new MailService($pdo, $mailConfig);
$organizationService = new OrganizationService($pdo);
$scheduledService = new ScheduledMailService($pdo);
$spamService = new SpamService($pdo);
$accountService = new AccountService($pdo);
$authService = new AuthService($pdo, ['enabled' => false]);

$makeUser = function (string $label, string $status = 'active', string $role = 'user') use ($userModel, &$createdUserIds, $suffix): array {
    $email = strtolower($label) . '.' . $suffix . '@couriva.local';
    $id = $userModel->create([
        'email'                => $email,
        'password'             => 'Couriva-Test-Pass-123!',
        'first_name'           => ucfirst($label),
        'last_name'            => 'Feature',
        'role'                 => $role,
        'status'               => $status,
        'timezone'             => 'UTC',
        'must_change_password' => 0,
    ]);
    $createdUserIds[] = $id;

    $stmt = $GLOBALS['pdo']->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    return $stmt->fetch();
};

$makeEmail = function (array $data) use ($emailModel, &$createdEmailIds): int {
    $id = $emailModel->create($data);
    $createdEmailIds[] = $id;
    return $id;
};

try {
    echo "=== Couriva Feature Acceptance Tests ===\n";
    echo 'Database: ' . (getenv('DB_NAME') ?: 'default') . "\n\n";

    $adminRecord = $userModel->findByEmail('admin@couriva.local');
    assertCouriva($adminRecord !== null && $adminRecord['role'] === 'admin', 'Seed administrator exists on the Couriva domain');

    // ------------------------------------------------------------------
    // Domain helpers
    // ------------------------------------------------------------------
    echo "--- Identity ---\n";
    assertCouriva(app_domain() === 'couriva.local', 'Default application domain is couriva.local');
    assertCouriva(couriva_address('Alice.Smith') === 'alice.smith@couriva.local', 'Usernames map to @couriva.local addresses');
    assertCouriva(couriva_address('someone@gmail.com') === 'someone@couriva.local', 'Existing domain suffix is stripped before mapping');
    assertCouriva(is_couriva_address('alice@couriva.local') && !is_couriva_address('alice@gmail.com'), 'Local-address detection ignores external addresses');

    // ------------------------------------------------------------------
    // Registration approval workflow
    // ------------------------------------------------------------------
    echo "\n--- Registration approval ---\n";
    $applicant = $makeUser('applicant', 'pending');
    $pdo->prepare('UPDATE users SET requested_at = NOW() WHERE id = ?')->execute([(int)$applicant['id']]);

    $blockedLogin = $authService->authenticate($applicant['email'], 'Couriva-Test-Pass-123!');
    assertCouriva($blockedLogin['success'] === false, 'Pending account cannot sign in');
    assertCouriva(str_contains((string)$blockedLogin['message'], 'awaiting administrator approval'), 'Pending sign-in explains the approval requirement', (string)$blockedLogin['message']);

    $nonAdminReview = $accountService->approve((int)$makeUser('notadmin')['id'], (int)$applicant['id']);
    assertCouriva($nonAdminReview['success'] === false, 'Non-administrator cannot approve accounts');

    $approval = $accountService->approve((int)$adminRecord['id'], (int)$applicant['id']);
    assertCouriva($approval['success'] === true, 'Administrator can approve a pending account', $approval['message']);

    $approvedRecord = $userModel->findById((int)$applicant['id']);
    assertCouriva($approvedRecord['status'] === 'active', 'Approved account becomes active');
    assertCouriva((int)$approvedRecord['reviewed_by'] === (int)$adminRecord['id'], 'Approval records the reviewing administrator');

    $approvedLogin = $authService->authenticate($applicant['email'], 'Couriva-Test-Pass-123!');
    assertCouriva($approvedLogin['success'] === true, 'Approved account can sign in');

    $approvalNotice = $pdo->prepare("SELECT COUNT(*) FROM notifications WHERE user_id = ? AND type = 'approval'");
    $approvalNotice->execute([(int)$applicant['id']]);
    assertCouriva((int)$approvalNotice->fetchColumn() >= 1, 'Approval creates a notification for the user');

    $rejectedUser = $makeUser('rejected', 'pending');
    $rejection = $accountService->reject((int)$adminRecord['id'], (int)$rejectedUser['id'], 'Request does not match an internal team.');
    assertCouriva($rejection['success'] === true, 'Administrator can reject a pending account');
    $rejectedRecord = $userModel->findById((int)$rejectedUser['id']);
    assertCouriva($rejectedRecord['status'] === 'rejected', 'Rejected account is stored as rejected');
    assertCouriva(str_contains((string)$rejectedRecord['rejection_reason'], 'internal team'), 'Rejection reason is stored');
    $rejectedLogin = $authService->authenticate($rejectedUser['email'], 'Couriva-Test-Pass-123!');
    assertCouriva($rejectedLogin['success'] === false && str_contains((string)$rejectedLogin['message'], 'not approved'), 'Rejected account cannot sign in');

    // Mail to a still-pending user must not be delivered into a mailbox.
    $pendingRecipient = $makeUser('pendingbox', 'pending');
    $sender = $makeUser('sender');
    $recipient = $makeUser('recipient');
    $observer = $makeUser('observer');

    $pendingSend = $mailService->sendEmail(
        (int)$sender['id'],
        ['to' => [$pendingRecipient['email']]],
        'Pending delivery test',
        'This must not enter an unapproved mailbox.'
    );
    assertCouriva(($pendingSend['success'] ?? false) === true, 'Sender can submit a message to a pending address');
    $pendingMailCount = $pdo->prepare('SELECT COUNT(*) FROM emails WHERE recipient_id = ?');
    $pendingMailCount->execute([(int)$pendingRecipient['id']]);
    assertCouriva((int)$pendingMailCount->fetchColumn() === 0, 'Mail is not delivered to a pending account');

    // ------------------------------------------------------------------
    // Labels and custom folders
    // ------------------------------------------------------------------
    echo "\n--- Labels and folders ---\n";
    $workLabel = $organizationService->createLabel((int)$recipient['id'], 'Work ' . $suffix, '#2563eb');
    $senderLabel = $organizationService->createLabel((int)$sender['id'], 'Private ' . $suffix, '#16a34a');
    $projectFolder = $organizationService->createFolder((int)$recipient['id'], 'Projects ' . $suffix, '#7c3aed');

    $labeledEmail = $makeEmail([
        'sender_id'       => (int)$sender['id'],
        'recipient_id'    => (int)$recipient['id'],
        'subject'         => 'Labeled message ' . $suffix,
        'body'            => 'Body for label tests.',
        'folder'          => 'inbox',
    ]);

    assertCouriva($organizationService->assignLabel((int)$recipient['id'], [$labeledEmail], $workLabel) === 1, 'Owner can apply a label');
    assertCouriva(count($organizationService->getLabelsForEmail($labeledEmail, (int)$recipient['id'])) === 1, 'Applied label is hydrated for the message');
    assertCouriva(count(courivaSearchIds($mailService, (int)$recipient['id'], 'label', ['label_id' => $workLabel])) === 1, 'Label scope returns the labeled message');

    $foreignLabelRejected = false;
    try {
        $organizationService->assignLabel((int)$recipient['id'], [$labeledEmail], $senderLabel);
    } catch (InvalidArgumentException $e) {
        $foreignLabelRejected = true;
    }
    assertCouriva($foreignLabelRejected, 'A user cannot apply another user’s label');
    assertCouriva($organizationService->assignLabel((int)$sender['id'], [$labeledEmail], $senderLabel) === 0, 'A user cannot label a message they do not own');

    assertCouriva($organizationService->removeLabel((int)$recipient['id'], [$labeledEmail], $workLabel) === 1, 'Owner can remove a label');

    assertCouriva($organizationService->moveToFolder((int)$recipient['id'], [$labeledEmail], $projectFolder) === 1, 'Message can move into a custom folder');
    $movedRow = $emailModel->findById($labeledEmail);
    assertCouriva((int)$movedRow['custom_folder_id'] === $projectFolder, 'Custom folder is stored on the message');
    assertCouriva(in_array($labeledEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'custom', ['folder_id' => $projectFolder]), true), 'Custom folder scope returns the message');
    assertCouriva(!in_array($labeledEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'inbox'), true), 'Custom-folder mail leaves Inbox');

    assertCouriva($organizationService->deleteFolder((int)$recipient['id'], $projectFolder) === true, 'Owner can delete a custom folder');
    $afterFolderDelete = $emailModel->findById($labeledEmail);
    assertCouriva($afterFolderDelete['custom_folder_id'] === null, 'Deleting a folder returns messages to their system folder');

    // ------------------------------------------------------------------
    // Archive and snooze
    // ------------------------------------------------------------------
    echo "\n--- Archive and snooze ---\n";
    $archiveEmail = $makeEmail([
        'sender_id'    => (int)$sender['id'],
        'recipient_id' => (int)$recipient['id'],
        'subject'      => 'Archive test ' . $suffix,
        'body'         => 'Archive body.',
        'folder'       => 'inbox',
    ]);

    assertCouriva($mailService->batchArchive((int)$recipient['id'], [$archiveEmail], true) === 1, 'Message can be archived');
    assertCouriva(!in_array($archiveEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'inbox'), true), 'Archived mail leaves Inbox');
    assertCouriva(in_array($archiveEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'archive'), true), 'Archive scope returns archived mail');
    assertCouriva($mailService->batchArchive((int)$recipient['id'], [$archiveEmail], false) === 1, 'Message can be unarchived');
    assertCouriva(in_array($archiveEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'inbox'), true), 'Unarchived mail returns to Inbox');

    $snoozeUntil = (new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    assertCouriva($mailService->batchSnooze((int)$recipient['id'], [$archiveEmail], $snoozeUntil) === 1, 'Message can be snoozed');
    assertCouriva(!in_array($archiveEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'inbox'), true), 'Snoozed mail leaves Inbox');
    assertCouriva(in_array($archiveEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'snoozed'), true), 'Snoozed scope returns snoozed mail');
    assertCouriva($mailService->batchUnsnooze((int)$recipient['id'], [$archiveEmail]) === 1, 'Message can be unsnoozed');
    assertCouriva(in_array($archiveEmail, courivaSearchIds($mailService, (int)$recipient['id'], 'inbox'), true), 'Unsnoozed mail returns to Inbox');

    $pastSnoozeRejected = false;
    try {
        $mailService->batchSnooze((int)$recipient['id'], [$archiveEmail], '2020-01-01 00:00:00');
    } catch (InvalidArgumentException $e) {
        $pastSnoozeRejected = true;
    }
    assertCouriva($pastSnoozeRejected, 'Past snooze times are rejected');

    // ------------------------------------------------------------------
    // Scheduled sending
    // ------------------------------------------------------------------
    echo "\n--- Scheduled sending ---\n";
    $draftId = $makeEmail([
        'sender_id'    => (int)$sender['id'],
        'subject'      => 'Scheduled subject ' . $suffix,
        'body'         => 'Scheduled body.',
        'is_draft'     => 1,
        'folder'       => 'draft',
    ]);
    $emailModel->addRecipient($draftId, (int)$recipient['id'], $recipient['email'], 'to');

    $future = (new DateTimeImmutable('+2 hours', new DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    $scheduleId = $scheduledService->schedule((int)$sender['id'], $draftId, $future, 'UTC');
    assertCouriva($scheduleId > 0, 'Draft can be scheduled');
    assertCouriva($scheduledService->countPending((int)$sender['id']) >= 1, 'Pending schedule is counted');

    $scheduleRows = $scheduledService->getForUser((int)$sender['id']);
    $createdSchedule = null;
    foreach ($scheduleRows as $row) {
        if ((int)$row['id'] === $scheduleId) {
            $createdSchedule = $row;
            break;
        }
    }
    assertCouriva($createdSchedule !== null && $createdSchedule['status'] === 'scheduled', 'Schedule is listed as scheduled');
    assertCouriva($scheduledService->cancel((int)$sender['id'], $scheduleId) === true, 'Owner can cancel a schedule');
    assertCouriva($scheduledService->cancel((int)$sender['id'], $scheduleId) === false, 'Cancelling twice is rejected');

    $dueDraftId = $makeEmail([
        'sender_id'    => (int)$sender['id'],
        'subject'      => 'Due scheduled ' . $suffix,
        'body'         => 'Deliver this scheduled body.',
        'is_draft'     => 1,
        'folder'       => 'draft',
    ]);
    $emailModel->addRecipient($dueDraftId, (int)$recipient['id'], $recipient['email'], 'to');
    $pdo->prepare("
        INSERT INTO scheduled_emails (user_id, draft_email_id, scheduled_at, status, created_at, updated_at)
        VALUES (?, ?, DATE_SUB(NOW(), INTERVAL 1 MINUTE), 'scheduled', NOW(), NOW())
    ")->execute([(int)$sender['id'], $dueDraftId]);

    $processResult = $scheduledService->processDue(10);
    assertCouriva(($processResult['sent'] ?? 0) >= 1, 'Due schedule is delivered', json_encode($processResult));

    $sentCheck = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE sender_id = ? AND subject = ? AND folder = 'sent'");
    $sentCheck->execute([(int)$sender['id'], 'Due scheduled ' . $suffix]);
    assertCouriva((int)$sentCheck->fetchColumn() === 1, 'Delivered schedule creates one Sent copy');

    $recipientCopy = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND subject = ? AND folder = 'inbox'");
    $recipientCopy->execute([(int)$recipient['id'], 'Due scheduled ' . $suffix]);
    assertCouriva((int)$recipientCopy->fetchColumn() === 1, 'Delivered schedule reaches the recipient Inbox');

    $draftGone = $emailModel->findById($dueDraftId);
    assertCouriva($draftGone === null, 'Delivered schedule removes the draft');

    $sentCopyId = (int)$pdo->query("SELECT id FROM emails WHERE sender_id = " . (int)$sender['id'] . " AND subject = " . $pdo->quote('Due scheduled ' . $suffix) . " AND folder = 'sent' LIMIT 1")->fetchColumn();
    $sentSchedule = $pdo->prepare("SELECT status FROM scheduled_emails WHERE sent_email_id = ? ORDER BY id DESC LIMIT 1");
    $sentSchedule->execute([$sentCopyId]);
    assertCouriva($sentSchedule->fetchColumn() === 'sent', 'Delivered schedule is marked sent');

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------
    echo "\n--- Notifications ---\n";
    $beforeUnread = unread_notification_count((int)$recipient['id']);
    create_notification((int)$recipient['id'], 'system', 'Feature test notice', 'Notification body', 'public/inbox.php');
    assertCouriva(unread_notification_count((int)$recipient['id']) === $beforeUnread + 1, 'Notification increments the unread count');
    $pdo->prepare("UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND title = 'Feature test notice'")->execute([(int)$recipient['id']]);
    assertCouriva(unread_notification_count((int)$recipient['id']) === $beforeUnread, 'Reading a notification clears it from the unread count');

    assertCouriva(str_ends_with(safe_notification_link('public/read.php?id=1'), 'public/read.php?id=1'), 'Internal notification links are preserved');
    assertCouriva(str_ends_with(safe_notification_link('https://example.test/phish'), 'public/notifications.php'), 'External notification links are rejected');
    assertCouriva(str_ends_with(safe_notification_link('javascript:alert(1)'), 'public/notifications.php'), 'Script notification links are rejected');

    // ------------------------------------------------------------------
    // Sender blocking
    // ------------------------------------------------------------------
    echo "\n--- Sender blocking ---\n";
    assertCouriva($spamService->blockSender((int)$recipient['id'], $sender['email']) === true, 'Recipient can block a sender');
    assertCouriva($spamService->blockSender((int)$recipient['id'], $sender['email']) === false, 'Duplicate block is ignored');

    $blockedSubject = 'Blocked sender ' . $suffix;
    $blockedSend = $mailService->sendEmail(
        (int)$sender['id'],
        ['to' => [$recipient['email']]],
        $blockedSubject,
        'Blocked body.'
    );
    assertCouriva(($blockedSend['success'] ?? false) === true, 'Blocked sender can still submit the message');
    $blockedInbox = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND subject = ? AND folder = 'inbox'");
    $blockedInbox->execute([(int)$recipient['id'], $blockedSubject]);
    assertCouriva((int)$blockedInbox->fetchColumn() === 0, 'Blocked sender does not reach the Inbox');
    $blockedSpam = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND subject = ? AND folder = 'spam'");
    $blockedSpam->execute([(int)$recipient['id'], $blockedSubject]);
    assertCouriva((int)$blockedSpam->fetchColumn() === 1, 'Blocked sender is routed to Spam');

    assertCouriva($spamService->unblockSenderByEmail((int)$recipient['id'], $sender['email']) === true, 'Recipient can unblock a sender');
    $unblockedSubject = 'Unblocked sender ' . $suffix;
    $mailService->sendEmail((int)$sender['id'], ['to' => [$recipient['email']]], $unblockedSubject, 'Unblocked body.');
    $unblockedInbox = $pdo->prepare("SELECT COUNT(*) FROM emails WHERE recipient_id = ? AND subject = ? AND folder = 'inbox'");
    $unblockedInbox->execute([(int)$recipient['id'], $unblockedSubject]);
    assertCouriva((int)$unblockedInbox->fetchColumn() === 1, 'Unblocked sender returns to the Inbox');

    // ------------------------------------------------------------------
    // Spam reports and administrator review
    // ------------------------------------------------------------------
    echo "\n--- Spam reports ---\n";
    $reportedEmail = $makeEmail([
        'sender_id'            => (int)$sender['id'],
        'recipient_id'         => (int)$recipient['id'],
        'sender_email_snapshot' => $sender['email'],
        'subject'              => 'Reported spam ' . $suffix,
        'body'                 => 'Buy something suspicious.',
        'folder'               => 'inbox',
    ]);

    $reportId = $spamService->report((int)$recipient['id'], $reportedEmail, 'Unsolicited advertising');
    assertCouriva($reportId > 0, 'User can report a message as spam');
    $reportedRow = $emailModel->findById($reportedEmail);
    assertCouriva($reportedRow['folder'] === 'spam', 'Reported message moves to Spam');
    assertCouriva($spamService->getReport($reportId)['status'] === 'open', 'Report enters the open review queue');

    assertCouriva($spamService->review((int)$adminRecord['id'], $reportId, 'resolved', 'Confirmed spam.') === true, 'Administrator can resolve a report');
    $resolved = $spamService->getReport($reportId);
    assertCouriva($resolved['status'] === 'resolved', 'Resolved report stores its decision');
    $blockedAfterReview = $pdo->prepare('SELECT COUNT(*) FROM blocked_senders WHERE user_id = ? AND sender_email = ?');
    $blockedAfterReview->execute([(int)$recipient['id'], $sender['email']]);
    assertCouriva((int)$blockedAfterReview->fetchColumn() === 1, 'Resolving a report blocks the sender for the reporter');
    assertCouriva($spamService->review((int)$adminRecord['id'], $reportId, 'resolved', 'Again') === false, 'A reviewed report cannot be reviewed twice');

    $dismissCandidate = $makeEmail([
        'sender_id'            => (int)$sender['id'],
        'recipient_id'         => (int)$recipient['id'],
        'sender_email_snapshot' => $sender['email'],
        'subject'              => 'Dismissed spam ' . $suffix,
        'body'                 => 'Not actually spam.',
        'folder'               => 'inbox',
    ]);
    $dismissReportId = $spamService->report((int)$recipient['id'], $dismissCandidate, 'Looks odd');
    assertCouriva($spamService->review((int)$adminRecord['id'], $dismissReportId, 'dismissed', 'Legitimate message.') === true, 'Administrator can dismiss a report');
    assertCouriva($emailModel->findById($dismissCandidate)['folder'] === 'inbox', 'Dismissed report restores the message to Inbox');

    $foreignReportRejected = false;
    try {
        $spamService->report((int)$observer['id'], $dismissCandidate, 'Not mine');
    } catch (InvalidArgumentException $e) {
        $foreignReportRejected = true;
    }
    assertCouriva($foreignReportRejected, 'A user cannot report a message they do not own');

    // ------------------------------------------------------------------
    // User-facing routes
    // ------------------------------------------------------------------
    echo "\n--- Routes ---\n";
    $projectRoot = dirname(__DIR__);
    foreach (['public/notifications.php', 'public/scheduled.php', 'public/register.php', 'admin/registrations.php', 'admin/spam-reports.php', 'bin/process_scheduled.php'] as $file) {
        assertCouriva(is_file($projectRoot . '/' . $file), 'Route or worker exists: ' . $file);
    }

    $headerSource = (string)file_get_contents($projectRoot . '/includes/header.php');
    assertCouriva(str_contains($headerSource, 'public/notifications.php'), 'Header exposes the notification center');
    assertCouriva(str_contains($headerSource, 'folder=snoozed') && str_contains($headerSource, 'folder=archive'), 'Sidebar exposes Snoozed and Archive');
    assertCouriva(str_contains($headerSource, 'folder=label') && str_contains($headerSource, 'folder=custom'), 'Sidebar exposes labels and custom folders');
} catch (Throwable $e) {
    assertCouriva(false, 'Suite completed without an uncaught exception', $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
} finally {
    $userIds = array_values(array_unique(array_map('intval', $createdUserIds)));

    if (!empty($userIds)) {
        $in = implode(',', array_fill(0, count($userIds), '?'));

        $scheduleDelete = $pdo->prepare("DELETE FROM scheduled_emails WHERE user_id IN ({$in})");
        $scheduleDelete->execute($userIds);

        $reportDelete = $pdo->prepare("
            DELETE FROM spam_reports
            WHERE reporter_id IN ({$in})
               OR email_id IN (SELECT id FROM emails WHERE sender_id IN ({$in}) OR recipient_id IN ({$in}))
        ");
        $reportDelete->execute(array_merge($userIds, $userIds, $userIds));

        $notificationDelete = $pdo->prepare("DELETE FROM notifications WHERE user_id IN ({$in})");
        $notificationDelete->execute($userIds);

        $blockDelete = $pdo->prepare("DELETE FROM blocked_senders WHERE user_id IN ({$in})");
        $blockDelete->execute($userIds);

        $labelDelete = $pdo->prepare("DELETE FROM email_labels WHERE email_id IN (SELECT id FROM emails WHERE sender_id IN ({$in}) OR recipient_id IN ({$in}))");
        $labelDelete->execute(array_merge($userIds, $userIds));

        $attachmentDelete = $pdo->prepare("DELETE FROM attachments WHERE email_id IN (SELECT id FROM emails WHERE sender_id IN ({$in}) OR recipient_id IN ({$in}))");
        $attachmentDelete->execute(array_merge($userIds, $userIds));

        $recipientDelete = $pdo->prepare("DELETE FROM email_recipients WHERE email_id IN (SELECT id FROM emails WHERE sender_id IN ({$in}) OR recipient_id IN ({$in}))");
        $recipientDelete->execute(array_merge($userIds, $userIds));

        $emailDelete = $pdo->prepare("DELETE FROM emails WHERE sender_id IN ({$in}) OR recipient_id IN ({$in})");
        $emailDelete->execute(array_merge($userIds, $userIds));

        $folderDelete = $pdo->prepare("DELETE FROM folders WHERE user_id IN ({$in})");
        $folderDelete->execute($userIds);

        $labelOwnerDelete = $pdo->prepare("DELETE FROM labels WHERE user_id IN ({$in})");
        $labelOwnerDelete->execute($userIds);

        $contactDelete = $pdo->prepare("DELETE FROM contacts WHERE user_id IN ({$in})");
        $contactDelete->execute($userIds);

        $logDelete = $pdo->prepare("DELETE FROM system_logs WHERE user_id IN ({$in})");
        $logDelete->execute($userIds);

        $userDelete = $pdo->prepare("DELETE FROM users WHERE id IN ({$in})");
        $userDelete->execute($userIds);
    }
}

echo "\n=== Couriva Summary: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
