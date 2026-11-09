<?php
/**
 * Couriva - Comprehensive Backend Smoke Test Suite
 * Validates MariaDB operations, MailService, AuthService, APIs, and Security Ownership
 */

define('WEBMAIL_INIT', true);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../app/Models/User.php';
require_once __DIR__ . '/../app/Models/Email.php';
require_once __DIR__ . '/../app/Models/Attachment.php';
require_once __DIR__ . '/../app/Models/Contact.php';
require_once __DIR__ . '/../app/Services/AttachmentService.php';
require_once __DIR__ . '/../app/Services/SmtpTransport.php';
require_once __DIR__ . '/../app/Services/MailService.php';
require_once __DIR__ . '/../app/Services/AuthService.php';

$pdo = getDbConnection();
$passed = 0;
$failed = 0;

function assertTest(bool $condition, string $testName, string $details = '')
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "[\033[32mPASS\033[0m] {$testName}\n";
    } else {
        $failed++;
        echo "[\033[31mFAIL\033[0m] {$testName}" . ($details ? ": {$details}" : "") . "\n";
    }
}

echo "=== Starting Couriva Backend Smoke Test Suite ===\n\n";

// 1. Setup Test Users
$userModel = new \App\Models\User($pdo);
$testUserAEmail = 'test.alice.' . time() . '@couriva.local';
$testUserBEmail = 'test.bob.' . time() . '@couriva.local';

$userIdA = $userModel->create([
    'email'      => $testUserAEmail,
    'password'   => 'SecretPassword123!',
    'first_name' => 'Alice',
    'last_name'  => 'Tester',
    'role'       => 'user',
    'status'     => 'active',
    'timezone'   => 'America/New_York',
    'theme'      => 'light',
]);

$userIdB = $userModel->create([
    'email'      => $testUserBEmail,
    'password'   => 'SecretPassword123!',
    'first_name' => 'Bob',
    'last_name'  => 'Tester',
    'role'       => 'user',
    'status'     => 'active',
    'timezone'   => 'UTC',
    'theme'      => 'light',
]);

assertTest($userIdA > 0 && $userIdB > 0, "Create Test Users in MariaDB (Alice: #{$userIdA}, Bob: #{$userIdB})");

$mailService = new \App\Services\MailService($pdo);
$emailModel = new \App\Models\Email($pdo);

// 2. Test MailService::saveOrUpdateDraft
$draftData1 = [
    'subject' => 'Project Alpha Proposal',
    'body'    => 'Initial draft notes for project alpha.',
    'to'      => $testUserBEmail,
    'cc'      => 'manager@couriva.local',
    'bcc'     => 'archive@couriva.local',
];

$draftId = $mailService->saveOrUpdateDraft($userIdA, null, $draftData1);
assertTest($draftId > 0, "saveOrUpdateDraft creates new draft (#{$draftId})");

$draftRecord = $emailModel->findById($draftId);
assertTest(
    $draftRecord && $draftRecord['subject'] === 'Project Alpha Proposal' && $draftRecord['folder'] === 'draft' && $draftRecord['is_draft'] == 1,
    "Verify newly created draft record fields in DB"
);

$draftRecipients = $emailModel->getRecipients($draftId);
assertTest(count($draftRecipients) === 3, "Draft recipients (To, Cc, Bcc) saved correctly (Count: " . count($draftRecipients) . ")");

// Update draft with saveOrUpdateDraft
$draftData2 = [
    'subject' => 'Project Alpha Proposal (Updated v2)',
    'body'    => 'Revised draft body content.',
    'to'      => [$testUserBEmail],
    'cc'      => [],
    'bcc'     => [],
];

$updatedDraftId = $mailService->saveOrUpdateDraft($userIdA, $draftId, $draftData2);
assertTest($updatedDraftId === $draftId, "saveOrUpdateDraft updates existing draft without creating duplicate");

$updatedRecord = $emailModel->findById($draftId);
assertTest(
    $updatedRecord['subject'] === 'Project Alpha Proposal (Updated v2)' && $updatedRecord['body'] === 'Revised draft body content.',
    "Verify updated draft content"
);

$updatedRecipients = $emailModel->getRecipients($draftId);
assertTest(count($updatedRecipients) === 1, "Recipients refreshed on draft update (Count: " . count($updatedRecipients) . ")");

// 3. Test sendEmail and delivery
$sendRes = $mailService->sendEmail(
    $userIdA,
    ['to' => [$testUserBEmail], 'cc' => [], 'bcc' => []],
    'Important Notice',
    'Hello Bob, this is a test delivery from Alice.'
);
assertTest(!empty($sendRes['success']) && !empty($sendRes['sent_email_id']), "sendEmail successfully delivers mail");

$sentEmailId = $sendRes['sent_email_id'];

// Check Bob's inbox
$bobInbox = $mailService->searchEmails($userIdB, 'inbox');
assertTest($bobInbox['total'] >= 1, "Bob receives Alice's email in Inbox (Total: {$bobInbox['total']})");

$receivedEmailId = $bobInbox['emails'][0]['id'];

// 4. Test Batch Operations
// 4.1 batchMarkRead
$readCount = $mailService->batchMarkRead($userIdB, [$receivedEmailId], true);
assertTest($readCount === 1, "batchMarkRead marks 1 email as read");

$readCheck = $emailModel->findById($receivedEmailId);
assertTest((int)$readCheck['is_read'] === 1, "Verify is_read = 1 in database");

$unreadCount = $mailService->batchMarkRead($userIdB, [$receivedEmailId], false);
assertTest($unreadCount === 1, "batchMarkRead marks 1 email as unread");

$unreadCheck = $emailModel->findById($receivedEmailId);
assertTest((int)$unreadCheck['is_read'] === 0, "Verify is_read = 0 in database");

// 4.2 batchToggleStar
$starCount = $mailService->batchToggleStar($userIdB, [$receivedEmailId], true);
assertTest($starCount === 1, "batchToggleStar stars 1 email");

$starCheck = $emailModel->findById($receivedEmailId);
assertTest((int)$starCheck['is_starred'] === 1, "Verify is_starred = 1 in database");

$unstarCount = $mailService->batchToggleStar($userIdB, [$receivedEmailId], false);
assertTest($unstarCount === 1, "batchToggleStar unstars 1 email");

// 4.3 Strict Ownership Protection Check
// Alice attempts to alter Bob's received email
$illegalReadCount = $mailService->batchMarkRead($userIdA, [$receivedEmailId], true);
assertTest($illegalReadCount === 0, "Security: User A cannot mark User B's received email as read (Affected: {$illegalReadCount})");

$illegalTrashCount = $mailService->batchMoveFolder($userIdA, [$receivedEmailId], 'trash');
assertTest($illegalTrashCount === 0, "Security: User A cannot move User B's received email to trash (Affected: {$illegalTrashCount})");

$illegalDeleteCount = $mailService->batchDelete($userIdA, [$receivedEmailId], true);
assertTest($illegalDeleteCount === 0, "Security: User A cannot delete User B's received email (Affected: {$illegalDeleteCount})");

// 4.4 batchMoveFolder & batchDelete for owner
$moveTrashCount = $mailService->batchMoveFolder($userIdB, [$receivedEmailId], 'trash');
assertTest($moveTrashCount === 1, "batchMoveFolder moves email to trash");

$trashCheck = $emailModel->findById($receivedEmailId);
assertTest($trashCheck['folder'] === 'trash' && !empty($trashCheck['deleted_at']), "Verify folder = 'trash' and deleted_at set");

$restoreCount = $mailService->batchMoveFolder($userIdB, [$receivedEmailId], 'inbox');
assertTest($restoreCount === 1, "batchMoveFolder restores email to inbox");

$restoreCheck = $emailModel->findById($receivedEmailId);
assertTest($restoreCheck['folder'] === 'inbox' && empty($restoreCheck['deleted_at']), "Verify folder restored to inbox and deleted_at cleared");

// 5. Test searchEmails with advanced criteria
$searchQ = $mailService->searchEmails($userIdB, 'inbox', ['q' => 'Important Notice']);
assertTest($searchQ['total'] === 1, "searchEmails keyword 'q' matches exact message");

$searchNoMatch = $mailService->searchEmails($userIdB, 'inbox', ['q' => 'NonExistentXYZ999']);
assertTest($searchNoMatch['total'] === 0, "searchEmails returns 0 for non-matching query");

$searchDate = $mailService->searchEmails($userIdB, 'inbox', [
    'date_from' => date('Y-m-d', strtotime('-1 day')),
    'date_to'   => date('Y-m-d', strtotime('+1 day')),
]);
assertTest($searchDate['total'] >= 1, "searchEmails date range filter functions correctly");

$searchUnread = $mailService->searchEmails($userIdB, 'inbox', ['is_unread' => true]);
assertTest($searchUnread['total'] >= 1, "searchEmails is_unread filter functions correctly");

// 6. Test User Profile & Theme Settings update
$updateProfileSuccess = $userModel->update($userIdA, [
    'first_name' => 'AliceUpdated',
    'last_name'  => 'TesterUpdated',
    'signature'  => 'Best regards,\nAlice',
    'timezone'   => 'America/Chicago',
    'theme'      => 'dark',
]);
assertTest($updateProfileSuccess, "User model updates profile and theme to 'dark'");

$userCheck = $userModel->findById($userIdA);
assertTest(
    $userCheck['first_name'] === 'AliceUpdated' &&
    $userCheck['theme'] === 'dark' &&
    $userCheck['timezone'] === 'America/Chicago',
    "Verify updated profile fields and theme in database"
);

// Test password change
$updatePasswordSuccess = $userModel->updatePassword($userIdA, 'NewBrandSecretPass456!');
assertTest($updatePasswordSuccess, "updatePassword updates hashed password");

$userCheck2 = $userModel->findById($userIdA);
assertTest(password_verify('NewBrandSecretPass456!', $userCheck2['password']), "Verify new password against password_verify");

// 7. Test AuthService with LDAP disabled and Local Fallback
$authService = new \App\Services\AuthService($pdo, ['enabled' => false]);
$authRes = $authService->authenticate($testUserAEmail, 'NewBrandSecretPass456!');
assertTest(!empty($authRes['success']) && $authRes['auth_source'] === 'local', "AuthService authenticates local user successfully");

$authFail = $authService->authenticate($testUserAEmail, 'WrongPassword999!');
assertTest(empty($authFail['success']), "AuthService correctly rejects invalid password");

// 8. Test Contacts Model
$contactModel = new \App\Models\Contact($pdo);
$contactId = $contactModel->create([
    'user_id'       => $userIdA,
    'contact_name'  => 'Charlie Brown',
    'contact_email' => 'charlie@peanuts.org',
    'phone'         => '555-4321',
    'company'       => 'Peanuts Comic',
    'notes'         => 'Cartoonist contact',
]);
assertTest($contactId > 0, "Contact created in database (#{$contactId})");

$contactsList = $contactModel->getByUserId($userIdA, 'Charlie');
assertTest(count($contactsList) === 1 && $contactsList[0]['contact_name'] === 'Charlie Brown', "Search contact by user ID and query");

// 9. Test SmtpTransport initialization & structure
$smtpTransport = new \App\Services\SmtpTransport([
    'host'       => '127.0.0.1',
    'port'       => 587,
    'encryption' => 'tls',
    'auth'       => true,
    'username'   => 'testuser',
    'password'   => 'testpass',
]);
assertTest($smtpTransport instanceof \App\Services\SmtpTransport, "SmtpTransport service instantiated successfully");

// Clean up test data
$pdo->prepare("DELETE FROM emails WHERE sender_id IN (?, ?) OR recipient_id IN (?, ?)")->execute([$userIdA, $userIdB, $userIdA, $userIdB]);
$pdo->prepare("DELETE FROM contacts WHERE user_id IN (?, ?)")->execute([$userIdA, $userIdB]);
$pdo->prepare("DELETE FROM users WHERE id IN (?, ?)")->execute([$userIdA, $userIdB]);

echo "\n=== Test Results: {$passed} passed, {$failed} failed ===\n";
exit($failed === 0 ? 0 : 1);
