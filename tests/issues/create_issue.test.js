// Run: node tests/issues/create_issue.test.js
const fs = require('fs');
const vm = require('vm');
const path = require('path');
const assert = require('assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../../forms/issues/create_issue.php'), 'utf8');
const script = source.match(/<script>([\s\S]*?)<\/script>/)[1];
function setup(initial = {}) {
    const nodes = {};
    function node() {
        return {
            value: '', hidden: false, textContent: '', children: [], events: {},
            addEventListener(type, handler) { this.events[type] = handler; },
            appendChild(child) { this.children.push(child); },
            replaceChildren() { this.children = []; },
            focus() { this.focused = true; },
            set innerHTML(html) {
                this.textContent = html.replace(/<[^>]*>/g, '').replace(/&nbsp;/g, '\u00a0');
            }
        };
    }
    for (const id of ['issue-crm-value', 'issue-crm-title', 'issue-crm-select', 'issue-crm-clear',
        'issue-crm-error', 'issue-crm-panel', 'issue-crm-query', 'issue-crm-entity',
        'issue-crm-results', 'issue-create-form', 'issue-submit', 'issue-text-error']) {
        nodes[id] = node();
    }
    const form = nodes['issue-create-form'];
    form.action = '/forms/issues/create_issue.php';
    const field = node();
    form.querySelector = () => initial.noField ? null : field;
    nodes['issue-crm-panel'].hidden = true;
    nodes['issue-crm-entity'].value = 'contact';
    nodes['issue-crm-value'].value = initial.crmValue || '';
    const calls = [];
    const pending = [];
    let timer;
    const editor = initial.noEditor ? null : {GetContent: () => initial.html ?? '<p><b>Hello</b></p>'};
    vm.runInNewContext(script, {
        BX: {ready: fn => fn(), bitrix_sessid: () => 'test-session'},
        window: {issueTextEditor: editor},
        document: {getElementById: id => nodes[id], createElement: node},
        URLSearchParams,
        fetch: (url, options) => {
            calls.push({url, options});
            return new Promise(resolve => pending.push(resolve));
        },
        setTimeout: fn => { timer = fn; return 1; },
        clearTimeout: () => { timer = null; }
    });
    return {
        nodes, calls, field,
        input(query) {
            nodes['issue-crm-query'].value = query;
            nodes['issue-crm-query'].events.input();
        },
        tick() { const fn = timer; timer = null; if (fn) fn(); },
        respond(index, data, ok = true) { pending[index]({ok, json: async () => data}); },
        submit() {
            let prevented = false;
            form.events.submit({preventDefault: () => { prevented = true; }});
            return prevented;
        }
    };
}
const flush = () => new Promise(resolve => setImmediate(resolve));
(async function () {
    const t = setup();
    t.nodes['issue-crm-select'].events.click();
    assert.equal(t.calls[0].url, '/forms/issues/create_issue.php');
    assert.equal(t.calls[0].options.credentials, 'same-origin');
    assert.equal(t.calls[0].options.body.get('sessid'), 'test-session');
    assert.equal(t.calls[0].options.body.get('entity'), 'contact');
    t.respond(0, {items: [{id: 42, entityId: 'contact', title: '<b>Alice</b>'}]});
    await flush();
    let result = t.nodes['issue-crm-results'].children[0];
    assert.equal(result.textContent, '<b>Alice</b> (#42)');
    result.events.click();
    assert.equal(t.nodes['issue-crm-value'].value, 'C_42');
    assert.equal(t.nodes['issue-crm-title'].textContent, '<b>Alice</b>');
    assert.equal(t.nodes['issue-crm-panel'].hidden, true);
    t.nodes['issue-crm-clear'].events.click();
    assert.equal(t.nodes['issue-crm-value'].value, '');
    assert.equal(t.nodes['issue-crm-clear'].hidden, true);

    t.nodes['issue-crm-select'].events.click();
    t.nodes['issue-crm-entity'].value = 'company';
    t.nodes['issue-crm-entity'].events.change();
    assert.equal(t.calls[2].options.body.get('entity'), 'company');
    t.input('Tricolor');
    assert.equal(t.calls.length, 3, 'Search input is debounced');
    t.tick();
    assert.equal(t.calls[3].options.body.get('query'), 'Tricolor');
    t.respond(3, {items: [{id: 99, entityId: 'company', title: 'Tricolor', phones: ['+7 900 123-45-67', '+7 900 765-43-21'], emails: ['sales@example.org', '<b>office</b>@example.org']}]});
    await flush();
    t.respond(2, {items: [{id: 1, entityId: 'company', title: 'Old result'}]});
    await flush();
    assert.equal(t.nodes['issue-crm-results'].children[0].textContent, 'Tricolor (#99)', 'Stale search cannot replace latest results');
    t.nodes['issue-crm-results'].children[0].events.click();
    assert.equal(t.nodes['issue-crm-value'].value, 'CO_99');
    assert.equal(t.nodes['issue-crm-results'].children[0].children[0].textContent,
        'Тел.: +7 900 123-45-67, +7 900 765-43-21 · Почта: sales@example.org, <b>office</b>@example.org',
        'All phones and emails use textContent, including HTML-like values');
    assert.equal(t.nodes['issue-crm-title'].textContent,
        'Tricolor — Тел.: +7 900 123-45-67, +7 900 765-43-21 · Почта: sales@example.org, <b>office</b>@example.org');

    const enter = setup();
    enter.nodes['issue-crm-query'].value = 'New';
    let prevented = false;
    enter.nodes['issue-crm-query'].events.keydown({key: 'Enter', preventDefault() { prevented = true; }});
    assert.equal(prevented, true, 'Enter searches without submitting form');
    enter.respond(0, {error: 'Session expired'}, false);
    await flush();
    assert.equal(enter.nodes['issue-crm-error'].textContent, 'Session expired');

    const restored = setup({crmValue: 'CO_123'});
    assert.equal(restored.nodes['issue-crm-value'].value, 'CO_123');
    assert.equal(t.submit(), false);
    assert.equal(t.field.value, '<p><b>Hello</b></p>', 'HTML is posted without losing formatting');
    assert.equal(t.nodes['issue-submit'].disabled, true);
    const empty = setup({html: '<p><br>&nbsp;</p>'});
    assert.equal(empty.submit(), true);
    assert.notEqual(empty.nodes['issue-submit'].disabled, true);
    assert.equal(setup({html: '<p>\u200b</p>'}).submit(), true);
    assert.equal(setup({noEditor: true}).submit(), true);
    const missingField = setup({noField: true});
    assert.equal(missingField.submit(), false);
    assert.equal(missingField.nodes['issue-create-form'].children[0].value, '<p><b>Hello</b></p>');
    console.log('Issue form client checks passed: CRM search, CSRF, contact/company selection, phone/email output, stale results, errors, HTML submission and empty editor validation.');
})().catch(error => { console.error(error); process.exitCode = 1; });
