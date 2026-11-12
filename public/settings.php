<?php
/**
 * Couriva - User Account Settings
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';
require_once dirname(__DIR__) . '/app/Services/OrganizationService.php';
require_once dirname(__DIR__) . '/app/Services/SpamService.php';

require_auth();

$userId = (int)$_SESSION['user_id'];
$userModel = new \App\Models\User($pdo);
$user = $userModel->findById($userId);
$organizationService = new \App\Services\OrganizationService($pdo);
$spamService = new \App\Services\SpamService($pdo);

$activeFolder = 'settings';
$pageTitle = 'Account Settings';
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Security token expired. Please try again.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'profile') {
            $firstName = trim($_POST['first_name'] ?? '');
            $lastName = trim($_POST['last_name'] ?? '');
            $timezone = trim($_POST['timezone'] ?? 'UTC');
            $signature = trim($_POST['signature'] ?? '');
            $theme = in_array($_POST['theme'] ?? 'light', ['light', 'dark', 'system'], true) ? $_POST['theme'] : 'light';

            if (empty($firstName) || empty($lastName)) {
                $error = 'First name and last name cannot be empty.';
            } else {
                $userModel->update($userId, [
                    'first_name' => $firstName,
                    'last_name'  => $lastName,
                    'timezone'   => $timezone,
                    'signature'  => $signature,
                    'theme'      => $theme,
                ]);

                $_SESSION['user_first_name'] = $firstName;
                $_SESSION['user_last_name'] = $lastName;
                $_SESSION['user_full_name'] = $firstName . ' ' . $lastName;
                $_SESSION['user_timezone'] = $timezone;
                $_SESSION['user_theme'] = $theme;

                log_activity($userId, 'SETTINGS_UPDATED', 'SUCCESS', 'Profile details and theme preference updated.');
                set_flash('success', 'Profile and theme settings updated successfully.');
                header('Location: ' . app_base_url('public/settings.php'));
                exit;
            }
        } elseif ($action === 'theme') {
            $theme = in_array($_POST['theme'] ?? 'light', ['light', 'dark', 'system'], true) ? $_POST['theme'] : 'light';
            $userModel->update($userId, ['theme' => $theme]);
            $_SESSION['user_theme'] = $theme;
            log_activity($userId, 'THEME_UPDATED', 'SUCCESS', "Theme changed to: {$theme}");
            set_flash('success', "Theme preference updated to " . ucfirst($theme) . ".");
            header('Location: ' . app_base_url('public/settings.php'));
            exit;
        } elseif ($action === 'password') {
            $currentPass = $_POST['current_password'] ?? '';
            $newPass = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            if (!password_verify($currentPass, $user['password'])) {
                $error = 'Current password is incorrect.';
            } elseif (strlen($newPass) < 8) {
                $error = 'New password must be at least 8 characters long.';
            } elseif ($newPass !== $confirmPass) {
                $error = 'New password and confirmation do not match.';
            } else {
                $userModel->updatePassword($userId, $newPass);
                $freshUser = $userModel->findById($userId);
                $_SESSION['session_epoch'] = (int)($freshUser['session_epoch'] ?? ($_SESSION['session_epoch'] + 1));
                $_SESSION['must_change_password'] = 0;
                $_SESSION['last_activity'] = time();
                log_activity($userId, 'PASSWORD_CHANGED', 'SUCCESS', 'User updated password.');
                set_flash('success', 'Password successfully changed.');
                header('Location: ' . app_base_url('public/settings.php'));
                exit;
            }
        } elseif (in_array($action, ['create_label', 'update_label', 'delete_label', 'create_folder', 'update_folder', 'delete_folder', 'unblock_sender'], true)) {
            try {
                if ($action === 'create_label') {
                    $organizationService->createLabel($userId, trim($_POST['label_name'] ?? ''), $_POST['label_color'] ?? '#2563eb');
                    set_flash('success', 'Label created.');
                } elseif ($action === 'update_label') {
                    $labelId = (int)($_POST['label_id'] ?? 0);
                    $name = trim($_POST['label_name'] ?? '');
                    $color = $_POST['label_color'] ?? '#2563eb';
                    $updated = $organizationService->updateLabel($userId, $labelId, $name, $color);
                    set_flash($updated ? 'success' : 'warning', $updated ? 'Label updated successfully.' : 'Label not found.');
                } elseif ($action === 'delete_label') {
                    $deleted = $organizationService->deleteLabel($userId, (int)($_POST['label_id'] ?? 0));
                    set_flash($deleted ? 'success' : 'warning', $deleted ? 'Label deleted.' : 'Label not found.');
                } elseif ($action === 'create_folder') {
                    $organizationService->createFolder($userId, trim($_POST['folder_name'] ?? ''), $_POST['folder_color'] ?? '#64748b');
                    set_flash('success', 'Folder created.');
                } elseif ($action === 'update_folder') {
                    $folderId = (int)($_POST['folder_id'] ?? 0);
                    $name = trim($_POST['folder_name'] ?? '');
                    $color = $_POST['folder_color'] ?? '#64748b';
                    $updated = $organizationService->updateFolder($userId, $folderId, $name, $color);
                    set_flash($updated ? 'success' : 'warning', $updated ? 'Folder updated successfully.' : 'Folder not found.');
                } elseif ($action === 'delete_folder') {
                    $deleted = $organizationService->deleteFolder($userId, (int)($_POST['folder_id'] ?? 0));
                    set_flash($deleted ? 'success' : 'warning', $deleted ? 'Folder deleted. Its messages returned to their system folders.' : 'Folder not found.');
                } elseif ($action === 'unblock_sender') {
                    $blockId = (int)($_POST['block_id'] ?? 0);
                    $unblocked = $spamService->unblockSender($userId, $blockId);
                    set_flash($unblocked ? 'success' : 'warning', $unblocked ? 'Sender unblocked.' : 'Blocked sender not found.');
                }
            } catch (InvalidArgumentException $e) {
                set_flash('danger', $e->getMessage());
            } catch (Throwable $e) {
                error_log('Settings organization action failed: ' . $e->getMessage());
                set_flash('danger', 'That change could not be saved.');
            }

            header('Location: ' . app_base_url('public/settings.php#organization'));
            exit;
        }
    }
}

$labels = $organizationService->getLabels($userId);
$customFolders = $organizationService->getFolders($userId);
$blockedSenders = $spamService->getBlockedSenders($userId);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="settings-container settings-wrapper">
    <!-- Page Header (Left-aligned with settings cards) -->
    <div class="mb-4">
        <h4 class="fw-bold mb-1"><i class="bi bi-gear-fill text-primary me-2"></i>Account Settings</h4>
        <p class="text-muted small">Manage your user profile, email signature, and security credentials</p>
    </div>

    <!-- Settings Section Tabs -->
    <ul class="nav nav-pills settings-section-tabs gap-2 mb-4" id="settingsSectionTabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-profile-btn" data-bs-toggle="pill" data-bs-target="#tab-profile" type="button" role="tab" aria-controls="tab-profile" aria-selected="true">
                <i class="bi bi-person-circle me-1"></i> Profile
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-security-btn" data-bs-toggle="pill" data-bs-target="#tab-security" type="button" role="tab" aria-controls="tab-security" aria-selected="false">
                <i class="bi bi-shield-lock me-1"></i> Security
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-organization-btn" data-bs-toggle="pill" data-bs-target="#tab-organization" type="button" role="tab" aria-controls="tab-organization" aria-selected="false">
                <i class="bi bi-folder2 me-1"></i> Organization
            </button>
        </li>
    </ul>

    <div class="tab-content" id="settingsSectionTabsContent">
        <!-- Profile Tab -->
        <div class="tab-pane fade show active" id="tab-profile" role="tabpanel" aria-labelledby="tab-profile-btn">
            <div class="row g-4">
                <!-- User Profile & Avatar Summary -->
                <div class="col-12">
                    <div class="card shadow-sm border-0 bg-body-tertiary">
                        <div class="card-body p-2 d-flex flex-wrap align-items-center justify-content-between gap-3">
                            <div class="d-flex align-items-center gap-3">
                                <div class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold shadow-sm" style="width: 40px; height: 40px; font-size: 1.1rem;">
                                    <?= strtoupper(substr($user['first_name'] ?: $user['email'], 0, 1)) ?>
                                </div>
                                <div>
                                    <h6 class="fw-bold mb-0 text-body fs-6"><?= e($user['first_name'] . ' ' . $user['last_name']) ?></h6>
                                    <div class="text-muted small"><?= e($user['email']) ?></div>
                                </div>
                                <div class="d-flex gap-2 ms-2">
                                    <span class="badge bg-primary-subtle text-primary border border-primary-subtle text-uppercase"><?= e($user['role']) ?></span>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle text-capitalize"><?= e($user['status']) ?></span>
                                </div>
                            </div>

                            <div class="text-muted small d-flex gap-4">
                                <div><i class="bi bi-globe me-1"></i> Timezone: <strong><?= e($user['timezone']) ?></strong></div>
                                <div><i class="bi bi-clock-history me-1"></i> Member since: <strong><?= date('M d, Y', strtotime($user['created_at'])) ?></strong></div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Profile & Signature Settings -->
                <div class="col-12">
                    <div class="card shadow-sm border-0">
                        <div class="card-header bg-body py-3 border-bottom">
                            <h5 class="mb-0 fw-bold"><i class="bi bi-person-circle text-primary me-2"></i>Profile Information</h5>
                        </div>
                        <div class="card-body p-4">
                            <form method="POST" action="<?= app_base_url('public/settings.php') ?>">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="profile">

                                <?= render_inline_alert($error) ?>

                                <div class="row g-3 mb-3">
                                    <div class="col-md-6">
                                        <label for="profileFirstName" class="form-label fw-semibold">First Name *</label>
                                        <input id="profileFirstName" type="text" class="form-control" name="first_name" required value="<?= e($user['first_name']) ?>">
                                    </div>
                                    <div class="col-md-6">
                                        <label for="profileLastName" class="form-label fw-semibold">Last Name *</label>
                                        <input id="profileLastName" type="text" class="form-control" name="last_name" required value="<?= e($user['last_name']) ?>">
                                    </div>
                                </div>

                                <div class="mb-3">
                                    <label for="profileEmail" class="form-label fw-semibold">Email Address</label>
                                    <input id="profileEmail" type="email" class="form-control bg-body-tertiary" value="<?= e($user['email']) ?>" readonly>
                                    <span class="text-muted small" style="font-size: 0.8rem;">Email address changes require administrative assistance.</span>
                                </div>

                                <div class="mb-3">
                                    <label for="profileTimezone" class="form-label fw-semibold">Timezone</label>
                                    <select id="profileTimezone" class="form-select" name="timezone">
                                        <?php
                                        $timezones = ['UTC', 'America/New_York', 'America/Chicago', 'America/Denver', 'America/Los_Angeles', 'Europe/London', 'Europe/Paris', 'Asia/Tokyo', 'Asia/Singapore', 'Asia/Manila'];
                                        foreach ($timezones as $tz): ?>
                                            <option value="<?= $tz ?>" <?= $user['timezone'] === $tz ? 'selected' : '' ?>><?= $tz ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>

                                <!-- Email Signature with Live Preview -->
                                <div class="mb-3">
                                    <label for="signatureInput" class="form-label fw-semibold d-flex justify-content-between">
                                        <span>Email Signature</span>
                                        <span class="badge bg-secondary-subtle text-secondary fw-normal">Auto-appended on compose</span>
                                    </label>
                                    <textarea class="form-control mb-2" name="signature" id="signatureInput" rows="4" placeholder="Your default email closing signature..."><?= e($user['signature']) ?></textarea>

                                    <!-- Real-time signature preview (Collapsible) -->
                                    <div class="d-flex align-items-center justify-content-between mb-1">
                                        <button type="button" class="btn btn-link btn-sm p-0 text-decoration-none small text-muted" data-bs-toggle="collapse" data-bs-target="#signaturePreviewCollapse" aria-expanded="false" aria-controls="signaturePreviewCollapse">
                                            <i class="bi bi-eye me-1"></i> Toggle signature preview
                                        </button>
                                    </div>
                                    <div class="collapse" id="signaturePreviewCollapse">
                                        <div class="signature-preview-container p-3 border rounded bg-body-tertiary mb-2">
                                            <div id="signaturePreview" class="text-body" style="font-family: inherit; font-size: 0.95rem; line-height: 1.5;">
                                                <?= !empty($user['signature']) ? nl2br(e($user['signature'])) : '<span class="text-muted fst-italic">No signature defined yet.</span>' ?>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Theme Switcher -->
                                <div class="mb-4">
                                    <label class="form-label fw-semibold">Theme Preference</label>
                                    <div class="d-flex gap-4 p-3 rounded bg-body-tertiary border">
                                        <div class="form-check">
                                            <input class="form-check-input settings-theme-radio" type="radio" name="theme" id="themeLight" value="light" <?= ($user['theme'] ?? 'light') === 'light' ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-medium cursor-pointer" for="themeLight">
                                                <i class="bi bi-sun-fill text-warning me-1"></i> Light Theme
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input settings-theme-radio" type="radio" name="theme" id="themeDark" value="dark" <?= ($user['theme'] ?? 'light') === 'dark' ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-medium cursor-pointer" for="themeDark">
                                                <i class="bi bi-moon-stars-fill text-primary me-1"></i> Dark Theme
                                            </label>
                                        </div>
                                        <div class="form-check">
                                            <input class="form-check-input settings-theme-radio" type="radio" name="theme" id="themeSystem" value="system" <?= ($user['theme'] ?? 'light') === 'system' ? 'checked' : '' ?>>
                                            <label class="form-check-label fw-medium cursor-pointer" for="themeSystem">
                                                <i class="bi bi-display text-secondary me-1"></i> System (Auto)
                                            </label>
                                        </div>
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary fw-semibold px-4 shadow-sm">
                                    <i class="bi bi-check-circle me-1"></i> Save Profile & Settings
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Security Tab -->
        <div class="tab-pane fade" id="tab-security" role="tabpanel" aria-labelledby="tab-security-btn">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-body py-3 border-bottom">
                    <h5 class="mb-0 fw-bold"><i class="bi bi-shield-lock text-primary me-2"></i>Change Password</h5>
                </div>
                <div class="card-body p-4">
                    <form method="POST" action="<?= app_base_url('public/settings.php') ?>" id="passwordChangeForm">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="password">

                        <?= render_inline_alert($error) ?>

                        <div class="mb-3">
                            <label for="settingsCurrentPassword" class="form-label fw-semibold">Current Password *</label>
                            <div class="input-group"><input type="password" class="form-control" id="settingsCurrentPassword" name="current_password" required autocomplete="current-password" placeholder="Enter current password"><button type="button" class="password-toggle" aria-label="Show current password" aria-controls="settingsCurrentPassword" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                        </div>

                        <div class="mb-3">
                            <label for="newPasswordInput" class="form-label fw-semibold">New Password *</label>
                            <div class="input-group"><input type="password" class="form-control" name="new_password" id="newPasswordInput" required autocomplete="new-password" placeholder="Min 8 characters"><button type="button" class="password-toggle" aria-label="Show new password" aria-controls="newPasswordInput" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>

                            <!-- Password Strength Meter -->
                            <div class="mt-2">
                                <div class="progress" style="height: 6px;">
                                    <div class="progress-bar" id="passwordStrengthBar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                                </div>
                                <div class="d-flex justify-content-between align-items-center mt-1">
                                    <span id="passwordStrengthLabel" class="small text-muted" style="font-size: 0.78rem;">Strength: Enter password</span>
                                </div>
                                <div class="d-flex flex-wrap gap-2 mt-2" id="passwordRulesPills" style="font-size: 0.8125rem;">
                                    <span class="badge bg-secondary-subtle text-secondary border rule-pill py-1 px-2" id="ruleLength">8+ chars</span>
                                    <span class="badge bg-secondary-subtle text-secondary border rule-pill py-1 px-2" id="ruleUpper">Uppercase</span>
                                    <span class="badge bg-secondary-subtle text-secondary border rule-pill py-1 px-2" id="ruleNumber">Number</span>
                                    <span class="badge bg-secondary-subtle text-secondary border rule-pill py-1 px-2" id="ruleSpecial">Symbol</span>
                                </div>
                            </div>
                        </div>

                        <div class="mb-4">
                            <label for="confirmPasswordInput" class="form-label fw-semibold">Confirm New Password *</label>
                            <div class="input-group"><input type="password" class="form-control" name="confirm_password" id="confirmPasswordInput" required autocomplete="new-password" placeholder="Confirm new password"><button type="button" class="password-toggle" aria-label="Show confirmation password" aria-controls="confirmPasswordInput" aria-pressed="false"><i class="bi bi-eye" aria-hidden="true"></i></button></div>
                            <div id="passwordMatchLabel" class="small mt-1" style="font-size: 0.8rem;"></div>
                        </div>

                        <button type="submit" class="btn btn-outline-primary fw-semibold w-100 shadow-sm" id="submitPasswordBtn">
                            <i class="bi bi-key me-1"></i> Update Password
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Organization Tab -->
        <div class="tab-pane fade" id="tab-organization" role="tabpanel" aria-labelledby="tab-organization-btn">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-body py-3 border-bottom">
                    <h5 class="mb-0 fw-bold text-body">Labels & folders</h5>
                    <div class="text-muted small" style="font-size: 0.8rem;">Organize how your messages are grouped.</div>
                </div>
                <div class="card-body p-4">
                    <!-- Organization Selector Tabs (Mockup Parity) -->
                    <ul class="nav nav-pills org-nav-pills gap-2 mb-4" id="orgTabs" role="tablist">
                        <li class="nav-item" role="presentation">
                            <button class="nav-link active" id="tab-labels-btn" data-bs-toggle="pill" data-bs-target="#tab-labels" type="button" role="tab" aria-controls="tab-labels" aria-selected="true">Labels</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-folders-btn" data-bs-toggle="pill" data-bs-target="#tab-folders" type="button" role="tab" aria-controls="tab-folders" aria-selected="false">Folders</button>
                        </li>
                        <li class="nav-item" role="presentation">
                            <button class="nav-link" id="tab-blocked-btn" data-bs-toggle="pill" data-bs-target="#tab-blocked" type="button" role="tab" aria-controls="tab-blocked" aria-selected="false">Blocked senders</button>
                        </li>
                    </ul>

                    <div class="tab-content" id="orgTabsContent">
                        <!-- Labels Tab -->
                        <div class="tab-pane fade show active" id="tab-labels" role="tabpanel" aria-labelledby="tab-labels-btn">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h6 class="fw-bold text-body mb-0">Your labels</h6>
                                <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold rounded-3" data-bs-toggle="modal" data-bs-target="#createLabelModal">
                                    New label
                                </button>
                            </div>
                            <?php if (empty($labels)): ?>
                                <div class="p-4 text-center text-muted border rounded-3 bg-body-tertiary">
                                    No labels yet. Organize your emails with custom labels.
                                </div>
                            <?php else: ?>
                                <div class="org-list-group">
                                    <?php foreach ($labels as $label): ?>
                                        <div class="org-item-row">
                                            <div class="d-flex align-items-center text-truncate gap-2">
                                                <span class="label-dot" style="background-color: <?= e($label['color']) ?>;"></span>
                                                <span class="fw-medium text-body text-truncate"><?= e($label['name']) ?></span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge rounded-pill bg-body-secondary text-secondary border px-2 py-1" style="font-size: 0.75rem;"><?= (int)$label['email_count'] ?> messages</span>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-link text-muted p-1 border-0 org-action-dropdown-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Options" aria-label="Label options">
                                                        <i class="bi bi-three-dots"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border rounded-3">
                                                        <li>
                                                            <button type="button" class="dropdown-item small d-flex align-items-center gap-2 btn-open-edit-label" data-bs-toggle="modal" data-bs-target="#editLabelModal" data-id="<?= (int)$label['id'] ?>" data-name="<?= e($label['name']) ?>" data-color="<?= e($label['color']) ?>">
                                                                <i class="bi bi-pencil"></i> Rename & change color
                                                            </button>
                                                        </li>
                                                        <li><hr class="dropdown-divider my-1"></li>
                                                        <li>
                                                            <form method="POST" action="<?= app_base_url('public/settings.php') ?>" data-confirm="Are you sure you want to delete the label "<?= e($label['name']) ?>"? Messages associated with this label will remain in their folders.">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_label">
                                                                <input type="hidden" name="label_id" value="<?= (int)$label['id'] ?>">
                                                                <button type="submit" class="dropdown-item small text-danger d-flex align-items-center gap-2">
                                                                    <i class="bi bi-trash3"></i> Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Folders Tab -->
                        <div class="tab-pane fade" id="tab-folders" role="tabpanel" aria-labelledby="tab-folders-btn">
                            <div class="d-flex align-items-center justify-content-between mb-3">
                                <h6 class="fw-bold text-body mb-0">Your folders</h6>
                                <button type="button" class="btn btn-primary btn-sm px-3 fw-semibold rounded-3" data-bs-toggle="modal" data-bs-target="#createFolderModal">
                                    New folder
                                </button>
                            </div>
                            <?php if (empty($customFolders)): ?>
                                <div class="p-4 text-center text-muted border rounded-3 bg-body-tertiary">
                                    No custom folders yet. Group related emails into custom folders.
                                </div>
                            <?php else: ?>
                                <div class="org-list-group">
                                    <?php foreach ($customFolders as $folder): ?>
                                        <div class="org-item-row">
                                            <div class="d-flex align-items-center text-truncate gap-2">
                                                <i class="bi bi-folder2 fs-5" style="color: <?= e($folder['color']) ?>;"></i>
                                                <span class="fw-medium text-body text-truncate"><?= e($folder['name']) ?></span>
                                            </div>
                                            <div class="d-flex align-items-center gap-2">
                                                <span class="badge rounded-pill bg-body-secondary text-secondary border px-2 py-1" style="font-size: 0.75rem;"><?= (int)$folder['email_count'] ?> messages</span>
                                                <div class="dropdown">
                                                    <button class="btn btn-sm btn-link text-muted p-1 border-0 org-action-dropdown-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false" title="Options" aria-label="Folder options">
                                                        <i class="bi bi-three-dots"></i>
                                                    </button>
                                                    <ul class="dropdown-menu dropdown-menu-end shadow-sm border rounded-3">
                                                        <li>
                                                            <button type="button" class="dropdown-item small d-flex align-items-center gap-2 btn-open-edit-folder" data-bs-toggle="modal" data-bs-target="#editFolderModal" data-id="<?= (int)$folder['id'] ?>" data-name="<?= e($folder['name']) ?>" data-color="<?= e($folder['color']) ?>">
                                                                <i class="bi bi-pencil"></i> Rename & change color
                                                            </button>
                                                        </li>
                                                        <li><hr class="dropdown-divider my-1"></li>
                                                        <li>
                                                            <form method="POST" action="<?= app_base_url('public/settings.php') ?>" data-confirm="Are you sure you want to delete the folder "<?= e($folder['name']) ?>"? Messages will return to their system folders.">
                                                                <?= csrf_field() ?>
                                                                <input type="hidden" name="action" value="delete_folder">
                                                                <input type="hidden" name="folder_id" value="<?= (int)$folder['id'] ?>">
                                                                <button type="submit" class="dropdown-item small text-danger d-flex align-items-center gap-2">
                                                                    <i class="bi bi-trash3"></i> Delete
                                                                </button>
                                                            </form>
                                                        </li>
                                                    </ul>
                                                </div>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- Blocked Senders Tab -->
                        <div class="tab-pane fade" id="tab-blocked" role="tabpanel" aria-labelledby="tab-blocked-btn">
                            <div class="mb-3">
                                <h6 class="fw-bold text-body mb-0">Blocked senders</h6>
                                <div class="text-muted small">Senders blocked from reaching your inbox.</div>
                            </div>
                            <?php if (empty($blockedSenders)): ?>
                                <div class="p-4 text-center text-muted border rounded-3 bg-body-tertiary">
                                    No blocked senders. Block an address from a message's Organize panel.
                                </div>
                            <?php else: ?>
                                <div class="org-list-group">
                                    <?php foreach ($blockedSenders as $blocked): ?>
                                        <div class="org-item-row">
                                            <span class="text-truncate small font-monospace text-body"><?= e($blocked['sender_email']) ?></span>
                                            <form method="POST" action="<?= app_base_url('public/settings.php') ?>" class="d-inline">
                                                <?= csrf_field() ?>
                                                <input type="hidden" name="action" value="unblock_sender">
                                                <input type="hidden" name="block_id" value="<?= (int)$blocked['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-secondary py-1 px-3">Unblock</button>
                                            </form>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="text-muted small mt-4 pt-3 border-top" style="font-size: 0.8rem;">
                        Use the row's overflow menu for Rename, Change color, and Delete instead of displaying permanent red trash buttons.
                    </div>
                </div>
            </div>
        </div>
    </div>
</div> <!-- /.settings-container -->

<!-- Edit Label Modal -->
<div class="modal fade" id="editLabelModal" tabindex="-1" aria-labelledby="editLabelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content shadow-lg border rounded-4 bg-body">
            <div class="modal-header border-bottom px-4 pt-3 pb-2">
                <div>
                    <h5 class="modal-title fw-bold text-body" id="editLabelModalLabel">Edit label</h5>
                    <p class="text-muted small mb-0">Update label name and color.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= app_base_url('public/settings.php') ?>" id="editLabelForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_label">
                <input type="hidden" name="label_id" id="editLabelIdInput" value="0">
                <div class="modal-body px-4 py-3">
                    <?= render_inline_alert($error) ?>
                    <div class="mb-3">
                        <label for="editLabelNameInput" class="form-label small text-muted fw-semibold">Label name</label>
                        <input type="text" class="form-control" id="editLabelNameInput" name="label_name" required maxlength="64" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label small text-muted fw-semibold d-block mb-2">Label color</label>
                        <div class="d-flex align-items-center gap-2 color-swatches-group" id="editLabelSwatches">
                            <label class="color-swatch-item">
                                <input type="radio" name="label_color" value="#6366f1">
                                <span class="swatch-circle" style="background-color: #6366f1;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="label_color" value="#2563eb">
                                <span class="swatch-circle" style="background-color: #2563eb;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="label_color" value="#0d9488">
                                <span class="swatch-circle" style="background-color: #0d9488;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="label_color" value="#d97706">
                                <span class="swatch-circle" style="background-color: #d97706;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="label_color" value="#db2777">
                                <span class="swatch-circle" style="background-color: #db2777;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="label_color" value="#64748b">
                                <span class="swatch-circle" style="background-color: #64748b;"><i class="bi bi-check2"></i></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Edit Folder Modal -->
<div class="modal fade" id="editFolderModal" tabindex="-1" aria-labelledby="editFolderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
        <div class="modal-content shadow-lg border rounded-4 bg-body">
            <div class="modal-header border-bottom px-4 pt-3 pb-2">
                <div>
                    <h5 class="modal-title fw-bold text-body" id="editFolderModalLabel">Edit folder</h5>
                    <p class="text-muted small mb-0">Update folder name and color.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form method="POST" action="<?= app_base_url('public/settings.php') ?>" id="editFolderForm">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="update_folder">
                <input type="hidden" name="folder_id" id="editFolderIdInput" value="0">
                <div class="modal-body px-4 py-3">
                    <?= render_inline_alert($error) ?>
                    <div class="mb-3">
                        <label for="editFolderNameInput" class="form-label small text-muted fw-semibold">Folder name</label>
                        <input type="text" class="form-control" id="editFolderNameInput" name="folder_name" required maxlength="80" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label small text-muted fw-semibold d-block mb-2">Folder color</label>
                        <div class="d-flex align-items-center gap-2 color-swatches-group" id="editFolderSwatches">
                            <label class="color-swatch-item">
                                <input type="radio" name="folder_color" value="#64748b">
                                <span class="swatch-circle" style="background-color: #64748b;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="folder_color" value="#2563eb">
                                <span class="swatch-circle" style="background-color: #2563eb;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="folder_color" value="#0d9488">
                                <span class="swatch-circle" style="background-color: #0d9488;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="folder_color" value="#d97706">
                                <span class="swatch-circle" style="background-color: #d97706;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="folder_color" value="#db2777">
                                <span class="swatch-circle" style="background-color: #db2777;"><i class="bi bi-check2"></i></span>
                            </label>
                            <label class="color-swatch-item">
                                <input type="radio" name="folder_color" value="#6366f1">
                                <span class="swatch-circle" style="background-color: #6366f1;"><i class="bi bi-check2"></i></span>
                            </label>
                        </div>
                    </div>
                </div>
                <div class="modal-footer border-top px-4 py-2 d-flex justify-content-end gap-2">
                    <button type="button" class="btn btn-outline-secondary btn-sm px-3" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold">Save changes</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
