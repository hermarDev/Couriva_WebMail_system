/**
 * tests/docked-compose-attachments.test.js
 * Regression test for floating docked compose file attachment support.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..');

function createMockEnvironment() {
    const listeners = {};
    const elements = {};

    function createElement(id, tagName = 'div', attributes = {}) {
        const elListeners = {};
        const children = [];
        let _innerHTML = '';
        let _textContent = '';

        const el = {
            id,
            tagName: tagName.toUpperCase(),
            value: '',
            files: [],
            innerText: '',
            title: '',
            className: '',
            dataset: {},
            clickedCount: 0,
            children,
            get innerHTML() {
                return _innerHTML;
            },
            set innerHTML(val) {
                _innerHTML = String(val);
                if (val === '') {
                    children.length = 0;
                }
            },
            get textContent() {
                return _textContent;
            },
            set textContent(val) {
                _textContent = String(val);
            },
            classList: {
                _classes: new Set(),
                add(c) { this._classes.add(c); },
                remove(c) { this._classes.delete(c); },
                toggle(c, force) {
                    if (force === undefined) {
                        if (this._classes.has(c)) { this._classes.delete(c); return false; }
                        this._classes.add(c); return true;
                    }
                    if (force) { this._classes.add(c); return true; }
                    this._classes.delete(c);
                    return false;
                },
                contains(c) { return this._classes.has(c); }
            },
            attributes: { ...attributes },
            getAttribute(k) { return this.attributes[k] || null; },
            setAttribute(k, v) { this.attributes[k] = String(v); },
            removeAttribute(k) { delete this.attributes[k]; },
            addEventListener(ev, cb) {
                if (!elListeners[ev]) elListeners[ev] = [];
                elListeners[ev].push(cb);
            },
            async dispatch(ev, eventObj = {}) {
                if (elListeners[ev]) {
                    for (const cb of elListeners[ev]) {
                        await cb({
                            preventDefault() {},
                            stopPropagation() {},
                            target: el,
                            ...eventObj
                        });
                    }
                }
            },
            appendChild(child) {
                children.push(child);
                return child;
            },
            querySelector(sel) {
                if (sel === '.compose-minimize-btn') return elements['composeMinimizeBtn'];
                if (sel === '.compose-maximize-btn') return elements['composeMaximizeBtn'];
                if (sel === '.compose-close-btn') return elements['composeCloseBtn'];
                if (sel === '.file-name') {
                    if (!el._fileNameEl) el._fileNameEl = createElement(id + '_fname', 'span');
                    return el._fileNameEl;
                }
                if (sel === '.file-remove-btn') {
                    if (!el._removeBtnEl) el._removeBtnEl = createElement(id + '_remove', 'button');
                    return el._removeBtnEl;
                }
                return null;
            },
            querySelectorAll() { return []; },
            click() { el.clickedCount++; },
            focus() {}
        };
        if (id) elements[id] = el;
        return el;
    }

    createElement('dockedComposeContainer', 'div');
    elements['dockedComposeContainer'].classList.add('d-none');
    createElement('dockedComposeForm', 'form');
    createElement('composeMinimizeBtn', 'button');
    createElement('composeMaximizeBtn', 'button');
    createElement('composeCloseBtn', 'button');
    createElement('btnOpenCompose', 'button');
    createElement('btnDockedAttach', 'button');
    createElement('btnDockedDiscard', 'button');
    createElement('dockedAttachmentInput', 'input');
    createElement('dockedAttachmentPreviews', 'div');
    elements['dockedAttachmentPreviews'].classList.add('d-none');
    createElement('dockedAttachmentAlert', 'div');
    elements['dockedAttachmentAlert'].classList.add('d-none');
    createElement('dockedAttachmentAlertMsg', 'span');
    createElement('dockedComposeTitle', 'span');
    createElement('dockedTo', 'input');
    createElement('dockedSubject', 'input');
    createElement('dockedBody', 'textarea');
    createElement('dockedDraftId', 'input');
    createElement('dockedSaveStatus', 'span');

    let confirmCalls = 0;

    class MockDataTransfer {
        constructor() {
            this._items = [];
            const self = this;
            this.items = {
                add(file) { self._items.push(file); }
            };
        }
        get files() {
            return [...this._items];
        }
    }

    const mockWindow = {
        location: { pathname: '/public/inbox.php' },
        innerWidth: 1024,
        matchMedia: () => ({ matches: false }),
        confirm: () => { confirmCalls++; return true; },
        CourivaDialog: {
            confirm: async () => { confirmCalls++; return true; }
        },
        MutationObserver: function () {
            return { observe() {}, disconnect() {} };
        },
        DataTransfer: MockDataTransfer
    };

    const mockDoc = {
        documentElement: {
            getAttribute() { return 'light'; },
            setAttribute() {}
        },
        body: { appendChild() {} },
        createElement(tagName) {
            return createElement('temp_' + Math.random().toString(36).slice(2), tagName);
        },
        getElementById(id) {
            return elements[id] || null;
        },
        querySelector(sel) {
            if (sel === 'meta[name="csrf-token"]') return { getAttribute: () => 'token123' };
            if (sel === 'meta[name="webmail-base-url"]') return { getAttribute: () => '' };
            if (sel === '#dockedComposeContainer') return elements['dockedComposeContainer'];
            if (sel === '#btnOpenCompose') return elements['btnOpenCompose'];
            return null;
        },
        querySelectorAll() { return []; },
        addEventListener(ev, cb) {
            if (!listeners[ev]) listeners[ev] = [];
            listeners[ev].push(cb);
        }
    };

    return {
        elements,
        mockDoc,
        mockWindow,
        MockDataTransfer,
        getConfirmCalls: () => confirmCalls,
        triggerDOMContentLoaded() {
            if (listeners['DOMContentLoaded']) {
                listeners['DOMContentLoaded'].forEach(cb => cb());
            }
        }
    };
}

function loadApp(env) {
    const appJsCode = fs.readFileSync(path.join(ROOT, 'assets/js/app.js'), 'utf8');
    const fn = new Function(
        'window', 'document', 'fetch', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'location', 'bootstrap', 'MutationObserver', 'DataTransfer',
        appJsCode
    );
    fn(
        env.mockWindow,
        env.mockDoc,
        async () => ({ ok: true, json: async () => ({ success: true }) }),
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        env.mockWindow.location,
        undefined,
        env.mockWindow.MutationObserver,
        env.MockDataTransfer
    );
    env.triggerDOMContentLoaded();
}

test('includes/footer.php renders docked attachment input, previews, and alert inside #dockedComposeForm', () => {
    const footer = fs.readFileSync(path.join(ROOT, 'includes/footer.php'), 'utf8');
    assert.match(footer, /id="dockedAttachmentInput"[^>]*name="attachments\[\]"/);
    assert.match(footer, /id="dockedAttachmentPreviews"/);
    assert.match(footer, /id="dockedAttachmentAlert"/);
    assert.match(footer, /id="btnDockedAttach"/);
});

test('Clicking #btnDockedAttach opens #dockedAttachmentInput file picker and stages valid files', async () => {
    const env = createMockEnvironment();
    loadApp(env);

    env.elements['btnOpenCompose'].dispatch('click');
    await env.elements['btnDockedAttach'].dispatch('click');
    assert.strictEqual(env.elements['dockedAttachmentInput'].clickedCount, 1, 'Paperclip button must trigger hidden file input click');

    const file1 = { name: 'report.pdf', size: 1024 };
    const file2 = { name: 'photo.png', size: 2048 };

    env.elements['dockedAttachmentInput'].files = [file1];
    await env.elements['dockedAttachmentInput'].dispatch('change');

    assert.strictEqual(env.elements['dockedAttachmentInput'].files.length, 1);
    assert.strictEqual(env.elements['dockedAttachmentPreviews'].children.length, 1);
    assert.strictEqual(env.elements['dockedAttachmentPreviews'].classList.contains('d-none'), false);

    // Selecting a second file accumulates without replacing first file
    env.elements['dockedAttachmentInput'].files = [file2];
    await env.elements['dockedAttachmentInput'].dispatch('change');

    assert.strictEqual(env.elements['dockedAttachmentInput'].files.length, 2);
    assert.strictEqual(env.elements['dockedAttachmentPreviews'].children.length, 2);
    assert.strictEqual(
        env.elements['dockedAttachmentPreviews'].children[0]._fileNameEl.textContent,
        'report.pdf'
    );
    assert.strictEqual(
        env.elements['dockedAttachmentPreviews'].children[1]._fileNameEl.textContent,
        'photo.png'
    );

    // Removing the first file leaves only the second file
    await env.elements['dockedAttachmentPreviews'].children[0]._removeBtnEl.dispatch('click');
    assert.strictEqual(env.elements['dockedAttachmentInput'].files.length, 1);
    assert.strictEqual(env.elements['dockedAttachmentInput'].files[0].name, 'photo.png');
    assert.strictEqual(env.elements['dockedAttachmentPreviews'].children.length, 1);
});

test('Docked compose rejects disallowed/oversized files and shows inline alert', async () => {
    const env = createMockEnvironment();
    loadApp(env);

    env.elements['btnOpenCompose'].dispatch('click');
    env.elements['dockedAttachmentInput'].files = [
        { name: 'payload.exe', size: 512 },
        { name: 'huge.pdf', size: 26 * 1024 * 1024 }
    ];
    await env.elements['dockedAttachmentInput'].dispatch('change');

    assert.strictEqual(env.elements['dockedAttachmentInput'].files.length, 0, 'Invalid files must not remain staged');
    assert.strictEqual(env.elements['dockedAttachmentAlert'].classList.contains('d-none'), false, 'Alert must become visible');
    assert.ok(env.elements['dockedAttachmentAlertMsg'].textContent.length > 0, 'Alert message must explain rejection');
});

test('Discarding docked compose confirms and clears staged attachments and alerts', async () => {
    const env = createMockEnvironment();
    loadApp(env);

    env.elements['btnOpenCompose'].dispatch('click');
    env.elements['dockedAttachmentInput'].files = [{ name: 'notes.txt', size: 120 }];
    await env.elements['dockedAttachmentInput'].dispatch('change');

    assert.strictEqual(env.elements['dockedAttachmentInput'].files.length, 1);

    await env.elements['btnDockedDiscard'].dispatch('click');

    assert.strictEqual(env.getConfirmCalls(), 1, 'Discard with staged attachment must prompt confirmation');
    assert.strictEqual(env.elements['dockedAttachmentInput'].files.length, 0, 'Staged files must be cleared on discard');
    assert.strictEqual(env.elements['dockedAttachmentPreviews'].children.length, 0);
    assert.strictEqual(env.elements['dockedAttachmentPreviews'].classList.contains('d-none'), true);
    assert.strictEqual(env.elements['dockedAttachmentAlert'].classList.contains('d-none'), true);
});
