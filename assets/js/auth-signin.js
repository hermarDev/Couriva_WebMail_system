/**
 * Couriva - Sign-in page behavior
 * Owns the local password visibility toggle and submit loading state.
 */
(function () {
    'use strict';

    function initSignInPage() {
        var submitButton = document.querySelector('.auth-submit');
        var form = submitButton ? submitButton.closest('form') : null;
        var defaultLabel = submitButton ? submitButton.textContent.trim() : '';

        function setLoading(isLoading) {
            if (!submitButton) return;
            submitButton.disabled = isLoading;
            submitButton.classList.toggle('is-loading', isLoading);
            submitButton.setAttribute('aria-busy', isLoading ? 'true' : 'false');
            submitButton.textContent = isLoading ? 'Signing in…' : defaultLabel;
        }

        if (form) {
            form.addEventListener('submit', function () {
                setLoading(true);
            });
        }

        window.addEventListener('pageshow', function () {
            setLoading(false);
        });

        document.addEventListener('click', function (event) {
            var target = event.target;
            var button = target && typeof target.closest === 'function'
                ? target.closest('.password-toggle')
                : null;
            if (!button) return;

            // The global app.js handler binds the same control; mark this button
            // handled and stop the click before that handler can toggle it again.
            if (button.getAttribute('data-couriva-toggle-handled') !== 'true') {
                button.setAttribute('data-couriva-toggle-handled', 'true');
            }
            event.stopPropagation();
            event.stopImmediatePropagation();

            var input = document.getElementById(button.getAttribute('aria-controls'));
            if (!input) return;

            var isVisible = input.type === 'text';
            input.type = isVisible ? 'password' : 'text';
            button.setAttribute('aria-pressed', isVisible ? 'false' : 'true');
            button.setAttribute('aria-label', isVisible ? 'Show password' : 'Hide password');

            var icon = button.querySelector('svg, i');
            if (icon) {
                icon.setAttribute('class', isVisible ? 'bi bi-eye' : 'bi bi-eye-slash');
                var use = icon.querySelector('use');
                if (use) {
                    use.setAttribute('href', use.getAttribute('href').replace(/#.*$/, isVisible ? '#eye' : '#eye-slash'));
                }
            }
        }, true);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSignInPage);
    } else {
        initSignInPage();
    }
})();
