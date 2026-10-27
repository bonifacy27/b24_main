const fs = require('fs');
const vm = require('vm');
const path = require('path');
const assert = require('assert/strict');
const source = fs.readFileSync(path.join(__dirname, '../../forms/issues/create_issue.php'), 'utf8');
const match = source.match(/<script>([\s\S]*?)<\/script>/);
assert(match, 'Inline form script exists');
function render(initial, hasSelector = true) {
    const nodes = {};
    for (const id of ['issue-crm-value', 'issue-crm-title', 'issue-crm-select', 'issue-crm-clear', 'issue-crm-error', 'issue-create-form', 'issue-submit']) {
        nodes[id] = {value: '', hidden: false, events: {}, addEventListener(name, fn) { this.events[name] = fn; }};
    }
    let dialog;
    class Dialog {
        constructor(config) { this.config = config; this.items = []; dialog = this; }
        show() { this.visible = true; }
        getSelectedItems() { return this.items; }
        choose(entity, id, title) {
            const item = {
                getEntityId: () => entity, getId: () => id, getTitle: () => title,
                deselect: () => { this.items = this.items.filter(x => x !== item); this.config.events['Item:onDeselect'](); }
            };
            this.items = [item];
            this.config.events['Item:onSelect']({getData: () => ({item})});
        }
    }
    vm.runInNewContext(match[1].replace(/<\?= json_encode\(\$crmItem, \$jsonFlags\) \?>/, JSON.stringify(initial)), {
        BX: {ready: fn => fn(), UI: hasSelector ? {EntitySelector: {Dialog}} : {}},
        document: {getElementById: id => nodes[id]}
    });
    return {nodes, dialog};
}
const {nodes, dialog} = render(null);
nodes['issue-crm-select'].events.click();
assert.equal(dialog.visible, true);
dialog.choose('company', 123, '<b>Company</b>');
assert.equal(nodes['issue-crm-value'].value, 'CO_123');
assert.equal(nodes['issue-crm-title'].textContent, '<b>Company</b>');
assert.equal(nodes['issue-crm-clear'].hidden, false);
dialog.choose('contact', 456, 'Contact');
assert.equal(nodes['issue-crm-value'].value, 'C_456');
nodes['issue-crm-clear'].events.click();
assert.equal(nodes['issue-crm-value'].value, '');
assert.equal(dialog.getSelectedItems().length, 0);
assert.equal(nodes['issue-crm-clear'].hidden, true);
nodes['issue-create-form'].events.submit();
assert.equal(nodes['issue-submit'].disabled, true);
assert.equal(render(null, false).nodes['issue-crm-select'].disabled, true);
const initial = {id: 42, entityId: 'company', title: 'Saved company'};
const restored = render(initial).dialog.config;
assert.equal(restored.items[0].title, 'Saved company');
assert.equal(restored.preselectedItems[0][0], 'company');
assert.equal(restored.preselectedItems[0][1], 42);
console.log('Client checks passed: selector, CRM prefixes, text output, clear, submit, fallback, restore.');
