import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t, file, input, globals = {}, overrides = {}) {
    const { descriptor } = parse(readFileSync(new URL(`../resources/js/components/${file}.vue`, import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'documents-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue: { ...vue, useId: () => 'test', onMounted() {} }, '@inertiajs/vue3': { ...inertia, ...overrides }, '@/components/ui/button': { buttonVariants: () => 'shared-button' } };
    const context = { exports: {}, URL, require: name => modules[name] ?? {}, ...globals };
    runInNewContext(outputText, context);
    const props = vue.reactive(input);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    return { props, state: scope.run(() => context.exports.default.setup(props, { expose() {} })) };
}

test('documents retain their draft through conflict reloads and do not substitute removed search targets', async t => {
    const document = { id: 'first', title: 'Hosting', body: 'Saved body', revision: 1 };
    const { state, props } = mount(t, 'ProjectDocuments', { project: { id: 'project', revision: 1, documents: [document] }, targetDocumentId: 'gone' }, {}, {
        router: { on: () => () => {}, restore: () => undefined, remember() {}, reload: options => options.onSuccess() },
    });
    assert.equal(state.selected.value, undefined);
    assert.equal(state.missing.value, true);
    props.targetDocumentId = 'first';
    await vue.nextTick();
    assert.equal(state.selected.value.title, 'Hosting');
    state.edit(state.selected.value);
    state.form.title = 'Draft title';
    state.form.body = '\nUntouched draft\n';
    props.project = { id: 'project', revision: 4, documents: [{ ...document, body: 'Changed elsewhere', revision: 3 }] };
    await vue.nextTick();
    assert.equal(state.form.revision, 1);
    assert.equal(state.form.document_revision, 1);
    state.reload();
    assert.equal(state.form.revision, 4);
    assert.equal(state.form.document_revision, 3);
    assert.equal(state.form.title, 'Draft title');
    assert.equal(state.form.body, '\nUntouched draft\n');
});

test('Markdown heading controls focus headings and code copy resets its icon after feedback', async t => {
    const source = "printf 'hello'\n  ./deploy --safe\n\n";
    let copied;
    let copyFails = false;
    let button;
    let nextTimerId = 0;
    const timers = new Map();
    let focused = false;
    let scrolled = false;
    const heading = { textContent: 'Deploy', focus: () => { focused = true; }, scrollIntoView: () => { scrolled = true; } };
    const code = { textContent: source };
    const block = { querySelector: selector => selector === 'button' ? button : code, prepend: value => { button = value; } };
    const { state } = mount(t, 'MarkdownContent', { html: '<h2>Deploy</h2><pre><code>commands</code></pre>', navigation: true }, {
        navigator: { clipboard: { writeText: async text => { if (copyFails) throw new Error('Clipboard denied'); copied = text; } } },
        document: { createElement: () => ({ setAttribute() {}, firstElementChild: { dataset: { state: 'a' } } }), getElementById: () => heading },
        setTimeout: (callback, delay) => { const id = ++nextTimerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout: id => timers.delete(id),
    });
    state.content.value = { querySelectorAll: selector => selector === 'pre' ? [block] : [heading] };
    await state.enhance();
    assert.equal(state.headings.value[0].text, 'Deploy');
    state.goToHeading(state.headings.value[0].id);
    assert.equal(focused && scrolled, true);
    assert.match(button.className, /shared-button/);
    assert.match(button.className, /absolute top-2 right-2/);
    assert.match(button.innerHTML, /class="t-icon-swap" data-state="a"/);
    await button.onclick();
    assert.equal(copied, source);
    assert.equal(button.firstElementChild.dataset.state, 'b');
    assert.equal(button.title, 'Code copied');
    assert.equal(state.copyMessage.value, 'Code copied.');
    assert.equal(timers.get(1).delay, 2000);
    const firstButton = button;
    await state.enhance();
    assert.equal(button, firstButton);
    await button.onclick();
    assert.equal(timers.has(1), false);
    timers.get(2).callback();
    timers.delete(2);
    assert.equal(button.firstElementChild.dataset.state, 'a');
    assert.equal(button.title, 'Copy code');
    copyFails = true;
    await button.onclick();
    assert.equal(button.firstElementChild.dataset.state, 'a');
    assert.equal(button.title, 'Copy code');
    assert.equal(timers.size, 0);
    assert.equal(state.copyMessage.value, 'Code could not be copied. Select the code and copy it manually.');
});

test('document departures preserve drafts on stay and failed saves and clear history on discard', async t => {
    let before;
    let removed = false;
    const remembered = new Map();
    const visits = [];
    const requests = [];
    t.mock.method(inertia.router, 'put', (url, data, callbacks) => { requests.push(callbacks); });
    const { state } = mount(t, 'ProjectDocuments', { project: { id: 'a', revision: 1, documents: [] } }, {}, {
        usePage: () => ({ url: '/projects/a' }),
        router: { on: (name, listener) => { before = listener; return () => { removed = true; }; }, restore: () => undefined,
            remember: (data, key) => remembered.set(key, data), visit: url => visits.push(url) },
    });
    state.edit();
    state.form.title = 'Keep this';
    state.form.body = 'Draft body';
    await vue.nextTick();
    const departure = { detail: { visit: { method: 'get', url: '/projects/b' } } };

    assert.equal(before(departure), false);
    state.keepEditing();
    assert.equal(state.form.body, 'Draft body');
    assert.equal(visits.length, 0);
    assert.equal(remembered.get('document:a:new').data.title, 'Keep this');
    before(departure);
    state.save();
    state.form.setError('title', 'Rejected');
    assert.equal(state.departureOpen.value, true);
    assert.equal(visits.length, 0);
    assert.equal(state.form.body, 'Draft body');
    state.discardDraft();
    await vue.nextTick();
    assert.deepEqual(visits, ['/projects/b']);
    assert.equal(remembered.get('document:a:new'), null);
    assert.equal(remembered.get('document:a:active'), null);
    assert.equal(state.dirty.value, false);
    t.after(() => assert.equal(removed, true));
});

test('document history recovery checks fresh revisions and requires explicit recovery for a removed document', async t => {
    const memory = new Map([
        ['document:a:active', { id: 'doc' }],
        ['document:a:doc', { data: { action: 'save', revision: 1, id: 'doc', document_revision: 1, title: 'Recovered title', body: 'Recovered body' }, previewOpen: true, previewHtml: '<p>Recovered body</p>' }],
    ]);
    const router = { on: () => () => {}, restore: key => memory.get(key), remember: (data, key) => memory.set(key, data), reload: options => options.onSuccess() };
    const { state, props } = mount(t, 'ProjectDocuments', { project: { id: 'a', revision: 1, documents: [{ id: 'doc', revision: 1, title: 'Saved', body: 'Saved' }] } }, {}, { router });

    assert.equal(state.form.body, 'Recovered body');
    assert.equal(state.previewOpen.value, true);
    assert.equal(state.recovering.value, true);
    props.project.revision = 3;
    props.project.documents[0].revision = 2;
    state.reconcileRecovery();
    assert.match(state.form.errors.revision, /changed/);
    assert.equal(state.form.document_revision, 1);
    state.reload();
    assert.equal(state.form.document_revision, 2);
    assert.equal(state.form.body, 'Recovered body');
    props.project.documents = [];
    state.reload();
    assert.match(state.form.errors.id, /removed/);
    state.createFromDraft();
    assert.equal(state.form.id, null);
    assert.equal(state.form.body, 'Recovered body');
    assert.equal(state.form.hasErrors, false);
    const other = mount(t, 'ProjectDocuments', { project: { id: 'b', revision: 1, documents: [] } }, {}, { router });
    assert.equal(other.state.editing.value, false);
});
