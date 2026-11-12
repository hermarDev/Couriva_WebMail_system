document.addEventListener('DOMContentLoaded', function () {
    'use strict';

    const form = document.querySelector('form[action*="register.php"]');
    const password = document.getElementById('registerPassword');
    const confirmation = document.getElementById('registerConfirmPassword');
    const match = document.querySelector('.auth-password-match');
    const submit = document.querySelector('.auth-submit');
    const submitLabel = submit ? submit.querySelector('.auth-submit-label') : null;

    const rules = {
        length: (value) => value.length >= 10,
        uppercase: (value) => /[A-Z]/.test(value),
        lowercase: (value) => /[a-z]/.test(value),
        number: (value) => /[0-9]/.test(value),
        symbol: (value) => /[^A-Za-z0-9]/.test(value),
    };

    const updateRules = function () {
        if (!password) return;

        document.querySelectorAll('.auth-password-rule[data-rule]').forEach(function (item) {
            const rule = rules[item.dataset.rule];
            const met = Boolean(rule && rule(password.value));
            const status = item.querySelector('.auth-password-rule-status');
            item.classList.toggle('is-met', met);
            item.setAttribute('aria-label', item.firstElementChild.textContent.trim() + ': ' + (met ? 'Met' : 'Not met'));
            if (status) status.textContent = met ? 'Met' : 'Not met';
        });
    };

    const updateMatch = function () {
        if (!password || !confirmation || !match) return;
        if (!password.value || !confirmation.value) {
            match.hidden = true;
            match.textContent = '';
            return;
        }

        match.hidden = false;
        if (password.value === confirmation.value) {
            match.textContent = 'Passwords match';
            match.classList.remove('is-mismatch');
            match.classList.add('is-match');
        } else {
            match.textContent = 'Passwords do not match';
            match.classList.remove('is-match');
            match.classList.add('is-mismatch');
        }
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
            updateMatch();
        });
    }

    if (confirmation) {
        confirmation.addEventListener('input', updateMatch);
        confirmation.addEventListener('blur', function () {
            if (password.value && confirmation.value) updateMatch();
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
