document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.querySelector('form[action*="register.php"]');
    const password = document.getElementById('registerPassword');
    const confirmation = document.getElementById('registerConfirmPassword');
    const phone = document.getElementById('registerPhone');
    const phoneError = document.getElementById('phoneError');
    const submit = document.querySelector('.auth-submit');
    const submitLabel = submit ? submit.querySelector('.auth-submit-label') : null;

    const rules = {
        length: (value) => value.length >= 10,
        uppercase: (value) => /[A-Z]/.test(value),
        lowercase: (value) => /[a-z]/.test(value),
        number: (value) => /[0-9]/.test(value),
        symbol: (value) => /[^A-Za-z0-9]/.test(value),
        match: () => password && confirmation && password.value === confirmation.value && password.value.length > 0,
    };

    const updateRules = function () {
        if (!password) return;

        document.querySelectorAll('.auth-password-rule[data-rule]').forEach(function (item) {
            const rule = rules[item.dataset.rule];
            const met = Boolean(rule && rule(password.value));
            const status = item.querySelector('.auth-password-rule-status');
            item.classList.toggle('met', met);
            item.classList.toggle('unmet', !met);
            item.setAttribute('aria-label', item.firstElementChild.textContent.trim() + ': ' + (met ? 'OK' : 'Not OK'));
            if (status) status.textContent = met ? 'OK' : 'Not OK';
        });
    };

    // Phone input mask and validation
    if (phone) {
        phone.addEventListener('input', function () {
            // Strip non-digits
            let value = this.value.replace(/\D/g, '');
            // Limit to 11 digits
            if (value.length > 11) value = value.slice(0, 11);
            this.value = value;

            // Validation
            if (value.length > 0) {
                const isValid = /^09\d{9}$/.test(value);
                phoneError.textContent = isValid ? '' : 'Enter an 11-digit mobile number starting with 09.';
                phoneError.classList.toggle('d-none', isValid);
                phone.classList.toggle('is-invalid', !isValid);
                phone.classList.toggle('is-valid', isValid);
            } else {
                phoneError.classList.add('d-none');
                phone.classList.remove('is-invalid', 'is-valid');
            }
        });

        phone.addEventListener('blur', function () {
            if (this.value.length > 0 && !/^09\d{9}$/.test(this.value)) {
                phoneError.textContent = 'Enter an 11-digit mobile number starting with 09.';
                phoneError.classList.remove('d-none');
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            }
        });
    }

    // Username validation: prevent invalid characters as typed
    const usernameInput = document.getElementById('registerUsername');
    const usernameWarning = document.getElementById('usernameWarning');
    const usernameCharWarning = document.getElementById('usernameCharWarning');

    // Valid characters: a-z, 0-9, ., _, -
    const validCharRegex = /^[a-z0-9._-]+$/;
    const atRegex = /@/;

    function validateUsernameInput(input) {
        let value = input.value;

        // Strip @ and everything after it
        if (atRegex.test(value)) {
            value = value.replace(/@.*$/, '');
            usernameWarning.classList.remove('d-none');
        } else {
            usernameWarning.classList.add('d-none');
        }

        // Convert to lowercase
        value = value.toLowerCase();

        // Check for invalid characters
        let hasInvalidChars = false;
        const sanitized = value.split('').filter(ch => {
            if (validCharRegex.test(ch)) {
                return true;
            }
            hasInvalidChars = true;
            return false;
        }).join('');

        if (hasInvalidChars) {
            usernameCharWarning.classList.remove('d-none');
        } else {
            usernameCharWarning.classList.add('d-none');
        }

        // Update input value with sanitized version
        if (input.value !== sanitized) {
            input.value = sanitized;
        }
    }

    if (usernameInput) {
        usernameInput.addEventListener('input', function () {
            validateUsernameInput(this);
        });
    }

    const restoreSubmit = function () {
        if (!submit) return;
        submit.disabled = false;
        submit.classList.remove('is-loading');
        submit.removeAttribute('aria-busy');
        if (submitLabel) submitLabel.textContent = 'Create account';
    };

    if (password) {
        password.addEventListener('input', function () {
            updateRules();
        });
    }

    if (confirmation) {
        confirmation.addEventListener('input', updateRules);
        confirmation.addEventListener('blur', function () {
            if (password.value && confirmation.value) updateRules();
        });
    }

    if (form && submit) {
        form.addEventListener('submit', function () {
            submit.disabled = true;
            submit.classList.add('is-loading');
            submit.setAttribute('aria-busy', 'true');
            if (submitLabel) submitLabel.textContent = 'Creating account…';
        });
    }

    window.addEventListener('pageshow', restoreSubmit);
});
