<?php
/**
 * Couriva - Contacts Management
 */

define('WEBMAIL_INIT', true);

require_once dirname(__DIR__) . '/includes/functions.php';
require_once dirname(__DIR__) . '/includes/database.php';
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/app/Models/Contact.php';

require_auth();

$userId = (int)$_SESSION['user_id'];
$contactModel = new \App\Models\Contact($pdo);
$activeFolder = 'contacts';
$pageTitle = 'Contacts';

// Handle POST actions (Create, Edit, Delete)
$formError = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verify_csrf_token()) {
        $formError = 'Security token invalid.';
    } else {
        $action = $_POST['action'] ?? '';

        if ($action === 'create') {
            $name = trim($_POST['contact_name'] ?? '');
            $email = trim($_POST['contact_email'] ?? '');

            if (empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $formError = 'Valid contact name and email are required.';
            } else {
                try {
                    $contactModel->create([
                        'user_id'       => $userId,
                        'contact_name'  => $name,
                        'contact_email' => $email,
                        'phone'         => trim($_POST['phone'] ?? ''),
                        'company'       => trim($_POST['company'] ?? ''),
                        'notes'         => trim($_POST['notes'] ?? ''),
                    ]);
                    set_flash('success', 'Contact added successfully.');
                } catch (\InvalidArgumentException $e) {
                    $formError = $e->getMessage();
                }
            }
        } elseif ($action === 'update') {
            $contactId = (int)($_POST['contact_id'] ?? 0);
            $name = trim($_POST['contact_name'] ?? '');
            $email = trim($_POST['contact_email'] ?? '');

            if ($contactId <= 0 || empty($name) || empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $formError = 'Valid contact name and email are required to update.';
            } else {
                try {
                    $contactModel->update($contactId, $userId, [
                        'contact_name'  => $name,
                        'contact_email' => $email,
                        'phone'         => trim($_POST['phone'] ?? ''),
                        'company'       => trim($_POST['company'] ?? ''),
                        'notes'         => trim($_POST['notes'] ?? ''),
                    ]);
                    set_flash('success', 'Contact details updated successfully.');
                } catch (\InvalidArgumentException $e) {
                    $formError = $e->getMessage();
                }
            }
        } elseif ($action === 'delete') {
            $contactId = (int)($_POST['contact_id'] ?? 0);
            if ($contactId > 0) {
                $contactModel->delete($contactId, $userId);
                set_flash('success', 'Contact removed from your address book.');
            }
        }
    }
    // If there's a form error, don't redirect - show it inline
    if ($formError !== null) {
        // Fall through to render with error
    } else {
        header('Location: ' . app_base_url('public/contacts.php'));
        exit;
    }
}

$search = trim($_GET['search'] ?? '');
$contacts = $contactModel->getByUserId($userId, $search);

require_once dirname(__DIR__) . '/includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold"><i class="bi bi-person-lines-fill text-primary me-2"></i>Contacts</h4>
        <span class="text-muted small">Manage your personal address book and quick-compose recipients</span>
    </div>

    <button type="button" class="btn btn-primary btn-sm d-flex align-items-center gap-1 shadow-sm" data-bs-toggle="modal" data-bs-target="#newContactModal">
        <i class="bi bi-person-plus-fill"></i>
        <span>Add Contact</span>
    </button>
</div>

<!-- Search & Table Card -->
<div class="card shadow-sm border-0 mb-4">
    <div class="card-header bg-body py-3 border-bottom">
        <form method="GET" action="<?= app_base_url('public/contacts.php') ?>" class="d-flex gap-2">
            <input type="text" name="search" class="form-control form-control-sm" placeholder="Search contacts by name, email, or company..." value="<?= e($search) ?>">
            <button type="submit" class="btn btn-sm btn-outline-secondary" title="Search"><i class="bi bi-search"></i></button>
            <?php if (!empty($search)): ?>
                <a href="<?= app_base_url('public/contacts.php') ?>" class="btn btn-sm btn-light border" title="Clear search">Clear</a>
            <?php endif; ?>
        </form>
    </div>

    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light small text-uppercase">
                <tr>
                    <th>Name</th>
                    <th>Email Address</th>
                    <th>Phone</th>
                    <th>Company</th>
                    <th>Notes</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($contacts)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">No contacts found in your address book.</td></tr>
                <?php else: ?>
                    <?php foreach ($contacts as $c): ?>
                        <tr>
                            <td class="fw-semibold">
                                <span class="badge rounded-pill bg-primary-subtle text-primary border me-2" style="width: 28px; height: 28px; display: inline-flex; align-items: center; justify-content: center;">
                                    <?= strtoupper(substr($c['contact_name'], 0, 1)) ?>
                                </span>
                                <?= e($c['contact_name']) ?>
                            </td>
                            <td>
                                <a href="<?= app_base_url('public/compose.php?to=' . urlencode($c['contact_email'])) ?>" class="text-decoration-none">
                                    <?= e($c['contact_email']) ?>
                                </a>
                            </td>
                            <td class="text-muted small"><?= e($c['phone'] ?: '—') ?></td>
                            <td class="text-muted small"><?= e($c['company'] ?: '—') ?></td>
                            <td class="text-muted small text-truncate" style="max-width: 200px;" title="<?= e($c['notes'] ?? '') ?>"><?= e($c['notes'] ?: '—') ?></td>
                            <td class="text-end text-nowrap">
                                <a href="<?= app_base_url('public/compose.php?to=' . urlencode($c['contact_email'])) ?>" class="btn btn-sm btn-outline-primary py-1 px-2 me-1" title="Compose to <?= e($c['contact_name']) ?>">
                                    <i class="bi bi-pencil-square"></i> <span class="d-none d-lg-inline ms-1">Compose</span>
                                </a>
                                <button type="button" class="btn btn-sm btn-outline-secondary py-1 px-2 me-1 edit-contact-btn" 
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= e($c['contact_name']) ?>"
                                    data-email="<?= e($c['contact_email']) ?>"
                                    data-phone="<?= e($c['phone'] ?? '') ?>"
                                    data-company="<?= e($c['company'] ?? '') ?>"
                                    data-notes="<?= e($c['notes'] ?? '') ?>"
                                    title="Edit Contact">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                <button type="button" class="btn btn-sm btn-outline-danger py-1 px-2 delete-contact-btn"
                                    data-id="<?= $c['id'] ?>"
                                    data-name="<?= e($c['contact_name']) ?>"
                                    title="Delete Contact">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modal: Add New Contact -->
<div class="modal fade" id="newContactModal" tabindex="-1" aria-labelledby="newContactModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= app_base_url('public/contacts.php') ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="create">
            <div class="modal-header">
                <h5 class="modal-title" id="newContactModalLabel"><i class="bi bi-person-plus text-primary me-2"></i>New Contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?= render_inline_alert($formError) ?>
                <div class="mb-3">
                    <label for="newContactName" class="form-label fw-semibold">Contact Name *</label>
                    <input id="newContactName" type="text" class="form-control" name="contact_name" required placeholder="Enter contact name">
                </div>
                <div class="mb-3">
                    <label for="newContactEmail" class="form-label fw-semibold">Email Address *</label>
                    <input id="newContactEmail" type="email" class="form-control" name="contact_email" required placeholder="Enter email address">
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6 col-md-6">
                        <label for="newContactPhone" class="form-label small">Phone</label>
                        <input id="newContactPhone" type="text" class="form-control form-control-sm" name="phone" placeholder="09XXXXXXXXX" maxlength="11" data-phone-mask>
                        <div class="invalid-feedback" id="newContactPhoneError"></div>
                    </div>
                    <div class="col-sm-6 col-md-6">
                        <label for="newContactCompany" class="form-label small">Company</label>
                        <input id="newContactCompany" type="text" class="form-control form-control-sm" name="company" placeholder="Enter company">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="newContactNotes" class="form-label small">Notes</label>
                    <textarea id="newContactNotes" class="form-control form-control-sm" name="notes" rows="3" placeholder="Additional details..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Contact</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Edit Contact -->
<div class="modal fade" id="editContactModal" tabindex="-1" aria-labelledby="editContactModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <form method="POST" action="<?= app_base_url('public/contacts.php') ?>" class="modal-content" id="editContactForm">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="update">
            <input type="hidden" name="contact_id" id="editContactId">
            <div class="modal-header">
                <h5 class="modal-title" id="editContactModalLabel"><i class="bi bi-person-gear text-primary me-2"></i>Edit Contact</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <?= render_inline_alert($formError) ?>
                <div class="mb-3">
                    <label for="editContactName" class="form-label fw-semibold">Contact Name *</label>
                    <input type="text" class="form-control" name="contact_name" id="editContactName" required>
                </div>
                <div class="mb-3">
                    <label for="editContactEmail" class="form-label fw-semibold">Email Address *</label>
                    <input type="email" class="form-control" name="contact_email" id="editContactEmail" required>
                </div>
                <div class="row g-2 mb-3">
                    <div class="col-sm-6 col-md-6">
                        <label for="editContactPhone" class="form-label small">Phone</label>
                        <input type="text" class="form-control form-control-sm" name="phone" id="editContactPhone" placeholder="09XXXXXXXXX" maxlength="11" data-phone-mask>
                        <div class="invalid-feedback" id="editContactPhoneError"></div>
                    </div>
                    <div class="col-sm-6 col-md-6">
                        <label for="editContactCompany" class="form-label small">Company</label>
                        <input type="text" class="form-control form-control-sm" name="company" id="editContactCompany">
                    </div>
                </div>
                <div class="mb-3">
                    <label for="editContactNotes" class="form-label small">Notes</label>
                    <textarea class="form-control form-control-sm" name="notes" id="editContactNotes" rows="3"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-primary">Update Contact</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Delete Confirmation -->
<div class="modal fade" id="deleteContactModal" tabindex="-1" aria-labelledby="deleteContactModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-sm">
        <form method="POST" action="<?= app_base_url('public/contacts.php') ?>" class="modal-content">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="contact_id" id="deleteContactId">
            <div class="modal-header">
                <h6 class="modal-title fw-bold text-danger" id="deleteContactModalLabel"><i class="bi bi-exclamation-triangle-fill me-1"></i> Confirm Delete</h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body small text-muted">
                Are you sure you want to delete <strong id="deleteContactName" class="text-body"></strong> from your contacts?
            </div>
            <div class="modal-footer p-2">
                <button type="button" class="btn btn-sm btn-light border" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-sm btn-danger">Delete</button>
            </div>
        </form>
    </div>
</div>

<?php require_once dirname(__DIR__) . '/includes/footer.php'; ?>
