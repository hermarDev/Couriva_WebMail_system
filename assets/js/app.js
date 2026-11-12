/**
 * Couriva - Frontend Application Logic
 * Integrates:
 * 1. Dark/Light Theme Switching with DOM update and API persistence
 * 2. Recipient Chips & Autocomplete for To, Cc, Bcc
 * 3. Rich Text Formatting Toolbar & WYSIWYG Editor
 * 4. Drag-and-Drop Attachment Dropzone with client-side validation
 * 5. Real-time Draft Auto-Save
 * 6. Floating / Sticky Batch Actions Toolbar
 * 7. Quick Row Hover Actions (Read/Unread, Star, Trash)
 * 8. Desktop Reading Pane Split View with AJAX Preview
 * 9. Contacts Modals Data Binding
 * 10. Settings Live Signature Preview & Password Strength Meter
 * 11. Admin User Management Modal Data Binding
 */

document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    document.querySelectorAll('.password-toggle').forEach(function (button) {
        button.addEventListener('click', function () {
            const input = document.getElementById(button.getAttribute('aria-controls'));
            if (!input) return;
            const visible = input.type === 'password';
            input.type = visible ? 'text' : 'password';
            button.setAttribute('aria-pressed', String(visible));
            const action = button.getAttribute('aria-label').replace(/^(Show|Hide) /, '');
            button.setAttribute('aria-label', (visible ? 'Hide ' : 'Show ') + action);
            const icon = button.querySelector('svg, i');
            if (icon) {
                icon.setAttribute('class', visible ? 'bi bi-eye-slash' : 'bi bi-eye');
                const use = icon.querySelector('use');
                if (use) use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, visible ? '#eye-slash' : '#eye'));
            }
        });
    });

    // Global CSRF Token & Base URL
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';
    const baseUrlMeta = document.querySelector('meta[name="webmail-base-url"]');
    const baseUrl = baseUrlMeta ? (baseUrlMeta.getAttribute('content') || '') : '';

    // =========================================================================
    // 0. Auto-Dismiss Alerts
    // =========================================================================
    const autoAlerts = document.querySelectorAll('.alert.alert-dismissible:not(#attachmentAlert)');
    autoAlerts.forEach(function (alert) {
        setTimeout(function () {
            if (typeof bootstrap !== 'undefined' && bootstrap.Alert) {
                const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                if (bsAlert) bsAlert.close();
            }
        }, 5000);
    });

    // =========================================================================
    // 0.2 Mobile sidebar drawer
    // =========================================================================
    const sidebarMenu = document.getElementById('sidebarMenu');
    if (sidebarMenu) {
        const mobileSidebarQuery = window.matchMedia('(max-width: 767.98px)');
        const closeSidebarDrawer = function () {
            if (typeof bootstrap !== 'undefined' && bootstrap.Collapse) {
                bootstrap.Collapse.getOrCreateInstance(sidebarMenu, { toggle: false }).hide();
            }
        };
        const syncSidebarBackdrop = function () {
            const open = mobileSidebarQuery.matches && sidebarMenu.classList.contains('show');
            document.body.classList.toggle('sidebar-open', open);
        };

        sidebarMenu.addEventListener('shown.bs.collapse', syncSidebarBackdrop);
        sidebarMenu.addEventListener('hidden.bs.collapse', syncSidebarBackdrop);
        sidebarMenu.addEventListener('hide.bs.collapse', function () {
            document.body.classList.remove('sidebar-open');
        });

        document.addEventListener('click', function (event) {
            if (!mobileSidebarQuery.matches || !sidebarMenu.classList.contains('show')) return;
            if (sidebarMenu.contains(event.target) || event.target.closest('[data-bs-target="#sidebarMenu"]')) return;
            closeSidebarDrawer();
        });

        document.addEventListener('keydown', function (event) {
            if (event.key === 'Escape' && mobileSidebarQuery.matches && sidebarMenu.classList.contains('show')) {
                closeSidebarDrawer();
            }
        });

        mobileSidebarQuery.addEventListener('change', function () {
            if (!mobileSidebarQuery.matches) {
                document.body.classList.remove('sidebar-open');
                if (sidebarMenu.classList.contains('show')) {
                    closeSidebarDrawer();
                }
            } else {
                syncSidebarBackdrop();
            }
        });

        // Focus trap for sidebar drawer (a11y)
        let sidebarFocusableElements = [];
        let sidebarFirstFocusable = null;
        let sidebarLastFocusable = null;
        let sidebarPreviouslyFocused = null;

        const updateSidebarFocusableElements = function () {
            sidebarFocusableElements = Array.from(sidebarMenu.querySelectorAll(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
            )).filter(el => el.offsetWidth > 0 || el.offsetHeight > 0);
            sidebarFirstFocusable = sidebarFocusableElements[0];
            sidebarLastFocusable = sidebarFocusableElements[sidebarFocusableElements.length - 1];
        };

        const trapSidebarFocus = function (event) {
            if (!mobileSidebarQuery.matches || !sidebarMenu.classList.contains('show')) return;
            if (event.key !== 'Tab') return;

            updateSidebarFocusableElements();

            if (sidebarFocusableElements.length === 0) return;

            if (event.shiftKey) {
                if (document.activeElement === sidebarFirstFocusable) {
                    event.preventDefault();
                    sidebarLastFocusable?.focus();
                }
            } else {
                if (document.activeElement === sidebarLastFocusable) {
                    event.preventDefault();
                    sidebarFirstFocusable?.focus();
                }
            }
        };

        sidebarMenu.addEventListener('shown.bs.collapse', function () {
            sidebarPreviouslyFocused = document.activeElement;
            updateSidebarFocusableElements();
            setTimeout(() => sidebarFirstFocusable?.focus(), 0);
            document.addEventListener('keydown', trapSidebarFocus);
        });

        sidebarMenu.addEventListener('hidden.bs.collapse', function () {
            document.removeEventListener('keydown', trapSidebarFocus);
            if (sidebarPreviouslyFocused && typeof sidebarPreviouslyFocused.focus === 'function') {
                sidebarPreviouslyFocused.focus();
            }
        });
    }

    // =========================================================================
    // 0.3 Desktop Mini-Rail Sidebar Toggle (Gmail Style)
    // =========================================================================
    const sidebarMiniToggle = document.getElementById('sidebarMiniToggle');
    const isSidebarMiniSaved = (function () {
        try {
            return localStorage.getItem('couriva_sidebar_mini') === '1';
        } catch (e) {
            return false;
        }
    })();

    function updateSidebarToggleLabel(isMini) {
        if (sidebarMiniToggle) {
            sidebarMiniToggle.setAttribute('title', isMini ? 'Expand sidebar' : 'Collapse sidebar');
            sidebarMiniToggle.setAttribute('aria-label', isMini ? 'Expand sidebar' : 'Collapse sidebar');
        }
    }

    if (isSidebarMiniSaved) {
        document.body.classList.add('sidebar-mini');
        updateSidebarToggleLabel(true);
    }

    function toggleSidebarMini() {
        const isMini = document.body.classList.toggle('sidebar-mini');
        try {
            localStorage.setItem('couriva_sidebar_mini', isMini ? '1' : '0');
        } catch (e) {}
        updateSidebarToggleLabel(isMini);
    }

    if (sidebarMiniToggle) {
        sidebarMiniToggle.addEventListener('click', toggleSidebarMini);
    }

    // =========================================================================
    // 0.4 Quick Settings Offcanvas Density Handlers
    // =========================================================================
    const savedDensity = (function () {
        try {
            return localStorage.getItem('couriva_density') || 'default';
        } catch (e) {
            return 'default';
        }
    })();

    const applyDensity = function (density) {
        const mailCol = document.getElementById('mailListCol') || document.querySelector('.mail-list-container');
        if (mailCol) {
            mailCol.classList.remove('density-default', 'density-comfortable', 'density-compact');
            mailCol.classList.add('density-' + density);
        }
        document.querySelectorAll('.density-radio').forEach(function (radio) {
            radio.checked = radio.value === density;
        });
        document.querySelectorAll('.qs-density-card').forEach(function (card) {
            const radio = card.querySelector('.density-radio');
            if (radio && radio.value === density) {
                card.classList.add('selected');
            } else {
                card.classList.remove('selected');
            }
        });
        try {
            localStorage.setItem('couriva_density', density);
        } catch (e) {}
    };

    applyDensity(savedDensity);

    document.querySelectorAll('.density-radio').forEach(function (radio) {
        radio.addEventListener('change', function () {
            if (radio.checked) {
                applyDensity(radio.value);
            }
        });
    });

    document.querySelectorAll('#quickSettingsOffcanvas .theme-select-btn').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const theme = btn.getAttribute('data-theme');
            if (theme && typeof applyTheme === 'function') {
                applyTheme(theme, true);
            }
        });
    });

    // =========================================================================
    // 0.1 Custom Dialog Architecture (CourivaDialog - Mockup Parity)
    // =========================================================================
    window.CourivaDialog = (function () {
        let activeResolve = null;
        let triggerElement = null;

        function getModalEl() {
            let el = document.getElementById('courivaConfirmModal');
            if (!el) {
                const wrapper = document.createElement('div');
                wrapper.innerHTML = `
<div class="modal fade" id="courivaConfirmModal" tabindex="-1" aria-labelledby="courivaConfirmTitle" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-dialog-centered" style="max-width: 440px;">
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
</div>`;
                document.body.appendChild(wrapper.firstElementChild);
                el = document.getElementById('courivaConfirmModal');
            }
            return el;
        }

        function getBsModal() {
            const modalEl = getModalEl();
            if (!modalEl || !window.bootstrap || !window.bootstrap.Modal) return null;
            return window.bootstrap.Modal.getOrCreateInstance(modalEl, {
                backdrop: 'static',
                keyboard: true
            });
        }

        function resetButtons() {
            const submitBtn = document.getElementById('courivaConfirmSubmitBtn');
            const cancelBtn = document.getElementById('courivaConfirmCancelBtn');
            const closeBtn = document.getElementById('courivaConfirmCloseBtn');
            if (submitBtn) {
                submitBtn.disabled = false;
                const sp = submitBtn.querySelector('.spinner-border');
                if (sp) sp.remove();
            }
            if (cancelBtn) cancelBtn.disabled = false;
            if (closeBtn) closeBtn.disabled = false;
        }

        function showDialog(opts) {
            return new Promise(function (resolve) {
                const modal = getBsModal();
                const modalEl = getModalEl();
                if (!modal || !modalEl) {
                    if (opts.type === 'prompt') {
                        resolve(window.prompt(opts.message, opts.defaultValue || ''));
                    } else if (opts.type === 'alert') {
                        window.alert(opts.message);
                        resolve();
                    } else {
                        resolve(window.confirm(opts.message));
                    }
                    return;
                }

                activeResolve = resolve;
                triggerElement = opts.triggerEl || document.activeElement;

                const badgeEl = document.getElementById('courivaConfirmBadge');
                const iconEl = document.getElementById('courivaConfirmIcon');
                const titleEl = document.getElementById('courivaConfirmTitle');
                const messageEl = document.getElementById('courivaConfirmMessage');
                const inputContainer = document.getElementById('courivaConfirmInputContainer');
                const inputEl = document.getElementById('courivaConfirmInput');
                const inputErrorEl = document.getElementById('courivaConfirmInputError');
                const cancelBtn = document.getElementById('courivaConfirmCancelBtn');
                const submitBtn = document.getElementById('courivaConfirmSubmitBtn');
                const submitIcon = document.getElementById('courivaConfirmSubmitIcon');
                const submitText = document.getElementById('courivaConfirmSubmitText');

                resetButtons();

                const isDestructive = opts.isDestructive !== false;
                if (badgeEl) {
                    badgeEl.className = 'couriva-dialog-icon-badge ' + (opts.badgeType || (isDestructive ? 'danger' : 'primary'));
                }
                if (iconEl) {
                    iconEl.className = 'bi ' + (opts.icon || (isDestructive ? 'bi-trash3' : 'bi-exclamation-triangle-fill'));
                }

                if (titleEl) titleEl.textContent = opts.title || 'Confirm';
                if (messageEl) messageEl.textContent = opts.message || '';

                if (opts.type === 'prompt') {
                    if (inputContainer) inputContainer.classList.remove('d-none');
                    if (inputEl) {
                        inputEl.value = opts.defaultValue || '';
                        inputEl.placeholder = opts.placeholder || '';
                    }
                    if (inputErrorEl) {
                        inputErrorEl.textContent = '';
                        inputErrorEl.classList.add('d-none');
                    }
                } else {
                    if (inputContainer) inputContainer.classList.add('d-none');
                }

                if (opts.type === 'alert') {
                    if (cancelBtn) cancelBtn.classList.add('d-none');
                    if (submitBtn) {
                        submitBtn.className = 'btn btn-primary btn-sm px-3 fw-semibold';
                        if (submitIcon) submitIcon.className = 'bi bi-check2';
                        if (submitText) submitText.textContent = opts.confirmText || 'OK';
                    }
                } else {
                    if (cancelBtn) {
                        cancelBtn.classList.remove('d-none');
                        cancelBtn.textContent = opts.cancelText || 'Cancel';
                    }
                    if (submitBtn) {
                        submitBtn.className = 'btn ' + (isDestructive ? 'btn-danger' : 'btn-primary') + ' btn-sm px-3 fw-semibold d-inline-flex align-items-center gap-1';
                        if (submitIcon) {
                            submitIcon.className = 'bi ' + (opts.submitIcon || (isDestructive ? 'bi-trash3' : 'bi-check-lg'));
                            submitIcon.classList.remove('d-none');
                        }
                        if (submitText) submitText.textContent = opts.confirmText || (isDestructive ? 'Delete' : 'Confirm');
                    }
                }

                submitBtn.onclick = function () {
                    if (opts.type === 'prompt') {
                        const val = inputEl ? inputEl.value : '';
                        if (opts.validate && typeof opts.validate === 'function') {
                            const err = opts.validate(val);
                            if (err) {
                                if (inputErrorEl) {
                                    inputErrorEl.textContent = err;
                                    inputErrorEl.classList.remove('d-none');
                                }
                                return;
                            }
                        }
                        submitBtn.disabled = true;
                        if (activeResolve) {
                            const fn = activeResolve;
                            activeResolve = null;
                            fn(val);
                        }
                        modal.hide();
                    } else if (opts.type === 'alert') {
                        if (activeResolve) {
                            const fn = activeResolve;
                            activeResolve = null;
                            fn(true);
                        }
                        modal.hide();
                    } else {
                        submitBtn.disabled = true;
                        const sp = document.createElement('span');
                        sp.className = 'spinner-border spinner-border-sm me-1';
                        sp.setAttribute('role', 'status');
                        sp.setAttribute('aria-hidden', 'true');
                        submitBtn.prepend(sp);

                        if (activeResolve) {
                            const fn = activeResolve;
                            activeResolve = null;
                            fn(true);
                        }
                        modal.hide();
                    }
                };

                cancelBtn.onclick = function () {
                    if (activeResolve) {
                        const fn = activeResolve;
                        activeResolve = null;
                        fn(opts.type === 'prompt' ? null : false);
                    }
                    modal.hide();
                };

                const onHidden = function () {
                    modalEl.removeEventListener('hidden.bs.modal', onHidden);
                    if (activeResolve) {
                        const fn = activeResolve;
                        activeResolve = null;
                        fn(opts.type === 'prompt' ? null : false);
                    }
                    if (triggerElement && typeof triggerElement.focus === 'function') {
                        try { triggerElement.focus(); } catch (_) {}
                    }
                    triggerElement = null;
                    resetButtons();
                };
                modalEl.addEventListener('hidden.bs.modal', onHidden);

                modal.show();

                if (opts.type === 'prompt' && inputEl) {
                    setTimeout(() => inputEl.focus(), 300);
                } else if (submitBtn) {
                    setTimeout(() => submitBtn.focus(), 300);
                }
            });
        }

        function parseConfirmMessage(message, el) {
            const lower = (message || '').toLowerCase();
            let title = 'Confirm action';
            let confirmText = 'Confirm';
            let icon = 'bi-exclamation-triangle';
            let submitIcon = 'bi-check-lg';
            let isDestructive = false;

            if (lower.includes('delete') || lower.includes('trash') || lower.includes('empty') || lower.includes('discard') || lower.includes('cancel') || lower.includes('remove') || lower.includes('block')) {
                isDestructive = true;
                icon = 'bi-trash3';
                submitIcon = 'bi-trash3';
            }

            if (lower.includes('delete') && lower.includes('label')) {
                title = 'Delete label?';
                confirmText = 'Delete label';
            } else if (lower.includes('delete') && lower.includes('folder')) {
                title = 'Delete folder?';
                confirmText = 'Delete folder';
            } else if (lower.includes('permanently delete this user account') || (lower.includes('delete') && lower.includes('user'))) {
                title = 'Delete user account?';
                confirmText = 'Delete account';
            } else if (lower.includes('permanently delete')) {
                title = 'Permanently delete messages?';
                confirmText = 'Delete permanently';
            } else if (lower.includes('empty all items from trash') || lower.includes('empty trash')) {
                title = 'Empty Trash?';
                confirmText = 'Empty Trash';
            } else if (lower.includes('discard this draft') || lower.includes('discard')) {
                title = 'Discard draft?';
                confirmText = 'Discard draft';
            } else if (lower.includes('move this email to trash') || lower.includes('move to trash')) {
                title = 'Move to Trash?';
                confirmText = 'Move to Trash';
            } else if (lower.includes('block')) {
                title = 'Block sender?';
                confirmText = 'Block sender';
                icon = 'bi-shield-slash';
                submitIcon = 'bi-shield-slash';
            } else if (lower.includes('clear all read notifications') || lower.includes('clear')) {
                title = 'Clear notifications?';
                confirmText = 'Clear all';
            } else if (lower.includes('cancel this scheduled message')) {
                title = 'Cancel scheduled message?';
                confirmText = 'Cancel send';
                icon = 'bi-x-circle';
                submitIcon = 'bi-x-circle';
            } else if (lower.includes('remove this schedule entry')) {
                title = 'Remove schedule?';
                confirmText = 'Remove';
            }

            return {
                title: title,
                message: message,
                confirmText: confirmText,
                icon: icon,
                submitIcon: submitIcon,
                isDestructive: isDestructive,
                triggerEl: el
            };
        }

        return {
            confirm: function (options) {
                if (typeof options === 'string') {
                    options = { message: options };
                }
                return showDialog(Object.assign({ type: 'confirm' }, options));
            },
            prompt: function (options) {
                if (typeof options === 'string') {
                    options = { message: options };
                }
                return showDialog(Object.assign({ type: 'prompt', isDestructive: false, icon: 'bi-link-45deg', submitIcon: 'bi-check-lg' }, options));
            },
            alert: function (options) {
                if (typeof options === 'string') {
                    options = { message: options };
                }
                return showDialog(Object.assign({ type: 'alert', isDestructive: false, icon: 'bi-info-circle-fill' }, options));
            },
            confirmFromMessage: function (message, el) {
                const config = parseConfirmMessage(message, el);
                return showDialog(Object.assign({ type: 'confirm' }, config));
            }
        };
    })();

    // 0.2 Confirmation prompts (data-confirm wired to CourivaDialog)
    document.querySelectorAll('[data-confirm]').forEach(function (el) {
        if (el.tagName.toLowerCase() === 'form') {
            el.addEventListener('submit', function (event) {
                if (el.__courivaConfirmed) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                const message = el.getAttribute('data-confirm') || 'Are you sure?';
                CourivaDialog.confirmFromMessage(message, el).then(function (confirmed) {
                    if (confirmed) {
                        el.__courivaConfirmed = true;
                        el.submit();
                    }
                });
            });
        } else {
            el.addEventListener('click', function (event) {
                if (el.__courivaConfirmed) {
                    return;
                }
                event.preventDefault();
                event.stopPropagation();
                const message = el.getAttribute('data-confirm') || 'Are you sure?';
                CourivaDialog.confirmFromMessage(message, el).then(function (confirmed) {
                    if (confirmed) {
                        el.__courivaConfirmed = true;
                        if (el.tagName.toLowerCase() === 'a' && el.href) {
                            window.location.href = el.href;
                        } else {
                            el.click();
                        }
                    }
                });
            });
        }
    });

    document.querySelectorAll('[data-action="print-email"]').forEach(function (button) {
        button.addEventListener('click', function () {
            window.print();
        });
    });

    document.querySelectorAll('[data-action="dismiss-attachment-alert"]').forEach(function (button) {
        button.addEventListener('click', function () {
            const alert = document.getElementById('attachmentAlert');
            if (alert) alert.classList.add('d-none');
        });
    });

    document.querySelectorAll('[data-stop-propagation]').forEach(function (element) {
        element.addEventListener('click', function (event) {
            event.stopPropagation();
        });
    });

    // =========================================================================
    // 1. Theme Switcher (Navbar & Settings)
    // =========================================================================
    const themeToggleBtn = document.getElementById('themeToggleBtn');
    const settingsThemeRadios = document.querySelectorAll('.settings-theme-radio');

    function resolveTheme(preference) {
        if (preference === 'system') {
            return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
        }
        return preference === 'dark' ? 'dark' : 'light';
    }

    function applyTheme(theme, persist = false) {
        const preference = (theme === 'system' || theme === 'dark') ? theme : 'light';
        const resolved = resolveTheme(preference);
        document.documentElement.setAttribute('data-bs-theme', resolved);
        document.documentElement.setAttribute('data-theme-preference', preference);
        try { localStorage.setItem('webmail_theme', preference); } catch (_) { /* Storage may be blocked. */ }

        // Synchronize favicon with resolved active theme
        const faviconEl = document.getElementById('appFavicon');
        if (faviconEl) {
            const faviconFile = resolved === 'dark' ? 'couriva-logo-white.svg' : 'couriva-logo-black.svg';
            faviconEl.setAttribute('href', baseUrl + '/assets/brand/' + faviconFile);
        }

        // Update navbar button icon and text label
        if (themeToggleBtn) {
            const icon = themeToggleBtn.querySelector('svg, i');
            if (icon) {
                const iconName = resolved === 'dark' ? 'moon-stars-fill' : 'sun-fill';
                icon.setAttribute('class', 'bi bi-' + iconName + ' text-warning');
                const use = icon.querySelector('use');
                if (use) use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, '#' + iconName));
            }
            const label = themeToggleBtn.querySelector('#themeToggleLabel') || themeToggleBtn.querySelector('span');
            if (label) {
                label.textContent = resolved === 'dark' ? 'Dark' : 'Light';
            }
            const nextMode = resolved === 'dark' ? 'Light' : 'Dark';
            themeToggleBtn.setAttribute('title', `${resolved === 'dark' ? 'Dark' : 'Light'} active (Click to switch to ${nextMode})`);
            themeToggleBtn.setAttribute('aria-label', `Switch to ${nextMode} theme (Current: ${preference === 'system' ? 'System ' + resolved : resolved})`);
        }

        // Update settings radios if present
        settingsThemeRadios.forEach(radio => {
            radio.checked = (radio.value === preference);
        });

        // Update quick settings buttons if present
        document.querySelectorAll('#quickSettingsOffcanvas .theme-select-btn').forEach(btn => {
            const btnTheme = btn.getAttribute('data-theme');
            const indicator = btn.querySelector('.qs-card-indicator i');
            if (btnTheme === preference) {
                btn.classList.add('selected', 'active');
                if (indicator) indicator.className = 'bi bi-check-circle-fill';
                btn.setAttribute('aria-pressed', 'true');
            } else {
                btn.classList.remove('selected', 'active');
                if (indicator) indicator.className = 'bi bi-circle';
                btn.setAttribute('aria-pressed', 'false');
            }
        });

        // Sync email body iframe colors with active theme and auto-adjust height
        document.querySelectorAll('iframe.email-body-content').forEach(function (iframe) {
            try {
                const doc = iframe.contentDocument || iframe.contentWindow?.document;
                if (doc && doc.body) {
                    doc.documentElement.setAttribute('data-bs-theme', resolved);
                    if (resolved === 'dark') {
                        doc.body.style.backgroundColor = '#111827';
                        doc.body.style.color = '#f8fafc';
                    } else {
                        doc.body.style.backgroundColor = 'transparent';
                        doc.body.style.color = '#0f172a';
                    }
                    const scrollH = doc.body.scrollHeight;
                    if (scrollH > 60) {
                        iframe.style.height = (scrollH + 24) + 'px';
                    }
                }
            } catch (_) {}
        });

        // Persist theme to database via AJAX
        if (persist && csrfToken) {
            fetch(baseUrl + '/public/api/theme.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ theme: preference })
            }).catch(err => console.error('Failed to persist theme:', err));
        }
    }

    // Synchronize initial theme state from localStorage, DOM or default
    let storedTheme = null;
    try { storedTheme = localStorage.getItem('webmail_theme'); } catch (_) { /* Storage may be blocked. */ }
    let initialTheme = storedTheme || document.documentElement.getAttribute('data-theme-preference') || document.documentElement.getAttribute('data-bs-theme') || 'light';
    applyTheme(initialTheme, false);

    if (window.matchMedia) {
        try {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
                const pref = document.documentElement.getAttribute('data-theme-preference');
                if (pref === 'system') {
                    applyTheme('system', false);
                }
            });
        } catch (_) {}
    }

    if (themeToggleBtn) {
        themeToggleBtn.addEventListener('click', function () {
            const currentResolved = document.documentElement.getAttribute('data-bs-theme') || 'light';
            const newTheme = currentResolved === 'dark' ? 'light' : 'dark';
            applyTheme(newTheme, true);
        });
    }

    if (settingsThemeRadios.length > 0) {
        settingsThemeRadios.forEach(radio => {
            radio.addEventListener('change', function () {
                if (this.checked) {
                    applyTheme(this.value, true);
                }
            });
        });
    }

    // =========================================================================
    // 2. Contacts Cache & Recipient Chips Manager (Compose Page)
    // =========================================================================
    let contactsCache = [];

    // Preload address book contacts for typeahead/chips
    if (document.getElementById('composeForm')) {
        fetch(baseUrl + '/public/api/contacts.php')
            .then(res => res.json())
            .then(data => {
                if (Array.isArray(data)) {
                    contactsCache = data;
                }
            })
            .catch(err => console.error('Failed to load contacts for compose:', err));
    }

    class RecipientChips {
        constructor(config) {
            this.container = document.getElementById(config.containerId);
            this.list = document.getElementById(config.listId);
            this.input = document.getElementById(config.inputId);
            this.hidden = document.getElementById(config.hiddenId);
            this.dropdown = document.getElementById(config.dropdownId);
            this.field = config.field;
            this.chips = [];

            if (!this.container || !this.list || !this.input || !this.hidden) return;

            // Load initial values from hidden input
            if (this.hidden.value) {
                const initialList = this.hidden.value.split(',').map(s => s.trim()).filter(Boolean);
                initialList.forEach(email => this.addChip(email, false));
                this.syncHidden();
            }

            this.bindEvents();
        }

        bindEvents() {
            // Typing in text input
            this.input.addEventListener('input', () => this.handleInput());
            this.input.addEventListener('keydown', (e) => this.handleKeyDown(e));
            this.input.addEventListener('blur', () => {
                // Short delay to allow dropdown item clicks
                setTimeout(() => {
                    const val = this.input.value.trim().replace(/,/g, '');
                    if (val && this.isValidEmail(val)) {
                        this.addChip(val);
                        this.input.value = '';
                    }
                    this.hideDropdown();
                }, 200);
            });

            // Focus text input when container is clicked
            this.container.addEventListener('click', (e) => {
                if (!e.target.closest('.chip-badge') && !e.target.closest('button')) {
                    this.input.focus();
                }
            });
        }

        isValidEmail(email) {
            return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email);
        }

        addChip(email, triggerSync = true) {
            email = email.trim();
            if (!email || this.chips.includes(email)) return;

            this.chips.push(email);

            const chipEl = document.createElement('span');
            chipEl.className = 'chip-badge';
            chipEl.innerHTML = `
                <span class="chip-text">${this.escapeHtml(email)}</span>
                <button type="button" class="chip-remove" title="Remove" aria-label="Remove">&times;</button>
            `;

            chipEl.querySelector('.chip-remove').addEventListener('click', (e) => {
                e.stopPropagation();
                this.removeChip(email);
            });

            this.list.appendChild(chipEl);
            if (triggerSync) this.syncHidden();
        }

        removeChip(email) {
            this.chips = this.chips.filter(c => c !== email);
            this.renderList();
            this.syncHidden();
        }

        renderList() {
            this.list.innerHTML = '';
            this.chips.forEach(email => {
                const chipEl = document.createElement('span');
                chipEl.className = 'chip-badge';
                chipEl.innerHTML = `
                    <span class="chip-text">${this.escapeHtml(email)}</span>
                    <button type="button" class="chip-remove" title="Remove" aria-label="Remove">&times;</button>
                `;
                chipEl.querySelector('.chip-remove').addEventListener('click', (e) => {
                    e.stopPropagation();
                    this.removeChip(email);
                });
                this.list.appendChild(chipEl);
            });
        }

        syncHidden() {
            this.hidden.value = this.chips.join(', ');
            // Dispatch input event on hidden input for auto-save detection
            this.hidden.dispatchEvent(new Event('input', { bubbles: true }));
        }

        clear() {
            this.chips = [];
            this.renderList();
            this.syncHidden();
        }

        handleInput() {
            const query = this.input.value.trim().toLowerCase();
            if (!this.dropdown) return;

            if (query.length === 0) {
                this.hideDropdown();
                return;
            }

            const matches = contactsCache.filter(c => 
                (c.name && c.name.toLowerCase().includes(query)) ||
                (c.email && c.email.toLowerCase().includes(query))
            );

            if (matches.length === 0) {
                this.hideDropdown();
                return;
            }

            this.dropdown.innerHTML = '';
            matches.slice(0, 6).forEach(contact => {
                const item = document.createElement('div');
                item.className = 'autocomplete-item';
                item.innerHTML = `
                    <div>
                        <div class="contact-name">${this.escapeHtml(contact.name)}</div>
                        <div class="contact-email">${this.escapeHtml(contact.email)}${contact.company ? ' &bull; ' + this.escapeHtml(contact.company) : ''}</div>
                    </div>
                    <i class="bi bi-plus-circle text-primary"></i>
                `;
                item.addEventListener('click', () => {
                    this.addChip(contact.email);
                    this.input.value = '';
                    this.hideDropdown();
                    this.input.focus();
                });
                this.dropdown.appendChild(item);
            });

            this.dropdown.style.display = 'block';
        }

        handleKeyDown(e) {
            const val = this.input.value.trim();

            if (e.key === 'Enter' || e.key === ',' || e.key === ';' || e.key === 'Tab') {
                if (val) {
                    e.preventDefault();
                    const cleanEmail = val.replace(/[,;]/g, '').trim();
                    if (cleanEmail) {
                        this.addChip(cleanEmail);
                        this.input.value = '';
                        this.hideDropdown();
                    }
                }
            } else if (e.key === 'Backspace' && this.input.value === '' && this.chips.length > 0) {
                // Remove last chip when pressing backspace on empty input
                this.removeChip(this.chips[this.chips.length - 1]);
            } else if (e.key === 'Escape') {
                this.hideDropdown();
            }
        }

        hideDropdown() {
            if (this.dropdown) {
                this.dropdown.style.display = 'none';
                this.dropdown.innerHTML = '';
            }
        }

        escapeHtml(str) {
            return String(str).replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[m]));
        }
    }

    // Initialize recipient chips
    const toChips = new RecipientChips({
        containerId: 'toChipsContainer',
        listId: 'toChipsList',
        inputId: 'toTextInput',
        hiddenId: 'toHiddenInput',
        dropdownId: 'toDropdown',
        field: 'to'
    });

    const ccChips = new RecipientChips({
        containerId: 'ccChipsContainer',
        listId: 'ccChipsList',
        inputId: 'ccTextInput',
        hiddenId: 'ccHiddenInput',
        dropdownId: 'ccDropdown',
        field: 'cc'
    });

    const bccChips = new RecipientChips({
        containerId: 'bccChipsContainer',
        listId: 'bccChipsList',
        inputId: 'bccTextInput',
        hiddenId: 'bccHiddenInput',
        dropdownId: 'bccDropdown',
        field: 'bcc'
    });

    // Collapsible CC and BCC toggles
    const toggleCcBtn = document.getElementById('toggleCcBtn');
    const toggleBccBtn = document.getElementById('toggleBccBtn');
    const closeCcBtn = document.getElementById('closeCcBtn');
    const closeBccBtn = document.getElementById('closeBccBtn');
    const ccRowWrapper = document.getElementById('ccRowWrapper');
    const bccRowWrapper = document.getElementById('bccRowWrapper');

    if (toggleCcBtn && ccRowWrapper) {
        toggleCcBtn.addEventListener('click', () => {
            ccRowWrapper.classList.remove('d-none');
            document.getElementById('ccTextInput')?.focus();
        });
    }

    if (closeCcBtn && ccRowWrapper) {
        closeCcBtn.addEventListener('click', () => {
            ccRowWrapper.classList.add('d-none');
            ccChips.clear();
        });
    }

    if (toggleBccBtn && bccRowWrapper) {
        toggleBccBtn.addEventListener('click', () => {
            bccRowWrapper.classList.remove('d-none');
            document.getElementById('bccTextInput')?.focus();
        });
    }

    if (closeBccBtn && bccRowWrapper) {
        closeBccBtn.addEventListener('click', () => {
            bccRowWrapper.classList.add('d-none');
            bccChips.clear();
        });
    }

    // =========================================================================
    // 3. Rich Text Formatting Toolbar & WYSIWYG (Compose Page)
    // =========================================================================
    const richEditor = document.getElementById('richEditor');
    const bodyInput = document.getElementById('bodyInput');

    if (richEditor && bodyInput) {
        // Sync editor HTML to hidden textarea
        function syncEditorToBody() {
            bodyInput.value = richEditor.innerHTML.trim();
            bodyInput.dispatchEvent(new Event('input', { bubbles: true }));
        }

        richEditor.addEventListener('input', syncEditorToBody);
        richEditor.addEventListener('blur', syncEditorToBody);

        // Unsaved changes protection
        let isComposingDirty = false;
        function updateDirtyStatus() {
            const text = richEditor.textContent.trim();
            isComposingDirty = text.length > 5;
        }
        richEditor.addEventListener('input', updateDirtyStatus);

        window.addEventListener('beforeunload', function (e) {
            if (isComposingDirty) {
                e.preventDefault();
                e.returnValue = '';
            }
        });

        // Ensure synced right before form submit
        const composeForm = document.getElementById('composeForm');
        if (composeForm) {
            composeForm.addEventListener('submit', function () {
                isComposingDirty = false;
                syncEditorToBody();
            });
        }

        const discardBtn = document.getElementById('btnDiscardCompose');
        if (discardBtn) {
            discardBtn.addEventListener('click', function () {
                isComposingDirty = false;
            });
        }

        // Toolbar formatting buttons
        document.querySelectorAll('.editor-btn[data-cmd]').forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                const cmd = this.dataset.cmd;
                const val = this.dataset.val || null;

                richEditor.focus();
                document.execCommand(cmd, false, val);
                syncEditorToBody();
                updateToolbarStates();
            });
        });

        // Insert link button
        const insertLinkBtn = document.getElementById('insertLinkBtn');
        if (insertLinkBtn) {
            insertLinkBtn.addEventListener('click', function (e) {
                e.preventDefault();
                richEditor.focus();
                CourivaDialog.prompt({
                    title: 'Insert link',
                    message: 'Enter the destination URL for this link:',
                    defaultValue: 'https://',
                    placeholder: 'https://example.com',
                    confirmText: 'Insert link',
                    icon: 'bi-link-45deg',
                    isDestructive: false,
                    triggerEl: insertLinkBtn
                }).then(function (url) {
                    if (url && url.trim()) {
                        richEditor.focus();
                        document.execCommand('createLink', false, url.trim());
                        syncEditorToBody();
                    }
                });
            });
        }

        // Update toolbar active states based on selection
        function updateToolbarStates() {
            document.querySelectorAll('.editor-btn[data-cmd]').forEach(btn => {
                const cmd = btn.dataset.cmd;
                try {
                    if (document.queryCommandState(cmd)) {
                        btn.classList.add('active');
                    } else {
                        btn.classList.remove('active');
                    }
                } catch (_) {}
            });
        }

        richEditor.addEventListener('keyup', updateToolbarStates);
        richEditor.addEventListener('mouseup', updateToolbarStates);
    }

    // =========================================================================
    // 4. Drag-and-Drop Attachment Dropzone with Validation (Compose Page)
    // =========================================================================
    const attachmentDropzone = document.getElementById('attachmentDropzone');
    const attachmentInput = document.getElementById('attachmentInput');
    const attachmentPreviews = document.getElementById('attachmentPreviews');
    const attachmentSummary = document.getElementById('attachmentSummary');
    const attachmentAlert = document.getElementById('attachmentAlert');
    const attachmentAlertMsg = document.getElementById('attachmentAlertMsg');

    if (attachmentDropzone && attachmentInput && attachmentPreviews) {
        let stagedFiles = [];
        const MAX_FILE_SIZE = 25 * 1024 * 1024; // 25 MB
        const DISALLOWED_EXTENSIONS = ['exe', 'bat', 'cmd', 'sh', 'php', 'phtml', 'vbs', 'js', 'jar', 'py', 'com', 'scr'];

        function showAlert(msg) {
            if (attachmentAlert && attachmentAlertMsg) {
                attachmentAlertMsg.textContent = msg;
                attachmentAlert.classList.remove('d-none');
            }
        }

        function formatBytes(bytes) {
            if (bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.floor(Math.log(bytes) / Math.log(k));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(1)) + ' ' + sizes[i];
        }

        function getFileIconClass(filename) {
            const ext = filename.split('.').pop().toLowerCase();
            if (['png', 'jpg', 'jpeg', 'gif', 'svg', 'webp'].includes(ext)) return 'bi bi-file-earmark-image text-success';
            if (ext === 'pdf') return 'bi bi-file-earmark-pdf text-danger';
            if (['doc', 'docx'].includes(ext)) return 'bi bi-file-earmark-word text-primary';
            if (['xls', 'xlsx', 'csv'].includes(ext)) return 'bi bi-file-earmark-excel text-success';
            if (['zip', 'tar', 'gz', '7z', 'rar'].includes(ext)) return 'bi bi-file-earmark-zip text-warning';
            return 'bi bi-file-earmark-text text-secondary';
        }

        function syncInputFiles() {
            const dt = new DataTransfer();
            stagedFiles.forEach(file => dt.items.add(file));
            attachmentInput.files = dt.files;
            renderAttachmentPreviews();
        }

        function processFiles(files) {
            let hasError = false;

            Array.from(files).forEach(file => {
                const ext = file.name.split('.').pop().toLowerCase();

                // Check disallowed extensions
                if (DISALLOWED_EXTENSIONS.includes(ext)) {
                    showAlert(`"${file.name}" has a prohibited file type (.${ext}). Executable and script files cannot be attached.`);
                    hasError = true;
                    return;
                }

                // Check file size
                if (file.size > MAX_FILE_SIZE) {
                    showAlert(`"${file.name}" exceeds the maximum attachment limit of 25MB.`);
                    hasError = true;
                    return;
                }

                // Avoid duplicate names if exact same size
                const exists = stagedFiles.some(f => f.name === file.name && f.size === file.size);
                if (!exists) {
                    stagedFiles.push(file);
                }
            });

            if (!hasError && attachmentAlert) {
                attachmentAlert.classList.add('d-none');
            }

            syncInputFiles();
        }

        function renderAttachmentPreviews() {
            attachmentPreviews.innerHTML = '';

            if (stagedFiles.length === 0) {
                if (attachmentSummary) attachmentSummary.classList.add('d-none');
                return;
            }

            let totalSize = 0;

            stagedFiles.forEach((file, index) => {
                totalSize += file.size;

                const card = document.createElement('div');
                card.className = 'attachment-card';
                card.innerHTML = `
                    <i class="${getFileIconClass(file.name)} file-icon"></i>
                    <div class="d-flex flex-column">
                        <span class="file-name"></span>
                        <span class="file-size">${formatBytes(file.size)}</span>
                    </div>
                    <button type="button" class="file-remove-btn" title="Remove attachment">&times;</button>
                `;

                const fileName = card.querySelector('.file-name');
                fileName.textContent = file.name;
                fileName.title = file.name;
                card.querySelector('.file-remove-btn').setAttribute('aria-label', 'Remove attachment ' + file.name);
                card.querySelector('.file-remove-btn').addEventListener('click', (e) => {
                    e.stopPropagation();
                    stagedFiles.splice(index, 1);
                    syncInputFiles();
                });

                attachmentPreviews.appendChild(card);
            });

            if (attachmentSummary) {
                attachmentSummary.textContent = `${stagedFiles.length} file(s) attached (${formatBytes(totalSize)} total)`;
                attachmentSummary.classList.remove('d-none');
            }
        }

        // Dropzone click opens native file picker
        attachmentDropzone.addEventListener('click', (event) => {
            if (event.target !== attachmentInput) attachmentInput.click();
        });

        attachmentInput.addEventListener('change', () => {
            if (attachmentInput.files.length > 0) {
                processFiles(attachmentInput.files);
            }
        });

        // Drag and Drop events
        ['dragenter', 'dragover'].forEach(eventName => {
            attachmentDropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                attachmentDropzone.classList.add('drag-over');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            attachmentDropzone.addEventListener(eventName, (e) => {
                e.preventDefault();
                e.stopPropagation();
                attachmentDropzone.classList.remove('drag-over');
            });
        });

        attachmentDropzone.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length > 0) {
                processFiles(dt.files);
            }
        });
    }

    // =========================================================================
    // 5. Real-time Draft Auto-Save (Compose Page)
    // =========================================================================
    const draftIdInput = document.getElementById('draftId');
    const autosaveStatus = document.getElementById('autosaveStatus');
    const subjectInput = document.getElementById('subjectInput');

    if (draftIdInput && autosaveStatus && subjectInput) {
        let isSaving = false;
        let lastPayload = '';
        let autosaveTimer = null;

        function getDraftData() {
            return {
                draft_id: draftIdInput.value || null,
                subject: subjectInput.value || '',
                body: bodyInput ? bodyInput.value : '',
                to: document.getElementById('toHiddenInput')?.value || '',
                cc: document.getElementById('ccHiddenInput')?.value || '',
                bcc: document.getElementById('bccHiddenInput')?.value || ''
            };
        }

        function executeAutoSave() {
            const data = getDraftData();

            // Only auto-save if user entered some content
            if (!data.subject && !data.body && !data.to) {
                return;
            }

            const currentPayload = JSON.stringify(data);
            if (currentPayload === lastPayload || isSaving) {
                return;
            }

            isSaving = true;
            autosaveStatus.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span>Saving...';

            fetch(baseUrl + '/public/api/autosave_draft.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: currentPayload
            })
            .then(res => res.json())
            .then(resData => {
                isSaving = false;
                if (resData.success) {
                    lastPayload = currentPayload;
                    if (resData.draft_id) {
                        draftIdInput.value = resData.draft_id;
                    }
                    const timeStr = new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
                    autosaveStatus.innerHTML = `<i class="bi bi-check2 text-success me-1"></i>Saved at ${timeStr}`;
                } else {
                    autosaveStatus.innerHTML = '<span class="text-danger">Save failed</span>';
                }
            })
            .catch(err => {
                isSaving = false;
                console.error('Autosave error:', err);
                autosaveStatus.innerHTML = '<span class="text-muted">Offline</span>';
            });
        }

        function scheduleAutoSave() {
            clearTimeout(autosaveTimer);
            autosaveTimer = setTimeout(executeAutoSave, 2500);
        }

        // Watch subject, body, and hidden recipient inputs
        subjectInput.addEventListener('input', scheduleAutoSave);
        if (bodyInput) bodyInput.addEventListener('input', scheduleAutoSave);
        ['toHiddenInput', 'ccHiddenInput', 'bccHiddenInput'].forEach(id => {
            const el = document.getElementById(id);
            if (el) el.addEventListener('input', scheduleAutoSave);
        });

        // Periodic background fallback check every 30s
        setInterval(executeAutoSave, 30000);
    }

    // =========================================================================
    // 6. Floating / Sticky Batch Actions Toolbar
    // =========================================================================
    const selectAllCheckbox = document.getElementById('selectAllEmails');
    const batchToolbar = document.getElementById('batchActionsToolbar');
    const contextualBar = document.getElementById('contextualActionsBar') || document.querySelector('.contextual-actions');
    const selectedCountBadge = document.getElementById('selectedCountBadge');

    function getSelectedEmailIds() {
        const checked = document.querySelectorAll('.email-select-checkbox:checked');
        return Array.from(checked).map(cb => parseInt(cb.value, 10)).filter(id => id > 0);
    }

    function updateBatchActionToolbar() {
        const checked = document.querySelectorAll('.email-select-checkbox:checked');
        const all = document.querySelectorAll('.email-select-checkbox');
        const count = checked.length;

        all.forEach(cb => {
            cb.closest('.mail-item-row')?.classList.toggle('selected', cb.checked);
        });

        if (selectedCountBadge) {
            selectedCountBadge.textContent = count;
        }
        if (batchToolbar) {
            batchToolbar.style.display = count > 0 ? 'inline-flex' : 'none';
        }
        if (contextualBar) {
            if (count > 0) {
                contextualBar.classList.remove('d-none');
                contextualBar.classList.add('d-flex');
            } else {
                contextualBar.classList.remove('d-flex');
                contextualBar.classList.add('d-none');
            }
        }
        if (selectAllCheckbox) {
            if (count === 0) {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = false;
            } else if (count === all.length && all.length > 0) {
                selectAllCheckbox.checked = true;
                selectAllCheckbox.indeterminate = false;
            } else {
                selectAllCheckbox.checked = false;
                selectAllCheckbox.indeterminate = true;
            }
        }
    }

    if (selectAllCheckbox) {
        selectAllCheckbox.addEventListener('change', function () {
            const itemCheckboxes = document.querySelectorAll('.email-select-checkbox');
            itemCheckboxes.forEach(cb => cb.checked = selectAllCheckbox.checked);
            updateBatchActionToolbar();
        });

        document.querySelectorAll('.email-select-checkbox').forEach(cb => {
            cb.addEventListener('change', function () {
                updateBatchActionToolbar();
                if (!this.checked && selectAllCheckbox.checked) {
                    selectAllCheckbox.checked = false;
                }
            });
        });

        // Dropdown selection options (all, none, read, unread, starred, unstarred)
        const selectAllDropdown = document.getElementById('selectAllDropdown');
        document.querySelectorAll('#selectAllDropdownMenu .select-filter-option').forEach(function (optionBtn) {
            optionBtn.addEventListener('click', function () {
                const mode = this.getAttribute('data-select');
                const itemCheckboxes = document.querySelectorAll('.email-select-checkbox');
                itemCheckboxes.forEach(function (cb) {
                    const row = cb.closest('.mail-item-row');
                    if (!row) return;
                    switch (mode) {
                        case 'all':
                            cb.checked = true;
                            break;
                        case 'none':
                            cb.checked = false;
                            break;
                        case 'read':
                            cb.checked = row.classList.contains('read');
                            break;
                        case 'unread':
                            cb.checked = row.classList.contains('unread');
                            break;
                        case 'starred':
                            const starBtn = row.querySelector('.star-toggle-btn');
                            cb.checked = starBtn && starBtn.getAttribute('data-starred') === '1';
                            break;
                        case 'unstarred':
                            const starBtnUn = row.querySelector('.star-toggle-btn');
                            cb.checked = starBtnUn && starBtnUn.getAttribute('data-starred') !== '1';
                            break;
                    }
                });
                if (selectAllCheckbox) {
                    selectAllCheckbox.checked = mode === 'all';
                }
                updateBatchActionToolbar();
            });
        });
    }

    // Shared POST helper for row and batch actions.
    function postBatchAction(payload) {
        return fetch(baseUrl + '/public/api/batch_actions.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': csrfToken
            },
            body: JSON.stringify(payload)
        }).then(function (res) {
            return res.json().catch(function () { return {}; }).then(function (data) {
                if (!res.ok || !data.success) {
                    throw new Error(data.error || 'The action could not be completed.');
                }
                return data;
            });
        });
    }

    function snoozeIsoFromControl(control) {
        const now = new Date();
        let target = null;

        if (control.dataset.hours) {
            target = new Date(now.getTime() + parseInt(control.dataset.hours, 10) * 3600 * 1000);
        } else if (control.dataset.days) {
            target = new Date(now.getTime() + parseInt(control.dataset.days, 10) * 24 * 3600 * 1000);
        } else if (control.dataset.tomorrow) {
            target = new Date(now);
            target.setDate(target.getDate() + 1);
            target.setHours(8, 0, 0, 0);
            if (target.getTime() <= now.getTime()) {
                target.setDate(target.getDate() + 1);
            }
        }

        return target ? target.toISOString() : '';
    }

    function disableBatchControls(disabled) {
        document.querySelectorAll('.batch-action-btn, .batch-snooze-preset, .batch-label-btn').forEach(function (button) {
            button.disabled = disabled;
        });
    }

    // Batch Action Button Handlers
    document.querySelectorAll('.batch-action-btn').forEach(button => {
        button.addEventListener('click', function () {
            const action = this.dataset.action;
            const emailIds = getSelectedEmailIds();

            if (!action || emailIds.length === 0) return;

            const executeBatch = function () {
                disableBatchControls(true);
                postBatchAction({ action: action, email_ids: emailIds })
                .then(function () {
                    window.location.reload();
                })
                .catch(function (err) {
                    CourivaDialog.alert({
                        title: 'Action failed',
                        message: err.message || 'Could not complete this action.',
                        icon: 'bi-exclamation-triangle-fill',
                        isDestructive: true
                    });
                    disableBatchControls(false);
                });
            };

            if (action === 'permanent_delete') {
                CourivaDialog.confirm({
                    title: 'Permanently delete messages?',
                    message: `Are you sure you want to permanently delete ${emailIds.length} message(s)? This cannot be undone.`,
                    confirmText: 'Delete permanently',
                    icon: 'bi-trash3',
                    isDestructive: true,
                    triggerEl: button
                }).then(function (confirmed) {
                    if (confirmed) {
                        executeBatch();
                    }
                });
                return;
            }

            executeBatch();
        });
    });

    // Batch snooze presets
    document.querySelectorAll('.batch-snooze-preset').forEach(button => {
        button.addEventListener('click', function () {
            const emailIds = getSelectedEmailIds();
            const snoozeUntil = snoozeIsoFromControl(this);
            if (emailIds.length === 0 || !snoozeUntil) return;

            disableBatchControls(true);
            postBatchAction({ action: 'snooze', email_ids: emailIds, snooze_until: snoozeUntil })
                .then(function () { window.location.reload(); } || {})
                .catch(function (err) {
                    CourivaDialog.alert({
                        title: 'Snooze failed',
                        message: err.message || 'Could not snooze selected messages.',
                        icon: 'bi-exclamation-triangle-fill',
                        isDestructive: true
                    });
                    disableBatchControls(false);
                });
        });
    });

    // Batch label assignment
    document.querySelectorAll('.batch-label-btn').forEach(button => {
        button.addEventListener('click', function () {
            const emailIds = getSelectedEmailIds();
            const labelId = parseInt(this.dataset.labelId, 10);
            if (emailIds.length === 0 || !labelId) return;

            disableBatchControls(true);
            postBatchAction({ action: 'label', email_ids: emailIds, label_id: labelId })
                .then(function () { window.location.reload(); })
                .catch(function (err) {
                    CourivaDialog.alert({
                        title: 'Label assignment failed',
                        message: err.message || 'Could not apply label.',
                        icon: 'bi-exclamation-triangle-fill',
                        isDestructive: true
                    });
                    disableBatchControls(false);
                });
        });
    });

    // =========================================================================
    // 7. Quick Row Actions & Star Toggle
    // =========================================================================

    // Star / Unstar
    document.querySelectorAll('.star-toggle-btn').forEach(button => {
        button.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            if (this.disabled) return;
            const emailId = this.dataset.emailId;
            const currentStarred = this.dataset.starred === '1';
            const icon = this.querySelector('svg, i');
            const originalTitle = this.title;
            const originalClass = this.className;
            const originalIconClass = icon?.getAttribute('class');
            const use = icon?.querySelector('use');
            const originalHref = use?.getAttribute('href');
            this.disabled = true;

            // Optimistic UI update
            this.dataset.starred = currentStarred ? '0' : '1';
            this.classList.toggle('starred', !currentStarred);
            if (icon) {
                const iconName = currentStarred ? 'star' : 'star-fill';
                icon.setAttribute('class', currentStarred ? 'bi bi-star text-secondary' : 'bi bi-star-fill text-warning');
                const use = icon.querySelector('use');
                if (use) use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, '#' + iconName));
            }
            this.title = currentStarred ? 'Star' : 'Unstar';

            fetch(baseUrl + '/public/api/toggle_star.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    email_id: emailId,
                    is_starred: !currentStarred ? 1 : 0
                })
            }).then(res => {
                if (!res.ok) throw new Error('Star update rejected');
                return res.json();
            }).then(data => {
                if (!data.success) throw new Error('Star update rejected');
                if (typeof window.showGmailSnackbar === 'function') {
                    window.showGmailSnackbar(!currentStarred ? 'Conversation starred' : 'Conversation unstarred');
                }
            }).catch(err => {
                console.error('Failed to update star:', err);
                this.dataset.starred = currentStarred ? '1' : '0';
                this.className = originalClass;
                this.title = originalTitle;
                if (icon && originalIconClass !== null) icon.setAttribute('class', originalIconClass);
                if (use && originalHref !== null) use.setAttribute('href', originalHref);
                CourivaDialog.alert({
                    title: 'Star update failed',
                    message: 'Could not confirm the star change. Refresh to check the current status.',
                    icon: 'bi-exclamation-triangle-fill'
                });
            }).finally(() => { this.disabled = false; });
        });
    });

    // Quick Read/Unread Toggle
    document.querySelectorAll('.quick-read-toggle-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const emailId = parseInt(this.dataset.emailId, 10);
            const isRead = this.dataset.read === '1';
            const newAction = isRead ? 'mark_unread' : 'mark_read';
            const row = this.closest('.mail-item-row');
            if (this.disabled) return;
            this.disabled = true;

            fetch(baseUrl + '/public/api/batch_actions.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    action: newAction,
                    email_ids: [emailId]
                })
            })
            .then(res => {
                if (!res.ok) throw new Error('Action rejected');
                return res.json();
            }).then(data => {
                if (!data.success) throw new Error('Action rejected');
                const nextRead = !isRead;
                this.dataset.read = nextRead ? '1' : '0';
                const nextAction = nextRead ? 'Mark as unread' : 'Mark as read';
                this.title = nextAction;
                this.setAttribute('aria-label', nextAction);
                const icon = this.querySelector('svg, i');
                if (icon) {
                    const iconName = nextRead ? 'envelope' : 'envelope-open';
                    icon.setAttribute('class', 'bi bi-' + iconName);
                    const use = icon.querySelector('use');
                    if (use) use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, '#' + iconName));
                }

                if (row) {
                    row.classList.toggle('unread', !nextRead);
                    row.classList.toggle('read', nextRead);
                    const dot = row.querySelector('.unread-dot');
                    if (dot) dot.classList.toggle('d-none', nextRead);
                }
            }).catch(err => {
                console.error('Failed to toggle read status:', err);
                CourivaDialog.alert({
                    title: 'Status update failed',
                    message: 'Could not confirm read status change. Refresh to check the message status.',
                    icon: 'bi-exclamation-triangle-fill'
                });
            }).finally(() => { this.disabled = false; });
        });
    });

    // Quick Trash / Restore / Perm Delete / Not Spam
    function wireQuickAction(selector, action, confirmPrompt = null) {
        document.querySelectorAll(selector).forEach(btn => {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();

                const emailId = parseInt(this.dataset.emailId, 10);
                const row = this.closest('.mail-item-row');

                const performQuickAction = () => {
                    if (this.disabled) return;
                    this.disabled = true;
                    fetch(baseUrl + '/public/api/batch_actions.php', {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/json',
                            'X-CSRF-Token': csrfToken
                        },
                        body: JSON.stringify({
                            action: action,
                            email_ids: [emailId]
                        })
                    })
                    .then(res => {
                        if (!res.ok) throw new Error('Action rejected');
                        return res.json();
                    })
                    .then(data => {
                        if (!data.success) throw new Error('Action rejected');
                        if (row) {
                            row.style.transition = 'opacity 0.3s ease, height 0.3s ease';
                            row.style.opacity = '0';
                            setTimeout(() => row.remove(), 300);
                        }
                        if (typeof window.showGmailSnackbar === 'function') {
                            const labels = {
                                'trash': 'Conversation moved to Trash',
                                'archive': 'Conversation archived',
                                'restore': 'Conversation restored to Inbox',
                                'not_spam': 'Conversation marked as not spam',
                                'unarchive': 'Conversation moved to Inbox',
                                'unsnooze': 'Conversation unsnoozed'
                            };
                            const undoMap = {
                                'trash': 'restore',
                                'archive': 'unarchive',
                                'restore': 'trash',
                                'unarchive': 'archive'
                            };
                            const reverseAct = undoMap[action];
                            const onUndo = reverseAct ? function () {
                                fetch(baseUrl + '/public/api/batch_actions.php', {
                                    method: 'POST',
                                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken },
                                    body: JSON.stringify({ action: reverseAct, email_ids: [emailId] })
                                }).then(() => window.location.reload());
                            } : null;
                            window.showGmailSnackbar(labels[action] || 'Action completed', onUndo);
                        }
                    })
                    .catch(err => {
                        console.error(`Failed quick action ${action}:`, err);
                        CourivaDialog.alert({
                            title: 'Action failed',
                            message: 'Could not confirm this action. Refresh to check the message status.',
                            icon: 'bi-exclamation-triangle-fill'
                        });
                    })
                    .finally(() => { this.disabled = false; });
                };

                if (confirmPrompt) {
                    CourivaDialog.confirm({
                        title: 'Permanently delete message?',
                        message: confirmPrompt,
                        confirmText: 'Delete permanently',
                        icon: 'bi-trash3',
                        isDestructive: true,
                        triggerEl: btn
                    }).then(function (confirmed) {
                        if (confirmed) {
                            performQuickAction();
                        }
                    });
                    return;
                }

                performQuickAction();
            });
        });
    }

    wireQuickAction('.quick-trash-btn', 'trash');
    wireQuickAction('.quick-restore-btn', 'restore');
    wireQuickAction('.quick-perm-delete-btn', 'permanent_delete', 'Are you sure you want to permanently delete this message?');
    wireQuickAction('.quick-not-spam-btn', 'not_spam');
    wireQuickAction('.quick-archive-btn', 'archive');
    wireQuickAction('.quick-unarchive-btn', 'unarchive');
    wireQuickAction('.quick-unsnooze-btn', 'unsnooze');

    // Quick snooze presets from a single row.
    document.querySelectorAll('.quick-snooze-btn').forEach(btn => {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopPropagation();

            const emailId = parseInt(this.dataset.emailId, 10);
            const snoozeUntil = snoozeIsoFromControl(this);
            const row = this.closest('.mail-item-row');
            if (!emailId || !snoozeUntil || this.disabled) return;

            this.disabled = true;
            postBatchAction({ action: 'snooze', email_ids: [emailId], snooze_until: snoozeUntil })
                .then(function () {
                    if (row) {
                        row.style.transition = 'opacity 0.3s ease, height 0.3s ease';
                        row.style.opacity = '0';
                        setTimeout(() => row.remove(), 300);
                    }
                })
                .catch(function (err) {
                    CourivaDialog.alert({
                        title: 'Snooze failed',
                        message: err.message || 'Could not snooze message.',
                        icon: 'bi-exclamation-triangle-fill',
                        isDestructive: true
                    });
                })
                .finally(() => { this.disabled = false; });
        });
    });

    // =========================================================================
    // 8. Desktop Reading Pane Split View with AJAX Preview
    // =========================================================================
    const viewModeListBtn = document.getElementById('viewModeListBtn');
    const viewModeSplitBtn = document.getElementById('viewModeSplitBtn');
    const mailListCol = document.getElementById('mailListCol');
    const readingPaneCol = document.getElementById('readingPaneCol');
    const readingPaneContent = document.getElementById('readingPaneContent');

    if (viewModeListBtn && viewModeSplitBtn && mailListCol && readingPaneCol) {
        let currentMode = 'list';
        try { currentMode = localStorage.getItem('webmail_view_mode') || 'list'; } catch (_) { /* Storage may be blocked. */ }

        function setViewMode(mode) {
            currentMode = mode;
            try { localStorage.setItem('webmail_view_mode', mode); } catch (_) { /* Storage may be blocked. */ }
            if (typeof updateQsPaneCards === 'function') {
                updateQsPaneCards(mode);
            }

            if (mode === 'split') {
                mailListCol.className = 'col-lg-6 col-12';
                readingPaneCol.classList.remove('d-none');
                viewModeSplitBtn.classList.add('active');
                viewModeListBtn.classList.remove('active');
            } else {
                mailListCol.className = 'col-12';
                readingPaneCol.classList.add('d-none');
                viewModeListBtn.classList.add('active');
                viewModeSplitBtn.classList.remove('active');
                document.querySelectorAll('.mail-item-row.active-preview-row').forEach(r => r.classList.remove('active-preview-row'));
            }
        }

        // Initialize based on saved preference (only on large desktop screens >= 992px)
        if (window.innerWidth >= 992 && currentMode === 'split') {
            setViewMode('split');
        } else {
            setViewMode('list');
        }

        viewModeListBtn.addEventListener('click', () => setViewMode('list'));
        viewModeSplitBtn.addEventListener('click', () => setViewMode('split'));

        // Handle Row Clicks in Split Mode
        document.querySelectorAll('.mail-item-row').forEach(row => {
            row.addEventListener('click', function (e) {
                // If user clicks checkbox or star or quick actions, don't trigger reading pane
                if (e.target.closest('.form-check') || e.target.closest('.star-btn') || e.target.closest('.mail-quick-actions')) {
                    return;
                }

                if (currentMode === 'split') {
                    e.preventDefault();

                    const emailId = this.dataset.emailId;
                    if (!emailId) return;

                    // Highlight active row
                    document.querySelectorAll('.mail-item-row.active-preview-row').forEach(r => r.classList.remove('active-preview-row'));
                    this.classList.add('active-preview-row');

                    // Mark row as read optimistically
                    this.classList.remove('unread');
                    this.classList.add('read');
                    const dot = this.querySelector('.unread-dot');
                    if (dot) dot.classList.add('d-none');

                    // Load email via AJAX
                    loadReadingPaneEmail(emailId);
                }
            });
        });

        function loadReadingPaneEmail(emailId, allowImages = false) {
            if (!readingPaneContent) return;

            readingPaneContent.innerHTML = `
                <div class="py-5 text-center text-muted">
                    <div class="spinner-border text-primary mb-2" role="status"></div>
                    <div>Loading email preview...</div>
                </div>
            `;

            const imageQuery = allowImages ? '&images=1' : '';
            fetch(baseUrl + `/public/api/get_email.php?id=${emailId}${imageQuery}`)
                .then(res => res.json())
                .then(data => {
                    if (data.success && data.email) {
                        renderReadingPane(data.email);
                    } else {
                        readingPaneContent.innerHTML = `
                            <div class="alert alert-danger m-3">
                                <i class="bi bi-exclamation-triangle-fill me-2"></i>
                                ${data.error || 'Failed to load email preview.'}
                            </div>
                        `;
                    }
                })
                .catch(err => {
                    console.error('Reading pane error:', err);
                    readingPaneContent.innerHTML = `
                        <div class="alert alert-danger m-3">
                            <i class="bi bi-exclamation-triangle-fill me-2"></i>
                            A network error occurred while loading the email preview.
                        </div>
                    `;
                });
        }

        function renderReadingPane(email) {
            const senderInitial = (email.sender_first_name || email.sender_email || '?').charAt(0).toUpperCase();
            const senderName = [email.sender_first_name, email.sender_last_name].filter(Boolean).join(' ') || email.sender_email;

            // Recipients badges
            const toBadges = (email.recipients.to || []).map(r => `<span class="badge bg-body-secondary text-body border me-1">${escapeHtml(r.email)}</span>`).join('');
            const ccBadges = (email.recipients.cc || []).map(r => `<span class="badge bg-body-secondary text-body border me-1">cc: ${escapeHtml(r.email)}</span>`).join('');

            // Attachments HTML
            let attachmentsHtml = '';
            if (email.attachments && email.attachments.length > 0) {
                const cards = email.attachments.map(att => `
                    <a href="${baseUrl}/public/download.php?id=${att.id}" class="attachment-pill text-decoration-none">
                        <i class="${att.icon_class || 'bi bi-file-earmark'} fs-5"></i>
                        <div>
                            <div class="fw-semibold text-truncate" style="max-width: 140px;">${escapeHtml(att.filename)}</div>
                            <div class="small text-muted" style="font-size: 0.75rem;">${escapeHtml(att.formatted_size || att.size_display || '')}</div>
                        </div>
                        <i class="bi bi-download text-primary ms-1"></i>
                    </a>
                `).join('');

                attachmentsHtml = `
                    <div class="border-top pt-3 mt-4">
                        <h6 class="small fw-bold text-muted mb-2"><i class="bi bi-paperclip me-1"></i>Attachments (${email.attachments.length})</h6>
                        <div class="d-flex flex-wrap">${cards}</div>
                    </div>
                `;
            }

            // Remote images are blocked until the user explicitly opts in.
            const imagesNotice = email.has_blocked_images ? `
                <div class="alert alert-secondary d-flex flex-wrap align-items-center justify-content-between gap-2 py-2 px-3 mb-2">
                    <span class="small text-muted"><i class="bi bi-shield-lock me-1"></i> Remote images are blocked to protect your privacy.</span>
                    <button type="button" class="btn btn-sm btn-outline-primary load-remote-images-btn" data-email-id="${email.id}">
                        <i class="bi bi-image me-1"></i> Load images
                    </button>
                </div>
            ` : '';

            readingPaneContent.innerHTML = `
                <div class="text-start">
                    <!-- Subject & Actions -->
                    <div class="d-flex justify-content-between align-items-start pb-3 border-bottom mb-3">
                        <h5 class="fw-bold mb-0 text-body flex-grow-1 pe-2">${escapeHtml(email.subject || '(No Subject)')}</h5>
                        <div class="btn-group btn-group-sm">
                            <a href="${baseUrl}/public/compose.php?reply_to=${email.id}" class="btn btn-outline-secondary" title="Reply">
                                <i class="bi bi-reply"></i>
                            </a>
                            <a href="${baseUrl}/public/compose.php?reply_all=${email.id}" class="btn btn-outline-secondary" title="Reply All">
                                <i class="bi bi-reply-all"></i>
                            </a>
                            <a href="${baseUrl}/public/compose.php?forward=${email.id}" class="btn btn-outline-secondary" title="Forward">
                                <i class="bi bi-forward"></i>
                            </a>
                            <a href="${baseUrl}/public/read.php?id=${email.id}" class="btn btn-outline-secondary" title="Full Screen View" target="_blank">
                                <i class="bi bi-box-arrow-up-right"></i>
                            </a>
                        </div>
                    </div>

                    <!-- Sender Info Header -->
                    <div class="d-flex align-items-center gap-3 mb-3">
                        <div class="avatar-circle bg-primary text-white d-flex align-items-center justify-content-center rounded-circle fw-bold shadow-sm" style="width: 44px; height: 44px; font-size: 1.15rem; flex-shrink: 0;">
                            ${senderInitial}
                        </div>
                        <div class="flex-grow-1 overflow-hidden">
                            <div class="d-flex justify-content-between align-items-baseline">
                                <strong class="text-body">${escapeHtml(senderName)}</strong>
                                <span class="small text-muted" title="${escapeHtml(email.formatted_date)}">${escapeHtml(email.relative_time)}</span>
                            </div>
                            <div class="small text-muted text-truncate">&lt;${escapeHtml(email.sender_email)}&gt;</div>
                            <div class="small mt-1">
                                <span class="text-muted me-1">To:</span> ${toBadges || '<span class="text-muted fst-italic">None</span>'}
                                ${ccBadges}
                            </div>
                        </div>
                    </div>

                    <!-- Email Body isolated from application CSS and scripts -->
                    ${imagesNotice}
                    <iframe
                        class="reading-pane-body p-3 bg-body border rounded shadow-sm w-100"
                        title="Email message preview"
                        sandbox="allow-popups allow-popups-to-escape-sandbox"
                        referrerpolicy="no-referrer"
                        style="min-height: 320px; height: 60vh; border: 0;"
                        srcdoc="${escapeHtml(buildEmailDocument(email.sanitized_body_html || email.body_html || '<span class=&quot;text-muted fst-italic&quot;>No message content</span>'))}">
                    </iframe>

                    <!-- Attachments -->
                    ${attachmentsHtml}
                </div>
            `;

            const loadImagesBtn = readingPaneContent.querySelector('.load-remote-images-btn');
            if (loadImagesBtn) {
                loadImagesBtn.addEventListener('click', function () {
                    loadReadingPaneEmail(parseInt(this.dataset.emailId, 10), true);
                });
            }
        }

        function buildEmailDocument(bodyHtml) {
            return '<!doctype html><html><head><meta charset="utf-8"><style>'
                + 'html,body{margin:0;padding:0;background:transparent;color:inherit;font:inherit;line-height:1.65;overflow-wrap:anywhere}'
                + 'img{max-width:100%;height:auto}table{max-width:100%;border-collapse:collapse}'
                + '</style></head><body>' + bodyHtml + '</body></html>';
        }

        function escapeHtml(str) {
            return String(str || '').replace(/[&<>"']/g, m => ({
                '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
            }[m]));
        }
    }

    // =========================================================================
    // 9. Contacts Modals Data Binding (Contacts Page)
    // =========================================================================
    // Phone input mask & validation (PH format: 09XXXXXXXXX)
    function initPhoneMask() {
        document.querySelectorAll('[data-phone-mask]').forEach(function (input) {
            input.addEventListener('input', function () {
                let val = this.value.replace(/\D/g, '');
                if (val.length > 11) val = val.slice(0, 11);
                this.value = val;
                validatePhoneInput(this);
            });
            input.addEventListener('blur', function () {
                validatePhoneInput(this);
            });
        });
    }

    function validatePhoneInput(input) {
        const val = input.value.trim();
        const errorEl = document.getElementById(input.id + 'Error');
        if (!errorEl) return true;

        if (val === '') {
            input.classList.remove('is-invalid');
            errorEl.textContent = '';
            return true;
        }

        const digitsOnly = val.replace(/\D/g, '');
        const valid = /^09\d{9}$/.test(digitsOnly);

        if (valid) {
            input.classList.remove('is-invalid');
            input.classList.add('is-valid');
            errorEl.textContent = '';
            return true;
        } else {
            input.classList.remove('is-valid');
            input.classList.add('is-invalid');
            errorEl.textContent = 'Phone must be 11 digits starting with 09 (e.g., 09XXXXXXXXX).';
            return false;
        }
    }

    // Initialize phone mask on page load
    initPhoneMask();

    document.querySelectorAll('.edit-contact-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const name = this.dataset.name || '';
            const email = this.dataset.email || '';
            const phone = this.dataset.phone || '';
            const company = this.dataset.company || '';
            const notes = this.dataset.notes || '';

            document.getElementById('editContactId').value = id;
            document.getElementById('editContactName').value = name;
            document.getElementById('editContactEmail').value = email;
            document.getElementById('editContactPhone').value = phone;
            document.getElementById('editContactCompany').value = company;
            document.getElementById('editContactNotes').value = notes;

            const modalEl = document.getElementById('editContactModal');
            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    });

    document.querySelectorAll('.delete-contact-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const name = this.dataset.name || '';

            document.getElementById('deleteContactId').value = id;
            document.getElementById('deleteContactName').textContent = name;

            const modalEl = document.getElementById('deleteContactModal');
            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    });

    // =========================================================================
    // 10. Settings Page: Live Signature Preview & Password Strength Meter
    // =========================================================================
    const signatureInput = document.getElementById('signatureInput');
    const signaturePreview = document.getElementById('signaturePreview');

    if (signatureInput && signaturePreview) {
        signatureInput.addEventListener('input', function () {
            const val = this.value.trim();
            if (val) {
                signaturePreview.innerHTML = val.replace(/[&<>"']/g, m => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'
                }[m])).replace(/\n/g, '<br>');
            } else {
                signaturePreview.innerHTML = '<span class="text-muted fst-italic">No signature defined yet.</span>';
            }
        });
    }

    const newPasswordInput = document.getElementById('newPasswordInput');
    const confirmPasswordInput = document.getElementById('confirmPasswordInput');
    const passwordStrengthBar = document.getElementById('passwordStrengthBar');
    const passwordStrengthLabel = document.getElementById('passwordStrengthLabel');
    const passwordMatchLabel = document.getElementById('passwordMatchLabel');
    const ruleLength = document.getElementById('ruleLength');
    const ruleUpper = document.getElementById('ruleUpper');
    const ruleNumber = document.getElementById('ruleNumber');
    const ruleSpecial = document.getElementById('ruleSpecial');

    if (newPasswordInput && passwordStrengthBar) {
        newPasswordInput.addEventListener('input', function () {
            const pass = this.value;

            const hasLength = pass.length >= 8;
            const hasUpper = /[A-Z]/.test(pass);
            const hasNumber = /[0-9]/.test(pass);
            const hasSpecial = /[^A-Za-z0-9]/.test(pass);

            // Update rule pills
            function toggleRulePill(el, condition) {
                if (!el) return;
                if (condition) {
                    el.className = 'badge bg-success-subtle text-success border border-success-subtle rule-pill';
                } else {
                    el.className = 'badge bg-secondary-subtle text-secondary border rule-pill';
                }
            }

            toggleRulePill(ruleLength, hasLength);
            toggleRulePill(ruleUpper, hasUpper);
            toggleRulePill(ruleNumber, hasNumber);
            toggleRulePill(ruleSpecial, hasSpecial);

            let score = 0;
            if (hasLength) score++;
            if (hasUpper) score++;
            if (hasNumber) score++;
            if (hasSpecial) score++;

            let percent = 0;
            let barClass = 'progress-bar bg-danger';
            let labelText = 'Weak';

            if (pass.length === 0) {
                percent = 0;
                labelText = 'Enter password';
            } else if (score === 1) {
                percent = 25;
                barClass = 'progress-bar bg-danger';
                labelText = 'Weak';
            } else if (score === 2) {
                percent = 50;
                barClass = 'progress-bar bg-warning';
                labelText = 'Fair';
            } else if (score === 3) {
                percent = 75;
                barClass = 'progress-bar bg-info';
                labelText = 'Good';
            } else if (score === 4) {
                percent = 100;
                barClass = 'progress-bar bg-success';
                labelText = 'Strong';
            }

            passwordStrengthBar.style.width = percent + '%';
            passwordStrengthBar.className = barClass;
            if (passwordStrengthLabel) {
                passwordStrengthLabel.textContent = 'Strength: ' + labelText;
            }

            checkPasswordMatch();
        });
    }

    if (confirmPasswordInput) {
        confirmPasswordInput.addEventListener('input', checkPasswordMatch);
    }

    function checkPasswordMatch() {
        if (!confirmPasswordInput || !newPasswordInput || !passwordMatchLabel) return;

        const newPass = newPasswordInput.value;
        const confirmPass = confirmPasswordInput.value;

        if (confirmPass.length === 0) {
            passwordMatchLabel.textContent = '';
            passwordMatchLabel.className = 'small mt-1';
        } else if (newPass === confirmPass) {
            passwordMatchLabel.innerHTML = '<i class="bi bi-check-circle-fill text-success me-1"></i> Passwords match';
            passwordMatchLabel.className = 'small mt-1 text-success';
        } else {
            passwordMatchLabel.innerHTML = '<i class="bi bi-x-circle-fill text-danger me-1"></i> Passwords do not match';
            passwordMatchLabel.className = 'small mt-1 text-danger';
        }
    }

    // =========================================================================
    // 11. Admin User Management Modal Data Binding (admin/users.php)
    // =========================================================================
    document.querySelectorAll('.edit-user-modal-btn').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = this.dataset.id;
            const email = this.dataset.email || '';
            const firstName = this.dataset.firstname || '';
            const lastName = this.dataset.lastname || '';
            const role = this.dataset.role || 'user';
            const status = this.dataset.status || 'active';
            const timezone = this.dataset.timezone || 'UTC';

            const modalEditUserId = document.getElementById('modalEditUserId');
            const modalEditUserEmail = document.getElementById('modalEditUserEmail');
            const modalEditUserFirstName = document.getElementById('modalEditUserFirstName');
            const modalEditUserLastName = document.getElementById('modalEditUserLastName');
            const modalEditUserRole = document.getElementById('modalEditUserRole');
            const modalEditUserStatus = document.getElementById('modalEditUserStatus');
            const modalEditUserTimezone = document.getElementById('modalEditUserTimezone');

            if (modalEditUserId) modalEditUserId.value = id;
            if (modalEditUserEmail) modalEditUserEmail.value = email;
            if (modalEditUserFirstName) modalEditUserFirstName.value = firstName;
            if (modalEditUserLastName) modalEditUserLastName.value = lastName;
            if (modalEditUserRole) modalEditUserRole.value = role;
            if (modalEditUserStatus) modalEditUserStatus.value = status;
            if (modalEditUserTimezone) modalEditUserTimezone.value = timezone;

            const modalEl = document.getElementById('editUserModal');
            if (modalEl && typeof bootstrap !== 'undefined' && bootstrap.Modal) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }
        });
    });

    // 12. Compose Scheduled Send Validation (public/compose.php)
    const composeFormEl = document.getElementById('composeForm');
    const scheduledAtInput = document.getElementById('scheduledAtInput');
    if (composeFormEl && scheduledAtInput) {
        composeFormEl.addEventListener('submit', function (event) {
            const submitter = event.submitter;
            if (submitter && submitter.value === 'schedule' && !scheduledAtInput.value) {
                event.preventDefault();
                scheduledAtInput.setCustomValidity('Choose a date and time for the scheduled message.');
                scheduledAtInput.reportValidity();
            }
        });
        scheduledAtInput.addEventListener('input', function () {
            scheduledAtInput.setCustomValidity('');
        });
        if (scheduledAtInput.value) {
            const selected = new Date(scheduledAtInput.value);
            if (!Number.isNaN(selected.getTime()) && selected.getTime() < Date.now()) {
                scheduledAtInput.setCustomValidity('Choose a time in the future.');
            }
        }
    }

    // 13. Admin Registration Reject Modal Binding (admin/registrations.php)
    const rejectModalEl = document.getElementById('rejectModal');
    if (rejectModalEl) {
        rejectModalEl.addEventListener('show.bs.modal', function (event) {
            const trigger = event.relatedTarget;
            if (!trigger) return;
            const userIdInput = document.getElementById('rejectUserId');
            const userEmailEl = document.getElementById('rejectUserEmail');
            if (userIdInput) userIdInput.value = trigger.getAttribute('data-user-id') || '';
            if (userEmailEl) userEmailEl.textContent = trigger.getAttribute('data-user-email') || '';
        });
    }

    // =========================================================================
    // 14. Gmail-Style Floating Docked Compose State Engine
    // =========================================================================
    const dockedComposeContainer = document.getElementById('dockedComposeContainer');
    const btnOpenCompose = document.getElementById('btnOpenCompose');

    if (dockedComposeContainer) {
        const minimizeBtn = dockedComposeContainer.querySelector('.compose-minimize-btn');
        const maximizeBtn = dockedComposeContainer.querySelector('.compose-maximize-btn');
        const closeBtn = dockedComposeContainer.querySelector('.compose-close-btn');
        const dockedTitle = document.getElementById('dockedComposeTitle');
        const dockedTo = document.getElementById('dockedTo');
        const dockedSubject = document.getElementById('dockedSubject');
        const dockedBody = document.getElementById('dockedBody');
        const dockedDraftId = document.getElementById('dockedDraftId');
        const dockedSaveStatus = document.getElementById('dockedSaveStatus');
        const btnDockedDiscard = document.getElementById('btnDockedDiscard');

        const openDockedCompose = function (draftData = null) {
            dockedComposeContainer.classList.remove('d-none');
            dockedComposeContainer.classList.remove('docked-minimized');
            if (draftData) {
                if (dockedDraftId) dockedDraftId.value = draftData.id || '';
                if (dockedTo) dockedTo.value = draftData.to || '';
                if (dockedSubject) dockedSubject.value = draftData.subject || '';
                if (dockedBody) dockedBody.value = draftData.body || '';
                if (dockedTitle) dockedTitle.textContent = draftData.subject || 'Edit Draft';
            } else {
                if (dockedTitle) dockedTitle.textContent = 'New Message';
            }
            if (dockedTo && !dockedTo.value) {
                dockedTo.focus();
            } else if (dockedBody) {
                dockedBody.focus();
            }
        };

        const closeDockedCompose = function () {
            dockedComposeContainer.classList.add('d-none');
            dockedComposeContainer.classList.remove('docked-fullscreen', 'docked-minimized');
        };

        if (btnOpenCompose && !window.location.pathname.endsWith('/compose.php')) {
            btnOpenCompose.addEventListener('click', function (e) {
                e.preventDefault();
                openDockedCompose();
            });
        }

        if (minimizeBtn) {
            minimizeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                dockedComposeContainer.classList.toggle('docked-minimized');
                dockedComposeContainer.classList.remove('docked-fullscreen');
            });
        }

        if (maximizeBtn) {
            maximizeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                dockedComposeContainer.classList.toggle('docked-fullscreen');
                dockedComposeContainer.classList.remove('docked-minimized');
            });
        }

        if (closeBtn) {
            closeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                closeDockedCompose();
            });
        }

        if (btnDockedDiscard) {
            btnDockedDiscard.addEventListener('click', function () {
                if (dockedTo) dockedTo.value = '';
                if (dockedSubject) dockedSubject.value = '';
                if (dockedBody) dockedBody.value = '';
                if (dockedDraftId) dockedDraftId.value = '';
                closeDockedCompose();
            });
        }

        // Live draft autosave for docked composer
        let dockedAutosaveTimer = null;
        const triggerDockedAutosave = function () {
            if (!dockedTo || !dockedSubject || !dockedBody) return;
            const toVal = dockedTo.value.trim();
            const subjectVal = dockedSubject.value.trim();
            const bodyVal = dockedBody.value.trim();

            if (!toVal && !subjectVal && !bodyVal) return;

            if (dockedSaveStatus) dockedSaveStatus.textContent = 'Saving...';

            clearTimeout(dockedAutosaveTimer);
            dockedAutosaveTimer = setTimeout(function () {
                fetch(baseUrl + '/public/api/autosave_draft.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': csrfToken
                    },
                    body: JSON.stringify({
                        draft_id: dockedDraftId ? dockedDraftId.value : '',
                        to: toVal,
                        subject: subjectVal,
                        body: bodyVal
                    })
                }).then(function (res) { return res.json(); })
                  .then(function (data) {
                      if (data.success && data.draft_id && dockedDraftId) {
                          dockedDraftId.value = data.draft_id;
                          if (dockedSaveStatus) dockedSaveStatus.textContent = 'Saved';
                      }
                  }).catch(function () {
                      if (dockedSaveStatus) dockedSaveStatus.textContent = 'Offline';
                  });
            }, 1500);
        };

        [dockedTo, dockedSubject, dockedBody].forEach(function (el) {
            if (el) el.addEventListener('input', triggerDockedAutosave);
        });

        // Focus trap and backdrop click-to-close for docked compose on mobile (a11y)
        let dockedFocusableElements = [];
        let dockedFirstFocusable = null;
        let dockedLastFocusable = null;
        let dockedPreviouslyFocused = null;

        const updateDockedFocusableElements = function () {
            dockedFocusableElements = Array.from(dockedComposeContainer.querySelectorAll(
                'a[href], button:not([disabled]), textarea, input, select, [tabindex]:not([tabindex="-1"])'
            )).filter(el => el.offsetWidth > 0 || el.offsetHeight > 0);
            dockedFirstFocusable = dockedFocusableElements[0];
            dockedLastFocusable = dockedFocusableElements[dockedFocusableElements.length - 1];
        };

        const trapDockedFocus = function (event) {
            const isMobile = window.matchMedia('(max-width: 767.98px)').matches;
            if (!isMobile || !dockedComposeContainer.classList.contains('docked-fullscreen')) return;
            if (event.key !== 'Tab') return;

            updateDockedFocusableElements();

            if (dockedFocusableElements.length === 0) return;

            if (event.shiftKey) {
                if (document.activeElement === dockedFirstFocusable) {
                    event.preventDefault();
                    dockedLastFocusable?.focus();
                }
            } else {
                if (document.activeElement === dockedLastFocusable) {
                    event.preventDefault();
                    dockedFirstFocusable?.focus();
                }
            }
        };

        const handleBackdropClick = function (event) {
            const isMobile = window.matchMedia('(max-width: 767.98px)').matches;
            if (!isMobile || !dockedComposeContainer.classList.contains('docked-fullscreen')) return;
            if (!dockedComposeContainer.contains(event.target)) {
                closeDockedCompose();
            }
        };

        // Use a MutationObserver to detect when docked compose enters fullscreen mode
        const dockedObserver = new MutationObserver(function () {
            const isFullscreen = dockedComposeContainer.classList.contains('docked-fullscreen');
            const isMobile = window.matchMedia('(max-width: 767.98px)').matches;

            if (isFullscreen && isMobile) {
                dockedPreviouslyFocused = document.activeElement;
                updateDockedFocusableElements();
                setTimeout(() => dockedFirstFocusable?.focus(), 0);
                document.addEventListener('keydown', trapDockedFocus);
                document.addEventListener('click', handleBackdropClick);
            } else {
                document.removeEventListener('keydown', trapDockedFocus);
                document.removeEventListener('click', handleBackdropClick);
                if (dockedPreviouslyFocused && typeof dockedPreviouslyFocused.focus === 'function') {
                    dockedPreviouslyFocused.focus();
                }
            }
        });

        dockedObserver.observe(dockedComposeContainer, {
            attributes: true,
            attributeFilter: ['class']
        });

        // Also handle ESC key to close fullscreen on mobile
        document.addEventListener('keydown', function (event) {
            const isMobile = window.matchMedia('(max-width: 767.98px)').matches;
            if (event.key === 'Escape' && isMobile && dockedComposeContainer.classList.contains('docked-fullscreen')) {
                closeDockedCompose();
            }
        });
    }

    // =========================================================================
    // 15. Conversation Thread Accordion Expansion
    // =========================================================================
    document.querySelectorAll('.thread-message-card .thread-card-header').forEach(function (header) {
        header.addEventListener('click', function () {
            const card = this.closest('.thread-message-card');
            if (card) {
                card.classList.toggle('collapsed');
            }
        });
    });

    // =========================================================================
    // 16. Gmail SnackBar with Undo
    // =========================================================================
    window.showGmailSnackbar = function (message, onUndo = null) {
        const snackbar = document.getElementById('gmailSnackbar');
        const msgEl = document.getElementById('snackbarMessage');
        const undoBtn = document.getElementById('snackbarUndoBtn');
        if (!snackbar || !msgEl) return;

        msgEl.textContent = message;
        if (onUndo && undoBtn) {
            undoBtn.classList.remove('d-none');
            undoBtn.onclick = function (e) {
                e.preventDefault();
                snackbar.classList.add('d-none');
                onUndo();
            };
        } else if (undoBtn) {
            undoBtn.classList.add('d-none');
        }

        snackbar.classList.remove('d-none');
        clearTimeout(window._snackbarTimer);
        window._snackbarTimer = setTimeout(function () {
            snackbar.classList.add('d-none');
        }, 6000);
    };

    const snackbarCloseBtn = document.getElementById('snackbarCloseBtn');
    if (snackbarCloseBtn) {
        snackbarCloseBtn.addEventListener('click', function () {
            const snackbar = document.getElementById('gmailSnackbar');
            if (snackbar) snackbar.classList.add('d-none');
        });
    }

    // =========================================================================
    // 17. Gmail-Style Keyboard Navigation & Action Shortcuts
    // =========================================================================
    let currentKeyboardIndex = -1;

    document.addEventListener('keydown', function (e) {
        // Ignore if user is currently focused on an input, textarea, select, or contenteditable
        const activeTag = document.activeElement ? document.activeElement.tagName.toLowerCase() : '';
        const isEditable = document.activeElement && (document.activeElement.isContentEditable || ['input', 'textarea', 'select'].includes(activeTag));
        if (isEditable) return;

        // Ignore if any modal is currently shown (except opening/closing shortcuts modal)
        const openModal = document.querySelector('.modal.show');
        if (openModal && openModal.id !== 'keyboardShortcutsModal') return;

        const shortcutsModalEl = document.getElementById('keyboardShortcutsModal');
        const searchBar = document.getElementById('gmailSearchBar');
        const mailItems = Array.from(document.querySelectorAll('.mail-list-container .mail-item'));

        // ? : Show shortcuts modal
        if (e.key === '?') {
            e.preventDefault();
            if (shortcutsModalEl && typeof bootstrap !== 'undefined') {
                const modalInstance = bootstrap.Modal.getOrCreateInstance(shortcutsModalEl);
                modalInstance.show();
            }
            return;
        }

        // / : Focus search bar
        if (e.key === '/') {
            if (searchBar) {
                e.preventDefault();
                searchBar.focus();
                searchBar.select();
            }
            return;
        }

        // c : Open Compose
        if (e.key === 'c' || e.key === 'C') {
            const composeBtn = document.getElementById('btnOpenCompose');
            if (composeBtn) {
                e.preventDefault();
                composeBtn.click();
            }
            return;
        }

        // List navigation shortcuts (j / k / o / Enter / s / e / #)
        if (mailItems.length > 0) {
            // j : Next email in list
            if (e.key === 'j') {
                e.preventDefault();
                if (currentKeyboardIndex < mailItems.length - 1) {
                    currentKeyboardIndex++;
                } else {
                    currentKeyboardIndex = 0;
                }
                updateKeyboardActiveItem(mailItems);
                return;
            }

            // k : Previous email in list
            if (e.key === 'k') {
                e.preventDefault();
                if (currentKeyboardIndex > 0) {
                    currentKeyboardIndex--;
                } else {
                    currentKeyboardIndex = mailItems.length - 1;
                }
                updateKeyboardActiveItem(mailItems);
                return;
            }

            // o or Enter : Open highlighted email
            if (e.key === 'o' || e.key === 'Enter') {
                if (currentKeyboardIndex >= 0 && currentKeyboardIndex < mailItems.length) {
                    const item = mailItems[currentKeyboardIndex];
                    const link = item.querySelector('.mail-item-link') || item.querySelector('a');
                    if (link) {
                        e.preventDefault();
                        link.click();
                    }
                }
                return;
            }

            // s : Star / unstar highlighted email
            if (e.key === 's') {
                if (currentKeyboardIndex >= 0 && currentKeyboardIndex < mailItems.length) {
                    const item = mailItems[currentKeyboardIndex];
                    const starBtn = item.querySelector('.star-toggle-btn');
                    if (starBtn) {
                        e.preventDefault();
                        starBtn.click();
                    }
                }
                return;
            }

            // e : Archive selected or highlighted email
            if (e.key === 'e') {
                const batchArchiveBtn = document.querySelector('[data-batch-action="archive"]');
                if (batchArchiveBtn) {
                    e.preventDefault();
                    batchArchiveBtn.click();
                }
                return;
            }

            // # : Delete / Trash selected email
            if (e.key === '#') {
                const batchTrashBtn = document.querySelector('[data-batch-action="trash"]');
                if (batchTrashBtn) {
                    e.preventDefault();
                    batchTrashBtn.click();
                }
                return;
            }
        }
    });

    function updateKeyboardActiveItem(items) {
        items.forEach(function (el, idx) {
            if (idx === currentKeyboardIndex) {
                el.classList.add('keyboard-active');
                el.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            } else {
                el.classList.remove('keyboard-active');
            }
        });
    }

    // =========================================================================
    // Material 3 Category Tabs Controller
    // =========================================================================
    const categoryTabsContainer = document.getElementById('inboxCategoryTabs');
    if (categoryTabsContainer) {
        const categoryTabs = categoryTabsContainer.querySelectorAll('.category-tab-item');
        categoryTabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                categoryTabs.forEach(t => {
                    t.classList.remove('active');
                    t.setAttribute('aria-selected', 'false');
                });
                this.classList.add('active');
                this.setAttribute('aria-selected', 'true');
            });
        });
    }

    // =========================================================================
    // Quick Settings Reading Pane & AJAX Organization Modals (Mockup Parity)
    // =========================================================================
    function updateQsPaneCards(mode) {
        const noneBtn = document.getElementById('qsPaneNoneBtn');
        const rightBtn = document.getElementById('qsPaneRightBtn');
        if (!noneBtn || !rightBtn) return;
        if (mode === 'split') {
            rightBtn.classList.add('selected');
            noneBtn.classList.remove('selected');
            noneBtn.querySelector('.qs-selected-badge')?.remove();
            if (!rightBtn.querySelector('.qs-selected-badge')) {
                const b = document.createElement('span');
                b.className = 'badge bg-success-subtle text-success border border-success-subtle px-2 py-1 qs-selected-badge';
                b.textContent = 'Selected';
                rightBtn.appendChild(b);
            }
        } else {
            noneBtn.classList.add('selected');
            rightBtn.classList.remove('selected');
            rightBtn.querySelector('.qs-selected-badge')?.remove();
            if (!noneBtn.querySelector('.qs-selected-badge')) {
                const b = document.createElement('span');
                b.className = 'badge bg-success-subtle text-success border border-success-subtle px-2 py-1 qs-selected-badge';
                b.textContent = 'Selected';
                noneBtn.appendChild(b);
            }
        }
    }

    const qsPaneNoneBtn = document.getElementById('qsPaneNoneBtn');
    const qsPaneRightBtn = document.getElementById('qsPaneRightBtn');
    if (qsPaneNoneBtn) {
        qsPaneNoneBtn.addEventListener('click', function () {
            if (typeof setViewMode === 'function') {
                setViewMode('list');
            } else {
                try { localStorage.setItem('webmail_view_mode', 'list'); } catch (_) {}
                updateQsPaneCards('list');
            }
        });
    }
    if (qsPaneRightBtn) {
        qsPaneRightBtn.addEventListener('click', function () {
            if (typeof setViewMode === 'function') {
                setViewMode('split');
            } else {
                try { localStorage.setItem('webmail_view_mode', 'split'); } catch (_) {}
                updateQsPaneCards('split');
            }
        });
    }
    const savedViewMode = (function () {
        try { return localStorage.getItem('webmail_view_mode') || 'list'; } catch (_) { return 'list'; }
    })();
    updateQsPaneCards(savedViewMode);

    // Sidebar & Modal AJAX Organization Handlers
    const createLabelForm = document.getElementById('createLabelForm');
    const createFolderForm = document.getElementById('createFolderForm');

    if (createLabelForm) {
        createLabelForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const alertEl = document.getElementById('createLabelAlert');
            const submitBtn = document.getElementById('btnSubmitCreateLabel');
            const nameInput = document.getElementById('createLabelNameInput');
            const colorInput = createLabelForm.querySelector('input[name="label_color"]:checked');
            const name = (nameInput?.value || '').trim();
            const color = colorInput?.value || '#6366f1';

            if (!name) {
                if (alertEl) {
                    alertEl.textContent = 'Please provide a label name.';
                    alertEl.classList.remove('d-none');
                }
                return;
            }

            if (submitBtn) submitBtn.disabled = true;
            if (alertEl) alertEl.classList.add('d-none');

            fetch(baseUrl + '/public/api/organization.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    action: 'create_label',
                    name: name,
                    color: color
                })
            })
            .then(res => res.json().then(data => ({ status: res.status, ok: res.ok, data })))
            .then(result => {
                if (!result.ok || !result.data.success) {
                    throw new Error(result.data.error || 'Failed to create label.');
                }
                const modalEl = document.getElementById('createLabelModal');
                const modal = bootstrap.Modal.getInstance(modalEl) || (bootstrap.Modal.getOrCreateInstance ? bootstrap.Modal.getOrCreateInstance(modalEl) : null);
                if (modal) modal.hide();
                createLabelForm.reset();

                const sidebarGroup = document.querySelector('.sidebar-labels-group');
                if (sidebarGroup) {
                    const emptyNote = sidebarGroup.querySelector('.sidebar-muted-note');
                    if (emptyNote) emptyNote.remove();

                    const newLink = document.createElement('a');
                    newLink.className = 'webmail-nav-link';
                    newLink.href = result.data.item.url;
                    newLink.title = result.data.item.name;
                    newLink.innerHTML = `
                        <span class="label-dot nav-icon" style="background-color: ${result.data.item.color};"></span>
                        <span class="nav-text text-truncate">${result.data.item.name}</span>
                    `;
                    sidebarGroup.appendChild(newLink);
                }

                if (document.getElementById('organization')) {
                    window.location.reload();
                } else if (typeof showGmailSnackbar === 'function') {
                    showGmailSnackbar('Label "' + result.data.item.name + '" created.');
                }
            })
            .catch(err => {
                if (alertEl) {
                    alertEl.textContent = err.message || 'An error occurred.';
                    alertEl.classList.remove('d-none');
                }
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }

    if (createFolderForm) {
        createFolderForm.addEventListener('submit', function (e) {
            e.preventDefault();
            const alertEl = document.getElementById('createFolderAlert');
            const submitBtn = document.getElementById('btnSubmitCreateFolder');
            const nameInput = document.getElementById('createFolderNameInput');
            const colorInput = createFolderForm.querySelector('input[name="folder_color"]:checked');
            const name = (nameInput?.value || '').trim();
            const color = colorInput?.value || '#64748b';

            if (!name) {
                if (alertEl) {
                    alertEl.textContent = 'Please provide a folder name.';
                    alertEl.classList.remove('d-none');
                }
                return;
            }

            if (submitBtn) submitBtn.disabled = true;
            if (alertEl) alertEl.classList.add('d-none');

            fetch(baseUrl + '/public/api/organization.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({
                    action: 'create_folder',
                    name: name,
                    color: color
                })
            })
            .then(res => res.json().then(data => ({ status: res.status, ok: res.ok, data })))
            .then(result => {
                if (!result.ok || !result.data.success) {
                    throw new Error(result.data.error || 'Failed to create folder.');
                }
                const modalEl = document.getElementById('createFolderModal');
                const modal = bootstrap.Modal.getInstance(modalEl) || (bootstrap.Modal.getOrCreateInstance ? bootstrap.Modal.getOrCreateInstance(modalEl) : null);
                if (modal) modal.hide();
                createFolderForm.reset();

                const sidebarGroup = document.querySelector('.sidebar-folders-group');
                if (sidebarGroup) {
                    const emptyAction = sidebarGroup.querySelector('.sidebar-empty-action');
                    if (emptyAction) emptyAction.remove();

                    const newLink = document.createElement('a');
                    newLink.className = 'webmail-nav-link';
                    newLink.href = result.data.item.url;
                    newLink.title = result.data.item.name;
                    newLink.innerHTML = `
                        <i class="bi bi-folder2 nav-icon" style="color: ${result.data.item.color};"></i>
                        <span class="nav-text text-truncate">${result.data.item.name}</span>
                    `;
                    sidebarGroup.appendChild(newLink);
                }

                if (document.getElementById('organization')) {
                    window.location.reload();
                } else if (typeof showGmailSnackbar === 'function') {
                    showGmailSnackbar('Folder "' + result.data.item.name + '" created.');
                }
            })
            .catch(err => {
                if (alertEl) {
                    alertEl.textContent = err.message || 'An error occurred.';
                    alertEl.classList.remove('d-none');
                }
            })
            .finally(() => {
                if (submitBtn) submitBtn.disabled = false;
            });
        });
    }

    // Pre-populate Edit Label & Edit Folder Modals
    document.querySelectorAll('.btn-open-edit-label').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-id');
            const name = btn.getAttribute('data-name');
            const color = btn.getAttribute('data-color');
            const idInput = document.getElementById('editLabelIdInput');
            const nameInput = document.getElementById('editLabelNameInput');
            if (idInput) idInput.value = id || '';
            if (nameInput) nameInput.value = name || '';
            const swatch = document.querySelector(`#editLabelSwatches input[value="${color}"]`);
            if (swatch) swatch.checked = true;
        });
    });

    document.querySelectorAll('.btn-open-edit-folder').forEach(btn => {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-id');
            const name = btn.getAttribute('data-name');
            const color = btn.getAttribute('data-color');
            const idInput = document.getElementById('editFolderIdInput');
            const nameInput = document.getElementById('editFolderNameInput');
            if (idInput) idInput.value = id || '';
            if (nameInput) nameInput.value = name || '';
            const swatch = document.querySelector(`#editFolderSwatches input[value="${color}"]`);
            if (swatch) swatch.checked = true;
        });
    });
});
