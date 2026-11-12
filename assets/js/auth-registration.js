document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.querySelector('form[action*="register.php"]');
    const password = document.getElementById('registerPassword');
    const confirmation = document.getElementById('registerConfirmPassword');
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
