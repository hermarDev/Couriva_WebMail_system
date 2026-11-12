<?php
/**
 * Couriva - Compose Email Controller & View
 * Supports drafting, auto-save background sync, replies, and attachments
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/User.php';
require_once dirname(__DIR__) . '/app/Models/Email.php';
require_once dirname(__DIR__) . '/app/Models/Attachment.php';
require_once dirname(__DIR__) . '/app/Services/AttachmentService.php';
require_once dirname(__DIR__) . '/app/Services/SmtpTransport.php';
require_once dirname(__DIR__) . '/app/Services/MailService.php';
require_once dirname(__DIR__) . '/app/Services/ScheduledMailService.php';

require_auth();

$userId = (int)$_SESSION['user_id'];
$userModel = new \App\Models\User($pdo);
$userRecord = $userModel->findById($userId);
$emailModel = new \App\Models\Email($pdo);
$mailService = new \App\Services\MailService($pdo);
$attachmentService = new \App\Services\AttachmentService($pdo);
$scheduledService = new \App\Services\ScheduledMailService($pdo);

$to = $_GET['to'] ?? '';
$cc = $_GET['cc'] ?? '';
$bcc = $_GET['bcc'] ?? '';
$subject = $_GET['subject'] ?? '';
$body = '';
$parentEmailId = null;
$draftId = !empty($_GET['draft_id']) ? (int)$_GET['draft_id'] : null;

// Load existing draft if draft_id is provided
if ($draftId > 0) {
    $existingDraft = $emailModel->findById($draftId);
    if ($existingDraft && (int)$existingDraft['sender_id'] === $userId && ($existingDraft['folder'] === 'draft' || !empty($existingDraft['is_draft']))) {
        $subject = $existingDraft['subject'] !== '(Untitled Draft)' ? $existingDraft['subject'] : '';
        $body = $existingDraft['body'];
        $parentEmailId = $existingDraft['parent_email_id'];

        $recs = $emailModel->getRecipients($draftId);
        $toList = [];
        $ccList = [];
        $bccList = [];
        foreach ($recs as $r) {
            if ($r['recipient_type'] === 'to') $toList[] = $r['email_address'];
            elseif ($r['recipient_type'] === 'cc') $ccList[] = $r['email_address'];
            elseif ($r['recipient_type'] === 'bcc') $bccList[] = $r['email_address'];
        }
        $to = implode(', ', $toList);
        $cc = implode(', ', $ccList);
        $bcc = implode(', ', $bccList);
    } else {
        $draftId = null;
    }
}

// Handle Reply / Reply All / Forward presets
if (empty($draftId) && (!empty($_GET['reply_to']) || !empty($_GET['reply']) || !empty($_GET['reply_all']) || !empty($_GET['forward']))) {
    $refId = (int)($_GET['reply_to'] ?? $_GET['reply'] ?? $_GET['reply_all'] ?? $_GET['forward']);
    // Access control: only an email addressed to this user, or one of their
    // own sent/draft messages, may be quoted in a reply or forward.
    $refEmail = $emailModel->findVisibleById($refId, $userId);

    if ($refEmail) {
        $parentEmailId = $refEmail['id'];
        $refSenderName = trim(($refEmail['sender_first_name'] ?? '') . ' ' . ($refEmail['sender_last_name'] ?? ''));
        $refSenderLabel = $refSenderName !== '' ? $refSenderName : ($refEmail['sender_email'] ?? '');
        $bodyPrefix = "\n\n--- Original Message ---\nFrom: {$refSenderLabel}\nDate: {$refEmail['created_at']}\nSubject: {$refEmail['subject']}\n\n" . $refEmail['body'];

        if (!empty($_GET['reply_to']) || !empty($_GET['reply'])) {
            $to = $refEmail['sender_email'];
            $subject = (str_starts_with($refEmail['subject'], 'Re:') ? '' : 'Re: ') . $refEmail['subject'];
            $body = $bodyPrefix;
        } elseif (!empty($_GET['reply_all'])) {
            $to = $refEmail['sender_email'];
            $subject = (str_starts_with($refEmail['subject'], 'Re:') ? '' : 'Re: ') . $refEmail['subject'];
            $body = $bodyPrefix;
            // Gather CCs
            $recs = $emailModel->getRecipients($refId);
            $ccList = [];
            foreach ($recs as $r) {
                if ($r['email_address'] !== $_SESSION['user_email'] && $r['email_address'] !== $refEmail['sender_email']) {
                    $ccList[] = $r['email_address'];
                }
            }
            $cc = implode(', ', $ccList);
        } elseif (!empty($_GET['forward'])) {
            $subject = (str_starts_with($refEmail['subject'], 'Fwd:') ? '' : 'Fwd: ') . $refEmail['subject'];
            $body = "\n\n--- Forwarded Message ---\n" . $refEmail['body'];
        }
    }
}

// Append signature if composing brand new email
if (empty($draftId) && empty($body) && !empty($userRecord['signature'])) {
    $body = "\n\n" . $userRecord['signature'];
}

$error = null;

// Process POST submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $error = 'Security token invalid or expired. Please resubmit.';
    } else {
        $action = $_POST['action'] ?? 'send'; // 'send' or 'draft'
        $postedDraftId = !empty($_POST['draft_id']) ? (int)$_POST['draft_id'] : null;
        $toInput = trim($_POST['to'] ?? '');
        $ccInput = trim($_POST['cc'] ?? '');
        $bccInput = trim($_POST['bcc'] ?? '');
        $subjectInput = trim($_POST['subject'] ?? '');
        $bodyInput = sanitize_email_html($_POST['body'] ?? '');
        $parentEmailId = !empty($_POST['parent_email_id']) ? (int)$_POST['parent_email_id'] : null;

        $parseEmails = function(string $raw): array {
            $list = array_filter(array_map('trim', explode(',', $raw)));
            return array_filter($list, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL));
        };

        $toEmails = $parseEmails($toInput);
        $ccEmails = $parseEmails($ccInput);
        $bccEmails = $parseEmails($bccInput);

        if ($action === 'draft') {
            try {
                $savedDraftId = $mailService->saveOrUpdateDraft(
                    $userId,
                    $postedDraftId,
                    [
                        'subject'         => $subjectInput ?: '(Untitled Draft)',
                        'body'            => $bodyInput,
                        'to'              => $toEmails,
                        'cc'              => $ccEmails,
                        'bcc'             => $bccEmails,
                        'parent_email_id' => $parentEmailId,
                    ],
                    $_FILES['attachments'] ?? []
                );

                set_flash('success', 'Message saved to Drafts.');
                header('Location: ' . app_base_url('public/drafts.php'));
                exit;
            } catch (Throwable $e) {
                error_log('Draft save failed: ' . $e->getMessage());
                $error = 'Unable to save the draft. Please try again later.';
            }
        } elseif ($action === 'schedule') {
            $scheduledAt = trim($_POST['scheduled_at'] ?? '');

            if (empty($toEmails)) {
                $error = 'Please specify at least one valid recipient before scheduling.';
            } elseif ($scheduledAt === '') {
                $error = 'Choose the date and time for the scheduled message.';
            } else {
                try {
                    $savedDraftId = $mailService->saveOrUpdateDraft(
                        $userId,
                        $postedDraftId,
                        [
                            'subject'         => $subjectInput ?: '(Untitled Draft)',
                            'body'            => $bodyInput,
                            'to'              => $toEmails,
                            'cc'              => $ccEmails,
                            'bcc'             => $bccEmails,
                            'parent_email_id' => $parentEmailId,
                        ],
                        $_FILES['attachments'] ?? []
                    );

                    $scheduledService->schedule(
                        $userId,
                        $savedDraftId,
                        $scheduledAt,
                        (string)($userRecord['timezone'] ?? 'UTC')
                    );

                    log_activity($userId, 'EMAIL_SCHEDULED', 'SUCCESS', 'Scheduled message: ' . ($subjectInput ?: '(No Subject)'));
                    set_flash('success', 'Message scheduled. It will send automatically at the chosen time.');
                    header('Location: ' . app_base_url('public/scheduled.php'));
                    exit;
                } catch (InvalidArgumentException $e) {
                    $error = $e->getMessage();
                } catch (Throwable $e) {
                    error_log('Schedule creation failed: ' . $e->getMessage());
                    $error = 'Unable to schedule the message. Please try again later.';
                }
            }
        } else {
            // Send action
            if (empty($toEmails)) {
                $error = 'Please specify at least one valid recipient in the "To" field.';
            } else {
                $sendResult = $mailService->sendEmail(
                    $userId,
                    ['to' => $toEmails, 'cc' => $ccEmails, 'bcc' => $bccEmails],
                    $subjectInput ?: '(No Subject)',
                    $bodyInput,
                    $parentEmailId
                );

                if ($sendResult['success']) {
                    $sentEmailId = $sendResult['sent_email_id'];
                    $inboxEmailIds = $sendResult['inbox_email_ids'] ?? [];
                    $attachmentModel = new \App\Models\Attachment($pdo);

                    // Attach files to sent email and recipient inbox copies
                    if (!empty($_FILES['attachments']['name'][0])) {
                        $fileCount = count($_FILES['attachments']['name']);
                        for ($i = 0; $i < $fileCount; $i++) {
                            if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                                $file = [
                                    'name'     => $_FILES['attachments']['name'][$i],
                                    'type'     => $_FILES['attachments']['type'][$i],
                                    'tmp_name' => $_FILES['attachments']['tmp_name'][$i],
                                    'error'    => $_FILES['attachments']['error'][$i],
                                    'size'     => $_FILES['attachments']['size'][$i],
                                ];
                                $storedResult = $attachmentService->storeUploadedFile($sentEmailId, $file);
                                foreach ($inboxEmailIds as $inboxId) {
                                    $attachmentModel->create([
                                        'email_id'          => $inboxId,
                                        'original_filename' => $storedResult['original_filename'],
                                        'stored_filename'   => $storedResult['stored_filename'],
                                        'filepath'          => $storedResult['filepath'],
                                        'mime_type'         => $storedResult['mime_type'],
                                        'filesize'          => $storedResult['filesize'],
                                    ]);
                                }
                            }
                        }
                    }

                    // If email was previously saved as a draft, carry over its
                    // attachments. The service verifies that the draft belongs
                    // to this user and is actually a draft before copying.
                    if ($postedDraftId > 0) {
                        try {
                            $mailService->transferDraftAttachmentsOnSend($postedDraftId, $userId, $sentEmailId, $inboxEmailIds);
                        } catch (Throwable $transferError) {
                            error_log('Draft attachment transfer failed: ' . $transferError->getMessage());
                            // Surface the attachment transfer error to user instead of silently succeeding
                            set_flash('warning', 'Message sent, but draft attachments could not be copied: ' . $transferError->getMessage());
                        }
                    }

                    // Check for any delivery_status failures (SMTP path)
                    $deliveryCheck = $pdo->prepare("
                        SELECT status, COUNT(*) as cnt FROM delivery_status
                        WHERE email_id = ? AND transport = 'smtp'
                        GROUP BY status
                    ");
                    $deliveryCheck->execute([$sentEmailId]);
                    $deliveryStatuses = $deliveryCheck->fetchAll(PDO::FETCH_KEY_PAIR);

                    if (isset($deliveryStatuses['failed'])) {
                        set_flash('warning', 'Message queued for delivery, but some recipients may not receive it (SMTP reported failure).');
                    } elseif (isset($deliveryStatuses['unknown'])) {
                        set_flash('warning', 'Message queued for delivery, but SMTP outcome is uncertain for some recipients. Will retry automatically.');
                    }

                    log_activity($userId, 'EMAIL_SENT', 'SUCCESS', "Subject: {$subjectInput} to: " . implode(', ', $toEmails));
                    set_flash('success', 'Your message has been sent successfully.');
                    header('Location: ' . app_base_url('public/sent.php'));
                    exit;
                } else {
                    $error = 'Failed to deliver message: ' . ($sendResult['error'] ?? 'Unknown error');
                }
            }
        }
    }
}

$initialBodyHtml = '';
if (!empty($body)) {
    // Never inject stored content into #richEditor (or the hidden body input)
    // without sanitization.
    $initialBodyHtml = sanitize_email_html($body);
    $body = $initialBodyHtml;
}

$pageTitle = $draftId ? 'Edit Draft' : 'Compose Message';
require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="card shadow-sm border-0 mb-4 compose-card">
    <div class="card-header bg-body py-3 border-bottom d-flex justify-content-between align-items-center">
        <h5 class="mb-0 fw-bold d-flex align-items-center gap-2">
            <i class="bi bi-pencil-square text-primary"></i>
            <span><?= $draftId ? 'Edit Draft' : 'New Message' ?></span>
        </h5>
        <div class="d-flex align-items-center">
            <span id="autosaveStatus" class="small text-muted me-3 d-flex align-items-center gap-1"></span>
            <a href="<?= app_base_url('public/inbox.php') ?>" class="btn-close" aria-label="Close" title="Close compose"></a>
        </div>
    </div>

    <div class="card-body p-4">
        <?php if (!empty($error)): ?>
            <div class="alert alert-danger shadow-sm mb-3 d-flex align-items-center">
                <i class="bi bi-exclamation-triangle-fill me-2 fs-5"></i>
                <div><?= e($error) ?></div>
            </div>
        <?php endif; ?>

        <!-- Client-side validation alert container -->
        <div id="attachmentAlert" class="alert alert-danger alert-dismissible fade show d-none mb-3" role="alert">
            <span id="attachmentAlertMsg"></span>
            <button type="button" class="btn-close" data-action="dismiss-attachment-alert" aria-label="Close"></button>
        </div>

        <form method="POST" action="<?= app_base_url('public/compose.php') ?>" enctype="multipart/form-data" id="composeForm">
            <?= csrf_field() ?>
            <input type="hidden" name="draft_id" id="draftId" value="<?= e($draftId ?? '') ?>">
            <?php if ($parentEmailId): ?>
                <input type="hidden" name="parent_email_id" value="<?= $parentEmailId ?>">
            <?php endif; ?>

            <!-- Real form inputs for recipients (kept in sync with interactive chips) -->
            <input type="hidden" name="to" id="toHiddenInput" value="<?= e($to) ?>">
            <input type="hidden" name="cc" id="ccHiddenInput" value="<?= e($cc) ?>">
            <input type="hidden" name="bcc" id="bccHiddenInput" value="<?= e($bcc) ?>">

            <!-- "To" Field with Recipient Chips -->
            <div class="compose-field-row mb-2">
                <div class="recipient-chips-container" id="toChipsContainer" data-field="to">
                    <label class="chips-label" for="toTextInput">To</label>
                    <div class="chips-list" id="toChipsList"></div>
                    <input type="text" class="chips-text-input flex-grow-1" id="toTextInput" placeholder="Enter recipient email..." autocomplete="off">
                    <div class="chips-actions ms-auto d-flex gap-1">
                        <button type="button" class="btn btn-sm btn-link text-decoration-none py-0 px-1 text-muted" id="toggleCcBtn" title="Add Cc recipient">Cc</button>
                        <button type="button" class="btn btn-sm btn-link text-decoration-none py-0 px-1 text-muted" id="toggleBccBtn" title="Add Bcc recipient">Bcc</button>
                    </div>
                    <div class="autocomplete-dropdown shadow-sm" id="toDropdown" style="display: none;"></div>
                </div>
            </div>

            <!-- "Cc" Field (collapsible) -->
            <div class="compose-field-row mb-2 <?= empty($cc) ? 'd-none' : '' ?>" id="ccRowWrapper">
                <div class="recipient-chips-container" id="ccChipsContainer" data-field="cc">
                    <label class="chips-label" for="ccTextInput">Cc</label>
                    <div class="chips-list" id="ccChipsList"></div>
                    <input type="text" class="chips-text-input flex-grow-1" id="ccTextInput" placeholder="Enter Cc email..." autocomplete="off">
                    <div class="chips-actions ms-auto">
                        <button type="button" class="btn btn-sm btn-link text-decoration-none py-0 px-1 text-danger" id="closeCcBtn" title="Remove Cc field">&times;</button>
                    </div>
                    <div class="autocomplete-dropdown shadow-sm" id="ccDropdown" style="display: none;"></div>
                </div>
            </div>

            <!-- "Bcc" Field (collapsible) -->
            <div class="compose-field-row mb-2 <?= empty($bcc) ? 'd-none' : '' ?>" id="bccRowWrapper">
                <div class="recipient-chips-container" id="bccChipsContainer" data-field="bcc">
                    <label class="chips-label" for="bccTextInput">Bcc</label>
                    <div class="chips-list" id="bccChipsList"></div>
                    <input type="text" class="chips-text-input flex-grow-1" id="bccTextInput" placeholder="Enter Bcc email..." autocomplete="off">
                    <div class="chips-actions ms-auto">
                        <button type="button" class="btn btn-sm btn-link text-decoration-none py-0 px-1 text-danger" id="closeBccBtn" title="Remove Bcc field">&times;</button>
                    </div>
                    <div class="autocomplete-dropdown shadow-sm" id="bccDropdown" style="display: none;"></div>
                </div>
            </div>

            <style>
                .recipient-chips-container {
                    display: flex;
                    flex-wrap: wrap;
                    align-items: center;
                    gap: 0.25rem;
                    padding: 0.5rem;
                    border: 1px solid var(--bs-border-color);
                    border-radius: 0.375rem;
                    background-color: var(--bs-body-bg);
                    transition: border-color 0.15s ease-in-out, box-shadow 0.15s ease-in-out;
                }
                .recipient-chips-container:focus-within {
                    border-color: var(--bs-primary);
                    box-shadow: 0 0 0 0.25rem rgba(var(--bs-primary-rgb), 0.25);
                }
                .chips-label {
                    flex-shrink: 0;
                    font-weight: 600;
                    font-size: 0.875rem;
                    color: var(--bs-secondary-color);
                    white-space: nowrap;
                }
                .chips-list {
                    display: flex;
                    flex-wrap: wrap;
                    gap: 0.25rem;
                    flex: 1;
                    min-width: 0;
                }
                .chips-text-input {
                    flex: 1;
                    min-width: 120px;
                    border: none;
                    background: transparent;
                    padding: 0.25rem 0;
                    font-size: 0.875rem;
                    outline: none;
                }
                .chips-text-input::placeholder {
                    color: var(--bs-secondary-color);
                    opacity: 0.7;
                }
                .chip {
                    display: inline-flex;
                    align-items: center;
                    gap: 0.25rem;
                    padding: 0.125rem 0.5rem;
                    background-color: var(--bs-primary-bg-subtle);
                    border: 1px solid var(--bs-primary-border-subtle);
                    border-radius: 1rem;
                    font-size: 0.8125rem;
                    color: var(--bs-primary-text-emphasis);
                }
                .chip .chip-remove {
                    display: inline-flex;
                    align-items: center;
                    justify-content: center;
                    width: 1rem;
                    height: 1rem;
                    border-radius: 50%;
                    color: inherit;
                    opacity: 0.7;
                    transition: opacity 0.15s, background-color 0.15s;
                }
                .chip .chip-remove:hover {
                    opacity: 1;
                    background-color: rgba(0,0,0,0.1);
                }
                @media (max-width: 575.98px) {
                    .recipient-chips-container {
                        overflow-x: auto;
                        flex-wrap: nowrap;
                        padding-bottom: 0.75rem; /* space for scrollbar */
                    }
                    .chips-list {
                        flex-wrap: nowrap;
                    }
                    .chips-text-input {
                        min-width: 140px;
                        flex-shrink: 0;
                    }
                }
            </style>

            <!-- Subject Input -->
            <div class="compose-field-row mb-3">
                <div class="border rounded bg-body px-3 py-1 d-flex align-items-center shadow-sm">
                    <label for="subjectInput" class="text-muted fw-semibold me-2 mb-0" style="min-width: 60px;">Subject</label>
                    <input type="text" class="form-control border-0 bg-transparent shadow-none p-1 fw-medium" name="subject" id="subjectInput" value="<?= e($subject) ?>" placeholder="Subject line" autocomplete="off">
                </div>
            </div>

            <!-- Rich Text Formatting Toolbar & Editor Container -->
            <div class="editor-wrapper mb-3 border rounded shadow-sm">
                <!-- Formatting Toolbar -->
                <div class="editor-toolbar p-2 bg-body-tertiary border-bottom d-flex flex-wrap align-items-center gap-1 flex-column flex-md-row">
                    <div class="btn-group btn-group-sm me-2" role="group" aria-label="Text Formatting">
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="bold" title="Bold (Ctrl+B)">
                            <i class="bi bi-type-bold"></i>
                        </button>
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="italic" title="Italic (Ctrl+I)">
                            <i class="bi bi-type-italic"></i>
                        </button>
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="underline" title="Underline (Ctrl+U)">
                            <i class="bi bi-type-underline"></i>
                        </button>
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="strikeThrough" title="Strikethrough">
                            <i class="bi bi-type-strikethrough"></i>
                        </button>
                    </div>

                    <div class="btn-group btn-group-sm me-2" role="group" aria-label="Lists and Indents">
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="insertUnorderedList" title="Bulleted List">
                            <i class="bi bi-list-ul"></i>
                        </button>
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="insertOrderedList" title="Numbered List">
                            <i class="bi bi-list-ol"></i>
                        </button>
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="formatBlock" data-val="<blockquote>" title="Quote">
                            <i class="bi bi-quote"></i>
                        </button>
                    </div>

                    <div class="btn-group btn-group-sm me-2" role="group" aria-label="Links and Clears">
                        <button type="button" class="btn btn-light border editor-btn" id="insertLinkBtn" title="Insert Link">
                            <i class="bi bi-link-45deg"></i>
                        </button>
                        <button type="button" class="btn btn-light border editor-btn" data-cmd="removeFormat" title="Clear Formatting">
                            <i class="bi bi-eraser"></i>
                        </button>
                    </div>

                    <div class="ms-auto small text-muted d-none d-md-block">
                        <i class="bi bi-card-text me-1"></i> Rich Text
                    </div>
                </div>

                <!-- Rich WYSIWYG Contenteditable Area -->
                <div class="rich-text-editor p-3" id="richEditor" contenteditable="true" role="textbox" aria-label="Message body" aria-multiline="true" style="min-height: 240px; max-height: 500px; overflow-y: auto;"><?= $initialBodyHtml ?></div>

                <!-- Hidden Textarea for Form POST submission -->
                <textarea name="body" id="bodyInput" class="d-none"><?= htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?></textarea>
            </div>

            <!-- Drag-and-Drop File Attachment Dropzone -->
            <div class="attachment-section mb-3">
                <div class="attachment-dropzone compact-dropzone p-2 text-center border border-2 border-dashed rounded bg-body-tertiary" id="attachmentDropzone">
                    <input type="file" id="attachmentInput" name="attachments[]" multiple class="d-none">
                    <div class="dropzone-prompt cursor-pointer small">
                        <i class="bi bi-paperclip fs-5 text-primary align-middle me-1"></i>
                        <span class="fw-semibold">Attach files: </span><button type="button" id="browseAttachments" class="btn btn-link p-0 align-baseline small">browse</button> or drag &amp; drop here <span class="text-muted">(up to 25MB each)</span>
                    </div>
                </div>

                <!-- Attachment Staging List / Cards -->
                <div class="attachment-previews d-flex flex-wrap gap-2 mt-3" id="attachmentPreviews"></div>
                <div class="small text-muted mt-2 d-none" id="attachmentSummary"></div>
            </div>

            <!-- Submit Buttons & Actions -->
            <?php
            $scheduleTz = new DateTimeZone(in_array((string)($userRecord['timezone'] ?? 'UTC'), timezone_identifiers_list(), true) ? (string)$userRecord['timezone'] : 'UTC');
            $minScheduleValue = (new DateTimeImmutable('+1 minute', $scheduleTz))->format('Y-m-d\TH:i');
            ?>
            <div class="d-flex flex-wrap justify-content-between align-items-center pt-3 border-top gap-3">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <button type="submit" name="action" value="send" id="btnSendMessage" class="btn btn-primary px-4 fw-semibold shadow-sm d-flex align-items-center gap-2">
                        <i class="bi bi-send-fill"></i>
                        <span>Send Message</span>
                    </button>
                    <button type="submit" name="action" value="draft" id="btnSaveDraft" class="btn btn-outline-secondary d-flex align-items-center gap-2">
                        <i class="bi bi-file-earmark-arrow-down"></i>
                        <span>Save as Draft</span>
                    </button>
                    <button type="button" class="btn btn-outline-secondary d-flex align-items-center gap-1" data-bs-toggle="modal" data-bs-target="#scheduleSendModal" title="Schedule send">
                        <i class="bi bi-clock-history"></i>
                        <span>Schedule</span>
                    </button>
                </div>

                <a href="<?= app_base_url('public/inbox.php') ?>" id="btnDiscardCompose" class="btn btn-light text-danger d-flex align-items-center gap-1 border" data-confirm="Discard this draft? Any unsaved changes will be lost.">
                    <i class="bi bi-trash"></i>
                    <span>Discard</span>
                </a>
            </div>

            <!-- Schedule Send Modal -->
            <div class="modal fade" id="scheduleSendModal" tabindex="-1" aria-labelledby="scheduleSendModalLabel" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered" style="max-width: 420px;">
                    <div class="modal-content shadow-lg border-0 rounded-4">
                        <div class="modal-header border-bottom py-3">
                            <h5 class="modal-title fw-bold fs-6 d-flex align-items-center gap-2" id="scheduleSendModalLabel">
                                <i class="bi bi-calendar2-clock text-primary"></i>
                                <span>Schedule Send</span>
                            </h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body p-4">
                            <p class="text-muted small mb-3">Choose when you want this message delivered (in your timezone: <strong><?= e($scheduleTz->getName()) ?></strong>).</p>
                            <div class="mb-3">
                                <label for="scheduledAtInput" class="form-label small fw-semibold text-muted">Delivery Date &amp; Time</label>
                                <input type="datetime-local" class="form-control" name="scheduled_at" id="scheduledAtInput"
                                    value="<?= e($_POST['scheduled_at'] ?? '') ?>" min="<?= e($minScheduleValue) ?>"
                                    aria-label="Scheduled send date and time">
                            </div>
                        </div>
                        <div class="modal-footer border-top py-2 px-4 d-flex justify-content-between">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" name="action" value="schedule" class="btn btn-sm btn-primary rounded-pill px-4 fw-semibold">
                                <i class="bi bi-check2 me-1"></i> Confirm Schedule
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
