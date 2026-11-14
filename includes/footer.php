<?php
/**
 * Couriva - Global Footer Template
 */

if (!defined('WEBMAIL_INIT')) {
    define('WEBMAIL_INIT', true);
}
?>
<?php if (is_authenticated() && empty($_SESSION['must_change_password'])): ?>
        </main>
    </div>
</div>

<!-- Gmail-Style Floating Docked Compose Window -->
<div id="dockedComposeContainer" class="docked-compose-window shadow-lg border d-none" role="dialog" aria-labelledby="dockedComposeTitle" tabindex="-1" aria-modal="true">
    <!-- Window Header -->
    <div id="dockedComposeHeader" class="docked-compose-header d-flex align-items-center justify-content-between px-3 py-2">
        <span class="fw-semibold small text-truncate" id="dockedComposeTitle">New Message</span>
        <div class="d-flex align-items-center gap-2 window-controls">
            <button type="button" class="btn btn-sm btn-link text-muted p-0 border-0 compose-minimize-btn" title="Minimize" aria-label="Minimize compose window">
                <i class="bi bi-dash-lg"></i>
            </button>
            <button type="button" class="btn btn-sm btn-link text-muted p-0 border-0 compose-maximize-btn" title="Full screen" aria-label="Maximize compose window">
                <i class="bi bi-arrows-angle-expand"></i>
            </button>
            <button type="button" class="btn btn-sm btn-link text-muted p-0 border-0 compose-close-btn" title="Save and close" aria-label="Close compose window">
                <i class="bi bi-x-lg"></i>
            </button>
        </div>
    </div>

    <!-- Window Body -->
    <div class="docked-compose-body" id="dockedComposeBody">
        <form id="dockedComposeForm" method="POST" action="<?= app_base_url('public/compose.php') ?>" enctype="multipart/form-data">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="draft_id" id="dockedDraftId" value="">
            
            <!-- Recipients (To) -->
            <div class="docked-field-row border-bottom px-3 py-1 d-flex align-items-center">
                <span class="text-muted small me-2" style="min-width: 28px;">To</span>
                <input type="text" name="to" id="dockedTo" class="form-control form-control-sm border-0 shadow-none p-1" placeholder="Recipients" autocomplete="off">
            </div>

            <!-- Subject -->
            <div class="docked-field-row border-bottom px-3 py-1">
                <input type="text" name="subject" id="dockedSubject" class="form-control form-control-sm border-0 shadow-none p-1" placeholder="Subject">
            </div>

            <!-- Message Body / Rich Editor -->
            <div class="docked-editor-container p-3 flex-grow-1">
                <textarea name="body" id="dockedBody" class="form-control border-0 shadow-none p-0" rows="10" placeholder="Write your message here..."></textarea>
            </div>

            <!-- Docked Compose Footer -->
            <div class="docked-compose-footer border-top px-3 py-2 d-flex align-items-center justify-content-between bg-body">
                <div class="d-flex align-items-center gap-2">
                    <button type="submit" name="action" value="send" class="btn btn-primary btn-sm rounded-pill px-3 fw-semibold" id="btnDockedSend">
                        Send
                    </button>
                    <span class="text-muted small" id="dockedSaveStatus">Saved</span>
                </div>
                <div class="d-flex align-items-center gap-1">
                    <button type="button" class="btn btn-sm btn-link text-muted p-1 border-0" id="btnDockedAttach" title="Attach files" aria-label="Attach files">
                        <i class="bi bi-paperclip fs-5"></i>
                    </button>
                    <button type="button" class="btn btn-sm btn-link text-danger p-1 border-0" id="btnDockedDiscard" title="Discard draft" aria-label="Discard draft">
                        <i class="bi bi-trash fs-5"></i>
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<!-- Gmail-Style Action SnackBar with Undo -->
<div id="gmailSnackbar" class="gmail-snackbar shadow-lg d-none" role="status" aria-live="polite">
    <span id="snackbarMessage" class="snackbar-message">Action completed</span>
    <button type="button" class="btn btn-sm btn-link snackbar-undo-btn text-warning text-decoration-none fw-semibold p-0 ms-3" id="snackbarUndoBtn">
        Undo
    </button>
    <button type="button" class="btn btn-sm btn-link text-white-50 p-0 ms-2" id="snackbarCloseBtn" aria-label="Dismiss">
        <i class="bi bi-x"></i>
    </button>
</div>

<!-- Gmail Keyboard Shortcuts Cheatsheet Modal -->
<div class="modal fade" id="keyboardShortcutsModal" tabindex="-1" aria-labelledby="keyboardShortcutsModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable modal-lg">
        <div class="modal-content shadow-lg border-0">
            <div class="modal-header border-bottom px-4 py-3">
                <h5 class="modal-title fw-bold d-flex align-items-center gap-2" id="keyboardShortcutsModalLabel">
                    <i class="bi bi-keyboard text-primary"></i>
                    <span>Keyboard shortcuts</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body p-4">
                <div class="row g-4">
                    <div class="col-md-6">
                        <h6 class="fw-bold text-uppercase text-muted small mb-3">Navigation</h6>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Next email in list</span>
                            <kbd class="shortcut-key">j</kbd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Previous email in list</span>
                            <kbd class="shortcut-key">k</kbd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Open highlighted email</span>
                            <div><kbd class="shortcut-key">o</kbd> <span class="text-muted small">or</span> <kbd class="shortcut-key">Enter</kbd></div>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Focus search box</span>
                            <kbd class="shortcut-key">/</kbd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2">
                            <span class="small">Show shortcut help</span>
                            <kbd class="shortcut-key">?</kbd>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <h6 class="fw-bold text-uppercase text-muted small mb-3">Actions</h6>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Compose new message</span>
                            <kbd class="shortcut-key">c</kbd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Archive selected email</span>
                            <kbd class="shortcut-key">e</kbd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Delete / Move to Trash</span>
                            <kbd class="shortcut-key">#</kbd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2 border-bottom">
                            <span class="small">Star / unstar conversation</span>
                            <kbd class="shortcut-key">s</kbd>
                        </div>
                        <div class="d-flex justify-content-between align-items-center py-2">
                            <span class="small">Select all / Deselect</span>
                            <kbd class="shortcut-key">* a</kbd>
                        </div>
                    </div>
                </div>
            </div>
            <div class="modal-footer border-top bg-body-subtle px-4 py-2">
                <span class="text-muted small">Press <kbd class="shortcut-key">Esc</kbd> to close</span>
            </div>
        </div>
    </div>
</div>

<!-- Create Label Modal (Mockup Parity) -->
<div class="modal fade" id="createLabelModal" tabindex="-1" aria-labelledby="createLabelModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 440px;">
        <div class="modal-content shadow-lg border rounded-4 bg-body">
            <div class="modal-header border-bottom px-4 pt-3 pb-2">
                <div>
                    <h5 class="modal-title fw-bold text-body" id="createLabelModalLabel">Create label</h5>
                    <p class="text-muted small mb-0">Organize your emails with a custom label.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="createLabelForm" method="POST" action="<?= app_base_url('public/settings.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_label">
                <div class="modal-body px-4 py-3">
                    <div id="createLabelAlert" class="alert alert-danger small py-2 mb-3 d-none" role="alert"></div>
                    <div class="mb-3">
                        <label for="createLabelNameInput" class="form-label small text-muted fw-semibold">Label name</label>
                        <input type="text" class="form-control" id="createLabelNameInput" name="label_name" placeholder="e.g. Project updates" required maxlength="64" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label small text-muted fw-semibold d-block mb-2">Label color</label>
                        <div class="d-flex align-items-center gap-2 color-swatches-group">
                            <label class="color-swatch-item">
                                <input type="radio" name="label_color" value="#6366f1" checked>
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
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold" id="btnSubmitCreateLabel">+ Create label</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- Create Folder Modal (Mockup Parity) -->
<div class="modal fade" id="createFolderModal" tabindex="-1" aria-labelledby="createFolderModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 440px;">
        <div class="modal-content shadow-lg border rounded-4 bg-body">
            <div class="modal-header border-bottom px-4 pt-3 pb-2">
                <div>
                    <h5 class="modal-title fw-bold text-body" id="createFolderModalLabel">Create folder</h5>
                    <p class="text-muted small mb-0">Organize your emails with a custom folder.</p>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="createFolderForm" method="POST" action="<?= app_base_url('public/settings.php') ?>">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="create_folder">
                <div class="modal-body px-4 py-3">
                    <div id="createFolderAlert" class="alert alert-danger small py-2 mb-3 d-none" role="alert"></div>
                    <div class="mb-3">
                        <label for="createFolderNameInput" class="form-label small text-muted fw-semibold">Folder name</label>
                        <input type="text" class="form-control" id="createFolderNameInput" name="folder_name" placeholder="e.g. Invoices" required maxlength="80" autocomplete="off">
                    </div>
                    <div>
                        <label class="form-label small text-muted fw-semibold d-block mb-2">Folder color</label>
                        <div class="d-flex align-items-center gap-2 color-swatches-group">
                            <label class="color-swatch-item">
                                <input type="radio" name="folder_color" value="#64748b" checked>
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
                    <button type="submit" class="btn btn-primary btn-sm px-3 fw-semibold" id="btnSubmitCreateFolder">+ Create folder</button>
                </div>
            </form>
        </div>
    </div>
</div>
<?php else: ?>
</div>
<?php endif; ?>

<!-- Custom System-Wide Confirmation & Action Dialog (Mockup Parity) -->
<div class="modal fade" id="courivaConfirmModal" tabindex="-1" aria-labelledby="courivaConfirmTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable" style="max-width: 440px;">
        <div class="modal-content shadow-lg border couriva-dialog-card">
            <div class="modal-header border-0 pb-0 pt-4 px-4 d-flex align-items-start justify-content-between">
                <div class="couriva-dialog-icon-badge danger" id="courivaConfirmBadge">
                    <i class="bi bi-trash3" id="courivaConfirmIcon"></i>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close" id="courivaConfirmCloseBtn"></button>
            </div>
            <div class="modal-body px-4 pt-3 pb-2">
                <h5 class="modal-title fw-bold text-body mb-2" id="courivaConfirmTitle">Confirm</h5>
                <p class="text-muted small mb-0 lh-base" id="courivaConfirmMessage"></p>
                <div id="courivaConfirmInputContainer" class="d-none mt-3">
                    <input type="text" class="form-control" id="courivaConfirmInput" placeholder="" autocomplete="off">
                    <div id="courivaConfirmInputError" class="text-danger small mt-1 d-none"></div>
                </div>
            </div>
            <div class="modal-footer border-0 px-4 pb-4 pt-3 d-flex justify-content-end gap-2">
                <button type="button" class="btn btn-outline-secondary btn-sm px-3 fw-medium" id="courivaConfirmCancelBtn" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger btn-sm px-3 fw-semibold d-inline-flex align-items-center gap-1" id="courivaConfirmSubmitBtn">
                    <i class="bi bi-trash3" id="courivaConfirmSubmitIcon"></i>
                    <span id="courivaConfirmSubmitText">Confirm</span>
                </button>
            </div>
        </div>
    </div>
</div>

<footer class="text-center py-3 text-muted small border-top mt-auto">
    <div class="container">
        <span>&copy; <?= date('Y') ?> <?= e(app_name()) ?> &middot; Internal mail for <?= e('@' . app_domain()) ?></span>
    </div>
</footer>

<!-- Bootstrap 5 JS Bundle -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- Local SVG icon fallback -->
<script src="<?= app_base_url('assets/js/icons.js') ?>"></script>
<!-- Application Script -->
<script src="<?= app_base_url('assets/js/app.js') ?>"></script>
</body>
</html>
