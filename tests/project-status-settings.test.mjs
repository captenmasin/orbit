import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';

const helpers = { exports: {} };
runInNewContext(ts.transpileModule(readFileSync(new URL('../resources/js/lib/project.ts', import.meta.url), 'utf8'), { compilerOptions: { module: ts.ModuleKind.CommonJS } }).outputText, helpers);

function mount(t) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectStatusSettings.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'status-settings-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const events = [], toasts = [], reloads = [];
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia, '@/lib/project': helpers.exports, 'vue-sonner': { toast: { success: message => toasts.push(message) } } };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    t.mock.method(inertia.router, 'reload', options => reloads.push(options));
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const props = vue.reactive({ revision: 1, names: ['Idea', 'Live', 'Paused'], colors: { Idea: 'purple', Live: 'green', Paused: 'amber' }, usage: [{ name: 'Paused', count: 2 }] });
    const state = scope.run(() => context.exports.default.setup(props, { expose() {}, emit: (...args) => events.push(args) }));
    return { ...state, props, events, toasts, reloads };
}
const plain = value => JSON.parse(JSON.stringify(value));

test('status edits keep reassignment targets through renaming and reordering, then reset after saving', async t => {
    const state = mount(t);
    const requests = [];
    t.mock.method(inertia.http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify({ preferences: { revision: 2, values: { project_statuses: { names: ['Shipped', 'Planning', 'Testing'], colors: { Shipped: 'blue', Planning: 'purple', Testing: 'pink' } } } } }), headers: {} };
    });
    state.remove(2);
    state.removed.value[0].replacement = state.statusKey(state.form.statuses[1]);
    state.form.statuses[1].name = 'Shipped';
    state.form.statuses[1].color = 'blue';
    state.form.statuses[0].name = 'Planning';
    state.form.statuses = [state.form.statuses[1], state.form.statuses[0]];
    state.form.statuses.push({ original: null, name: 'Testing', color: 'pink' });

    await state.save();

    assert.equal(requests[0].url, '/settings/project_statuses');
    assert.deepEqual(JSON.parse(requests[0].data), { revision: 1, statuses: [
        { original: 'Live', name: 'Shipped', color: 'blue' }, { original: 'Idea', name: 'Planning', color: 'purple' }, { original: null, name: 'Testing', color: 'pink' },
    ], replacements: [{ original: 'Paused', replacement: 'Shipped' }] });
    assert.deepEqual(plain(state.form.statuses), [['Shipped', 'blue'], ['Planning', 'purple'], ['Testing', 'pink']].map(([name, color]) => ({ original: name, name, color })));
    assert.equal(state.removed.value.length, 0);
    assert.equal(state.form.isDirty, false);
    assert.deepEqual(state.events, [['saved', 2]]);
    assert.deepEqual(state.toasts, ['Project statuses saved.']);
    assert.deepEqual(Array.from(state.reloads[0].only), ['preferences', 'sidebarProjects', 'statuses', 'statusColors', 'statusUsage']);
});

test('keyboard reordering keeps row identity and focus and stops while saving or awaiting reload', async t => {
    const state = mount(t);
    const status = state.form.statuses[1];
    const key = state.statusKey(status);
    const handle = { focus: t.mock.fn() };

    await state.move(1, -1, { currentTarget: handle });

    assert.equal(state.form.statuses[0], status);
    assert.equal(state.statusKey(state.form.statuses[0]), key);
    assert.equal(state.announcement.value, 'Live moved to position 1 of 3.');
    assert.equal(handle.focus.mock.callCount(), 1);

    state.form.processing = true;
    await state.move(0, 1);
    state.form.processing = false;
    state.needsReload.value = true;
    await state.move(0, 1);
    assert.deepEqual(Array.from(state.form.statuses, status => status.name), ['Live', 'Idea', 'Paused']);
});

test('removing a replacement clears it, undo restores the row, and the last status cannot be removed', t => {
    const state = mount(t);
    state.remove(2);
    state.removed.value[0].replacement = state.statusKey(state.form.statuses[1]);
    state.remove(1);
    assert.equal(state.removed.value[0].replacement, '');
    state.remove(0);
    assert.equal(state.form.statuses.length, 1);
    state.undo(1);
    assert.deepEqual(Array.from(state.form.statuses, status => status.name), ['Idea', 'Live']);
    state.move(0, -1);
    state.move(1, 1);
    assert.deepEqual(Array.from(state.form.statuses, status => status.name), ['Idea', 'Live']);
});

test('validation errors preserve edits and conflicts require a reload before saving again', async t => {
    const state = mount(t);
    let status = 422, requests = 0;
    t.mock.method(inertia.http.getClient(), 'request', async () => {
        requests++;
        return { status, data: JSON.stringify({ message: 'Settings changed.', errors: { replacements: ['Choose a replacement.'] } }), headers: {} };
    });
    state.remove(2);
    state.form.statuses[0].name = 'Planning';
    await state.save();
    assert.equal(state.form.errors.replacements, 'Choose a replacement.');
    assert.equal(state.form.statuses[0].name, 'Planning');
    assert.equal(state.removed.value.length, 1);
    status = 409;
    await state.save();
    await state.save();
    assert.equal(requests, 2);
    assert.equal(state.needsReload.value, true);
    assert.deepEqual(state.events, []);
    state.reload();
    state.props.names = ['Research'];
    state.props.colors = { Research: 'amber' };
    state.props.revision = 8;
    await vue.nextTick();
    state.reloads[0].onSuccess();
    assert.deepEqual(plain(state.form.statuses), [{ original: 'Research', name: 'Research', color: 'amber' }]);
    assert.equal(state.form.revision, 8);
    assert.equal(state.needsReload.value, false);
    assert.equal(state.removed.value.length, 0);
});

test('status dots follow shared colours and use grey for archived and unknown statuses', async () => {
    const page = vue.reactive({ props: { statusColors: { Planning: 'pink' } } });
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectStatusDot.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'status-dot-test', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue, '@inertiajs/vue3': { usePage: () => page }, '@/lib/project': helpers.exports };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const render = status => renderToString(vue.createSSRApp(context.exports.default, { status, class: 'size-2' }));

    assert.match(await render('Planning'), /bg-pink-500\/80/);
    page.props.statusColors = { Planning: 'blue' };
    assert.match(await render('Planning'), /bg-blue-500\/70/);
    assert.match(await render('Archived'), /bg-neutral-500/);
    assert.match(await render('Unknown'), /bg-neutral-500/);
});
