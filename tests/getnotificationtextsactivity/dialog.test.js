'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const file = path.resolve(__dirname, '../../local/activities/getnotificationtextsactivity/properties_dialog.php');
const outputs = ['TaskTitle', 'TaskText', 'FormName', 'FormText', 'MailSubject', 'MailText', 'SiteText'];
const source = fs.readFileSync(file, 'utf8').split('<script>')[1].split('</script>')[0]
    .replace(/<\?=.*?\?>/g, (match) => match.includes('$uid') ? 'test' : match.includes('array_keys') ? JSON.stringify(outputs) : '{}');
function createDialog(inputs = [], options = {}) {
    const requests = [];
    const nodes = {};
    const form = {addEventListener() {}};
    const root = {innerHTML: 'old fields', querySelectorAll() { return inputs; }, closest() { return form; }};
    nodes.test_bindings = root;
    nodes.test_iblock = {value: '408'};
    nodes.test_template = {value: '3672570'};
    nodes.test_status = {textContent: ''};
    nodes.test_refresh = {disabled: false};
    for (const key of outputs) {
        nodes['test_' + key] = {value: key === 'TaskTitle' ? 'NAME' : ''};
    }
    const BX = {
        bitrix_sessid() { return 'session'; },
        type: {isString(value) { return typeof value === 'string'; }},
        util: {urlencode: encodeURIComponent},
        processHTML(html) {
            if (options.renderError) { throw new Error('renderer failed'); }
            return {HTML: html, SCRIPT: []};
        },
        ajax(config) {
            // Exercise the legacy Bitrix serializer contract, including recursive objects.
            config.encodedData = BX.ajax.prepareData(config.data);
            if (options.sendError) { throw new Error('transport failed'); }
            requests.push(config);
        }
    };
    // Same algorithm as core_ajax.js: deliberately use instance hasOwnProperty().
    BX.ajax.prepareData = function (data, prefix) {
        if (BX.type.isString(data)) { return data; }
        let result = '';
        if (data != null) {
            for (const key in data) {
                if (data.hasOwnProperty(key)) {
                    if (result.length) { result += '&'; }
                    let name = BX.util.urlencode(key);
                    if (prefix) { name = prefix + '[' + name + ']'; }
                    result += typeof data[key] === 'object' ? BX.ajax.prepareData(data[key], name) : name + '=' + BX.util.urlencode(data[key]);
                }
            }
        }
        return result;
    };
    BX.ajax.processScripts = function () {};
    vm.runInNewContext(source, {document: {getElementById(id) { return nodes[id]; }}, BX});
    return {requests, nodes, root, refresh: nodes.test_refresh, status: nodes.test_status, BX};
}
let passed = 0;
function test(name, callback) { callback(); passed++; console.log('PASS ' + name); }
function input(key, value) { return {name: 'nt_binding_' + key, value}; }
test('reproduces legacy serializer failure on prototype-less cache', () => {
    const dialog = createDialog();
    const cache = Object.create(null); cache.deadline = 'date';
    assert.throws(() => dialog.BX.ajax.prepareData({bindings: cache}), /hasOwnProperty/);
});
test('refresh sends JSON bindings and renders response without closing dialog', () => {
    const dialog = createDialog([input('deadline', '{=Variable:Date}')]);
    dialog.refresh.onclick();
    assert.equal(dialog.requests.length, 1);
    const request = dialog.requests[0];
    assert.deepEqual(JSON.parse(request.data.bindings_json), {deadline: '{=Variable:Date}'});
    assert.match(request.encodedData, /bindings_json=/);
    request.onsuccess({ok: true, data: {html: 'updated fields'}});
    assert.equal(dialog.root.innerHTML, 'updated fields');
    assert.equal(dialog.status.textContent, '');
    assert.equal(dialog.refresh.disabled, false);
});
test('reserved parameter names survive serialization', () => {
    const dialog = createDialog([input('hasOwnProperty', 'value'), input('__proto__', 'safe')]);
    dialog.refresh.onclick();
    const values = JSON.parse(dialog.requests[0].data.bindings_json);
    assert.equal(values.hasOwnProperty, 'value');
    assert.equal(values.__proto__, 'safe');
});
test('auxiliary expression value is preserved', () => {
    const dialog = createDialog([input('deadline', 'label'), input('deadline_X', '{=Variable:Date}')]);
    dialog.refresh.onclick();
    assert.equal(JSON.parse(dialog.requests[0].data.bindings_json).deadline, '{=Variable:Date}');
});
test('empty bindings still send a valid JSON object', () => {
    const dialog = createDialog(); dialog.refresh.onclick();
    assert.deepEqual(JSON.parse(dialog.requests[0].data.bindings_json), {});
});
test('synchronous transport error clears loading and permits retry', () => {
    const dialog = createDialog([], {sendError: true}); dialog.refresh.onclick();
    assert.equal(dialog.refresh.disabled, false);
    assert.match(dialog.status.textContent, /отправить запрос/);
});
test('timeout clears loading and permits retry', () => {
    const dialog = createDialog(); dialog.refresh.onclick();
    assert.equal(dialog.requests[0].timeout, 30);
    dialog.requests[0].onfailure('timeout');
    assert.equal(dialog.refresh.disabled, false);
    assert.match(dialog.status.textContent, /время ожидания/);
});
test('malformed response gives a visible error', () => {
    const dialog = createDialog(); dialog.refresh.onclick(); dialog.requests[0].onsuccess(null);
    assert.equal(dialog.refresh.disabled, false);
    assert.match(dialog.status.textContent, /некорректный ответ/);
});
test('renderer error gives a visible error', () => {
    const dialog = createDialog([], {renderError: true}); dialog.refresh.onclick();
    dialog.requests[0].onsuccess({ok: true, data: {html: 'new fields'}});
    assert.equal(dialog.refresh.disabled, false);
    assert.match(dialog.status.textContent, /отобразить параметры/);
});
test('stale response cannot overwrite newer template parameters', () => {
    const dialog = createDialog(); dialog.refresh.onclick();
    dialog.nodes.test_template.value = '6'; dialog.nodes.test_template.onchange();
    dialog.requests[0].onsuccess({ok: true, data: {html: 'stale'}});
    assert.equal(dialog.root.innerHTML, 'old fields');
    assert.equal(dialog.refresh.disabled, true);
    dialog.requests[1].onsuccess({ok: true, data: {html: 'latest'}});
    assert.equal(dialog.root.innerHTML, 'latest');
    assert.equal(dialog.refresh.disabled, false);
});
console.log(passed + ' dialog tests passed.');
