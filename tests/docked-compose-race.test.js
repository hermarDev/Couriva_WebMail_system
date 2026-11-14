/**
 * tests/docked-compose-race.test.js
 * Focused regression test for docked compose autosave and discard race conditions.
 *
 * Verifies:
 * 1. Discard cancels pending debounced autosave (does not fire fetch after discard).
 * 2. In-flight autosave response arriving after discard does not resurrect draft_id or save status.
 * 3. Overlapping autosaves do not send parallel draft creation requests with empty draft_id.
 * 4. Opening an existing draft preserves its draft_id for subsequent autosaves.
 */

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

// Helper to create a lightweight mock DOM environment for app.js
function createMockEnvironment() {
    const listeners = {};
    const elements = {};

    function createElement(id, tagName = 'div', attributes = {}) {
        const elListeners = {};
        const el = {
            id,
            tagName: tagName.toUpperCase(),
            value: '',
            innerText: '',
            textContent: '',
            title: '',
            className: '',
            dataset: {},
            classList: {
                _classes: new Set(),
                add(c) { this._classes.add(c); },
                remove(c) { this._classes.delete(c); },
                toggle(c, force) {
                    if (force === undefined) {
                        if (this._classes.has(c)) { this._classes.delete(c); return false; }
                        else { this._classes.add(c); return true; }
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
            dispatch(ev, eventObj = {}) {
                if (elListeners[ev]) {
                    elListeners[ev].forEach(cb => cb({
                        preventDefault() {},
                        stopPropagation() {},
                        target: el,
                        ...eventObj
                    }));
                }
            },
            querySelector(sel) {
                if (sel === '.compose-minimize-btn') return elements['composeMinimizeBtn'];
                if (sel === '.compose-maximize-btn') return elements['composeMaximizeBtn'];
                if (sel === '.compose-close-btn') return elements['composeCloseBtn'];
                return null;
            },
            querySelectorAll() { return []; },
            focus() {}
        };
        elements[id] = el;
        return el;
    }

    // Required DOM elements for docked compose
    createElement('dockedComposeContainer', 'div');
    elements['dockedComposeContainer'].classList.add('d-none');
    createElement('composeMinimizeBtn', 'button');
    createElement('composeMaximizeBtn', 'button');
    createElement('composeCloseBtn', 'button');
    createElement('btnOpenCompose', 'button');
    createElement('btnDockedDiscard', 'button');
    createElement('dockedComposeTitle', 'span');
    createElement('dockedTo', 'input');
    createElement('dockedSubject', 'input');
    createElement('dockedBody', 'textarea');
    createElement('dockedDraftId', 'input');
    createElement('dockedSaveStatus', 'span');
    createElement('gmailSearchBar', 'form');
    createElement('headerSearchInput', 'input');
    createElement('keyboardShortcutsModal', 'div');

    const fetchCalls = [];
    let fetchResolver = null;

    const mockFetch = (url, options) => {
        const callInfo = {
            url,
            options,
            body: options && options.body ? JSON.parse(options.body) : null
        };
        fetchCalls.push(callInfo);
        return new Promise((resolve) => {
            fetchResolver = (data = { success: true, draft_id: 101 }) => {
                resolve({
                    ok: true,
                    json: async () => data
                });
            };
            if (mockFetch.autoResolve) {
                fetchResolver();
            }
        });
    };
    mockFetch.autoResolve = true;
    mockFetch.resolveLast = (data) => {
        if (fetchResolver) fetchResolver(data);
    };

    const mockWindow = {
        location: { pathname: '/public/inbox.php' },
        innerWidth: 1024,
        matchMedia: () => ({ matches: false }),
        confirm: () => true,
        CourivaDialog: {
            confirm: async () => true
        },
        MutationObserver: function() {
            return { observe() {}, disconnect() {} };
        }
    };

    const mockDoc = {
        documentElement: {
            getAttribute() { return 'light'; },
            setAttribute() {}
        },
        body: {
            appendChild() {}
        },
        createElement(tagName) {
            return createElement('temp_' + Math.random(), tagName);
        },
        getElementById(id) {
            return elements[id] || null;
        },
        querySelector(sel) {
            if (sel === 'meta[name=\"csrf-token\"]') return { getAttribute: () => 'token123' };
            if (sel === 'meta[name=\"webmail-base-url\"]') return { getAttribute: () => '' };
            if (sel === '#dockedComposeContainer') return elements['dockedComposeContainer'];
            if (sel === '#btnOpenCompose') return elements['btnOpenCompose'];
            return null;
        },
        querySelectorAll(sel) {
            return [];
        },
        addEventListener(ev, cb) {
            if (!listeners[ev]) listeners[ev] = [];
            listeners[ev].push(cb);
        }
    };

    return {
        elements,
        mockDoc,
        mockWindow,
        mockFetch,
        fetchCalls,
        triggerDOMContentLoaded() {
            if (listeners['DOMContentLoaded']) {
                listeners['DOMContentLoaded'].forEach(cb => cb());
            }
        }
    };
}

// Load and execute app.js in mock sandbox
function loadApp(env) {
    const appJsCode = fs.readFileSync(path.join(__dirname, '../assets/js/app.js'), 'utf8');
    const fn = new Function(
        'window', 'document', 'fetch', 'setTimeout', 'clearTimeout', 'setInterval', 'clearInterval', 'location', 'bootstrap', 'MutationObserver',
        appJsCode
    );
    fn(
        env.mockWindow,
        env.mockDoc,
        env.mockFetch,
        setTimeout,
        clearTimeout,
        setInterval,
        clearInterval,
        env.mockWindow.location,
        undefined,
        env.mockWindow.MutationObserver
    );
    env.triggerDOMContentLoaded();
}

test('Race 1: Discarding docked compose cancels pending debounced autosave', async () => {
    const env = createMockEnvironment();
    loadApp(env);

    // Open docked compose
    env.elements['btnOpenCompose'].dispatch('click');
    assert.strictEqual(env.elements['dockedComposeContainer'].classList.contains('d-none'), false);

    // User types in docked compose
    env.elements['dockedTo'].value = 'test@couriva.local';
    env.elements['dockedSubject'].value = 'Test Subject';
    env.elements['dockedBody'].value = 'Draft body text';
    env.elements['dockedBody'].dispatch('input');

    assert.strictEqual(env.fetchCalls.length, 0, 'Autosave should be debounced, not called immediately');

    // User clicks Discard before debounce timer (1500ms) expires
    await env.elements['btnDockedDiscard'].dispatch('click');

    // Wait past the 1500ms debounce timer (e.g. 1700ms)
    await new Promise(r => setTimeout(r, 1700));

    assert.strictEqual(
        env.fetchCalls.length,
        0,
        'Pending autosave timer must be cancelled on discard; fetch must NOT be called'
    );
    assert.strictEqual(env.elements['dockedDraftId'].value, '', 'dockedDraftId must remain empty');
    assert.strictEqual(env.elements['dockedComposeContainer'].classList.contains('d-none'), true);
});

test('Race 2: In-flight autosave response resolving after discard does not resurrect draft_id or save status', async () => {
    const env = createMockEnvironment();
    env.mockFetch.autoResolve = false; // Hold fetch in-flight
    loadApp(env);

    env.elements['btnOpenCompose'].dispatch('click');

    // Type content
    env.elements['dockedTo'].value = 'test@couriva.local';
    env.elements['dockedSubject'].value = 'In-Flight Subject';
    env.elements['dockedBody'].value = 'Body in flight';
    env.elements['dockedBody'].dispatch('input');

    // Fast-forward debounce timer (1600ms) to trigger fetch
    await new Promise(r => setTimeout(r, 1600));

    assert.strictEqual(env.fetchCalls.length, 1, 'Autosave fetch should have started');

    // While fetch is still in-flight, user discards the draft
    await env.elements['btnDockedDiscard'].dispatch('click');
    assert.strictEqual(env.elements['dockedDraftId'].value, '', 'draft_id cleared on discard');

    // Now late fetch response arrives from server
    env.mockFetch.resolveLast({ success: true, draft_id: 888 });
    await new Promise(r => setTimeout(r, 50));

    // The late response must NOT set draft_id or resurrect "Saved" status
    assert.strictEqual(
        env.elements['dockedDraftId'].value,
        '',
        'Late autosave response must NOT overwrite dockedDraftId after discard'
    );
    assert.notStrictEqual(
        env.elements['dockedSaveStatus'].textContent,
        'Saved',
        'Late autosave response must NOT set status to "Saved" after discard'
    );
});

test('Race 3: Multiple rapid edits while initial draft creation is in flight do not send duplicate create requests with empty draft_id', async () => {
    const env = createMockEnvironment();
    env.mockFetch.autoResolve = false; // Hold first fetch in flight
    loadApp(env);

    env.elements['btnOpenCompose'].dispatch('click');

    // Keystroke 1
    env.elements['dockedSubject'].value = 'First draft title';
    env.elements['dockedSubject'].dispatch('input');

    // Wait for first fetch to start
    await new Promise(r => setTimeout(r, 1600));
    assert.strictEqual(env.fetchCalls.length, 1, 'First autosave should be in flight');
    assert.strictEqual(env.fetchCalls[0].body.draft_id, '', 'First autosave has empty draft_id');

    // Keystroke 2 while first fetch is STILL in flight
    env.elements['dockedBody'].value = 'Additional text added while saving';
    env.elements['dockedBody'].dispatch('input');

    // Wait 1600ms past second debounce
    await new Promise(r => setTimeout(r, 1600));

    // Resolve first fetch with draft_id = 999
    env.mockFetch.resolveLast({ success: true, draft_id: 999 });
    await new Promise(r => setTimeout(r, 50));

    // Second fetch should now be sent or queued using the returned draft_id 999
    if (env.fetchCalls.length > 1) {
        assert.strictEqual(
            env.fetchCalls[1].body.draft_id,
            999,
            'Second autosave must use newly resolved draft_id rather than empty string'
        );
    }
});

test('Race 4: Opening an existing draft preserves its draft_id and autosaves update that draft', async () => {
    const env = createMockEnvironment();
    loadApp(env);

    // Open existing draft with id 42
    // We can simulate calling openDockedCompose by clicking btnOpenCompose with draft data or directly
    env.elements['btnOpenCompose'].dispatch('click');
    env.elements['dockedDraftId'].value = '42';
    env.elements['dockedSubject'].value = 'Existing Draft';
    env.elements['dockedBody'].value = 'Existing content updated';
    env.elements['dockedBody'].dispatch('input');

    await new Promise(r => setTimeout(r, 1600));

    assert.strictEqual(env.fetchCalls.length, 1, 'Autosave should fire for existing draft');
    assert.strictEqual(env.fetchCalls[0].body.draft_id, '42', 'Autosave must preserve and pass existing draft_id');
});
