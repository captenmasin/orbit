import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse, registerTS } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import * as vueuse from '@vueuse/core';
import * as lucide from '@lucide/vue';
import * as reka from 'reka-ui';
import ts from 'typescript';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';

const { http } = inertia;

function mount(t, props = {}, fetch = async () => ({})) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectSecrets.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'secrets-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const focused = vue.ref(true);
    const visible = vue.ref('visible');
    const toasts = [];
    const successToasts = [];
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia, '@vueuse/core': { ...vueuse, useWindowFocus: () => focused, useDocumentVisibility: () => visible }, 'vue-sonner': { toast: { error: message => toasts.push(message), success: message => successToasts.push(message) } } };
    const context = { exports: {}, require: name => modules[name] ?? {}, setTimeout, clearTimeout, AbortController, Date, fetch, document: { cookie: '', getElementById: () => ({ focus() {} }) } };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const state = scope.run(() => context.exports.default.setup({ project: { id: 'project', revision: 3, secrets: [] }, native: true, ...props }, { expose() {} }));
    state.vaultLoading.value = false;
    return { ...state, toasts, successToasts, scope };
}

async function settle() {
    await vue.nextTick();
    await new Promise(resolve => setImmediate(resolve));
}

test('native file picker and focus changes keep the vault unlocked', async t => {
    let resolvePreview;
    t.mock.method(http.getClient(), 'request', () => new Promise(resolve => { resolvePreview = resolve; }));
    const state = mount(t);
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    state.openImport();

    const preview = state.previewImport();
    await vue.nextTick();
    assert.equal(state.importPreviewForm.processing, true);
    state.focused.value = false;
    await vue.nextTick();
    assert.equal(state.unlocked.value, true);

    resolvePreview({ status: 200, data: JSON.stringify({ preview: { source: '.env', entries: [{ name: 'APP_NAME', collision: false }] } }), headers: {} });
    await preview;
    state.focused.value = true;
    await vue.nextTick();
    assert.equal(state.unlocked.value, true);
    assert.equal(state.importPreview.value.entries[0].name, 'APP_NAME');
});

test('env import keeps the chosen environment and shows saved entries after success', async t => {
    const state = mount(t);
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(JSON.parse(request.data));
        return { status: 200, data: JSON.stringify(request.url.endsWith('/preview')
            ? { preview: { source: '.env', entries: [{ name: 'APP_NAME', collision: false }] } }
            : { saved: true, imported: 1, skipped: 0 }), headers: {} };
    });
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });
    state.openImport();
    state.query.value = 'unrelated';
    state.importPreviewForm.environment = 'Production';

    await state.previewImport();
    await state.importEntries();

    assert.equal(requests[0].environment, 'Production');
    assert.equal(requests[1].environment, 'Production');
    assert.equal(requests[1].project_revision, 3);
    assert.equal(state.environment.value, 'Production');
    assert.equal(state.query.value, '');
    assert.equal(state.importOpen.value, false);
    assert.deepEqual(state.successToasts, ['1 secret imported. 0 existing entries skipped.']);
    assert.equal(reloads, 1);
});

test('rejected env imports keep the dialog open and display the server error', async t => {
    const state = mount(t);
    t.mock.method(http.getClient(), 'request', async () => ({ status: 422,
        data: JSON.stringify({ errors: { secret: 'Native credential storage is unavailable.' } }), headers: {} }));
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });
    state.importOpen.value = true;
    state.importPreview.value = { source: '.env', entries: [{ name: 'APP_NAME', collision: false }] };

    await state.importEntries();

    assert.equal(state.importOpen.value, true);
    assert.match(state.error.value, /Native credential storage is unavailable/);
    assert.equal(reloads, 0);
});

test('expired PIN closes the import preview and prompts for unlock', async t => {
    const state = mount(t);
    t.mock.method(http.getClient(), 'request', async () => ({ status: 423, data: JSON.stringify({ message: 'Unlock secrets with your PIN.' }), headers: {} }));
    state.unlocked.value = true;
    state.importOpen.value = true;
    state.importPreview.value = { source: '.env', entries: [{ name: 'APP_NAME', collision: false }] };

    await state.importEntries();

    assert.equal(state.unlocked.value, false);
    assert.equal(state.importOpen.value, false);
    assert.match(state.error.value, /Unlock with your PIN/);
});

test('other secret actions also retain rejected submissions without reporting success', async t => {
    const state = mount(t);
    t.mock.method(http.getClient(), 'request', async () => ({ status: 422,
        data: JSON.stringify({ errors: { secret: 'Unable to save secrets.' } }), headers: {} }));
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });
    state.edit();
    await state.save();
    assert.equal(state.editorOpen.value, true);
    state.pasteOpen.value = true;
    await state.paste();
    assert.equal(state.pasteOpen.value, true);
    state.exportOpen.value = true;
    state.exportPreview.value = { destination: '.env', exists: false };
    await state.exportEntries();
    assert.equal(state.exportOpen.value, true);
    state.removing.value = { id: 'secret', revision: 1 };
    await state.remove();
    assert.equal(state.removing.value.id, 'secret');
    await state.copy({ id: 'secret', revision: 1 });
    assert.deepEqual(state.successToasts, []);
    assert.deepEqual(state.toasts, ['Unable to save secrets.', 'Unable to save secrets.']);
    assert.equal(reloads, 0);
});

test('copying a secret shows a toast and never schedules clipboard clearing', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify({ copied: true }), headers: {} };
    });
    const state = mount(t);

    await state.copy({ id: 'secret', name: 'API_KEY', revision: 2 });
    t.mock.timers.tick(31000);
    await settle();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/projects/project/secrets/secret/copy');
    assert.equal(requests[0].method, 'post');
    assert.equal(JSON.parse(requests[0].data).revision, 2);
    assert.deepEqual(state.successToasts, ['API_KEY copied to clipboard.']);
    assert.deepEqual(state.toasts, []);
});

test('successful export closes the dialog and reports success in a toast', async t => {
    t.mock.method(http.getClient(), 'request', async () => ({ status: 200, data: JSON.stringify({ saved: true }), headers: {} }));
    const state = mount(t);
    state.exportOpen.value = true;
    state.exportPreview.value = { destination: '.env', exists: false };

    await state.exportEntries();

    assert.equal(state.exportOpen.value, false);
    assert.deepEqual(state.successToasts, ['Secrets exported.']);
});


test('service and environment filters combine and include non-secret context', t => {
    const state = mount(t, { project: { id: 'project', revision: 3, secrets: [
        { id: 'a', name: 'KEY', environment: 'Production', service: 'Stripe', description: 'Billing dashboard' },
        { id: 'b', name: 'KEY', environment: 'Local', service: 'Stripe', description: 'Billing dashboard' },
        { id: 'c', name: 'KEY', environment: 'Production', service: 'Slack', description: 'Messages' },
    ] } });
    state.environment.value = 'Production';
    state.service.value = 'Stripe';
    state.query.value = 'billing';
    assert.deepEqual(Array.from(state.rows.value, item => item.id), ['a']);
    state.query.value = 'messages';
    assert.equal(state.rows.value.length, 0);
});

test('service groups normalize names, place unassigned last and follow the active filters', t => {
    const state = mount(t, { project: { id: 'project', revision: 3, secrets: [
        { id: 'a', name: 'ZEBRA_KEY', environment: 'Production', service: 'Zebra' },
        { id: 'b', name: 'STRIPE_KEY', environment: 'Production', service: 'Stripe', description: 'Billing dashboard' },
        { id: 'c', name: 'STRIPE_LOCAL', environment: 'Local', service: ' Stripe ', description: 'Billing dashboard' },
        { id: 'd', name: 'AWS_KEY', environment: 'Production', service: 'AWS' },
        { id: 'e', name: 'UNASSIGNED_KEY', environment: 'Production', service: null },
        { id: 'f', name: 'BLANK_SERVICE_KEY', environment: 'Production', service: '   ' },
    ] } });
    const groups = () => Array.from(state.serviceGroups.value, group => [group.service, Array.from(group.secrets, secret => secret.id)]);

    assert.deepEqual(groups(), [['AWS', ['d']], ['Stripe', ['b', 'c']], ['Zebra', ['a']], ['', ['e', 'f']]]);
    state.environment.value = 'Production';
    state.query.value = 'billing';
    assert.deepEqual(groups(), [['Stripe', ['b']]]);
    state.query.value = '';
    state.service.value = 'AWS';
    assert.deepEqual(groups(), [['AWS', ['d']]]);
    state.query.value = 'no matching key';
    assert.deepEqual(groups(), []);
});

test('service collapse stays independent of active details and selection including select all', t => {
    const state = mount(t, { project: { id: 'project', revision: 3, secrets: [
        { id: 'a', name: 'STRIPE_KEY', environment: 'Production', service: 'Stripe' },
        { id: 'b', name: 'AWS_KEY', environment: 'Production', service: 'AWS' },
        { id: 'c', name: 'UNASSIGNED_KEY', environment: 'Production', service: null },
    ] } });
    assert.equal(state.collapsedServices.value.length, 0);
    state.toggleSelected('a');
    state.toggleServiceGroup('Stripe', false);
    state.toggleServiceGroup('Stripe', false);
    state.toggleServiceGroup('AWS', false);

    assert.deepEqual(Array.from(state.collapsedServices.value), ['Stripe', 'AWS']);
    assert.equal(state.activeSecret.value.id, 'a');
    assert.deepEqual(Array.from(state.selectedIds.value), ['a']);
    state.toggleVisible();
    assert.deepEqual(Array.from(state.selectedIds.value), ['a', 'b', 'c']);
    assert.equal(state.selectAllState.value, true);

    state.toggleServiceGroup('Stripe', true);
    assert.deepEqual(Array.from(state.collapsedServices.value), ['AWS']);
    state.toggleServiceGroup('', false);
    assert.deepEqual(Array.from(state.collapsedServices.value), ['AWS', '']);
    assert.equal(state.activeSecret.value.id, 'a');
    state.toggleVisible();
    assert.equal(state.selectedIds.value.length, 0);
});

test('search changes reopen service groups to expose matching secrets', async t => {
    const state = mount(t, { project: { id: 'project', revision: 3, secrets: [
        { id: 'a', name: 'STRIPE_KEY', environment: 'Production', service: 'Stripe', description: 'Billing dashboard' },
        { id: 'b', name: 'AWS_KEY', environment: 'Production', service: 'AWS' },
    ] } });
    state.toggleServiceGroup('Stripe', false);
    state.toggleServiceGroup('AWS', false);
    state.toggleSelected('b');

    await state.filterSecrets('query', 'billing');
    await vue.nextTick();

    assert.equal(state.collapsedServices.value.length, 0);
    assert.deepEqual(Array.from(state.serviceGroups.value, group => group.service), ['Stripe']);
    assert.deepEqual(Array.from(state.selectedIds.value), ['b']);
    state.toggleServiceGroup('Stripe', false);
    await state.filterSecrets('query', '');
    await vue.nextTick();
    assert.equal(state.collapsedServices.value.length, 0);
    assert.deepEqual(Array.from(state.serviceGroups.value, group => group.service), ['AWS', 'Stripe']);
});

test('select all reflects filtered selection and keeps hidden selections while removing stale ids', async t => {
    const project = vue.reactive({ id: 'project', revision: 3, secrets: [
        { id: 'a', name: 'FIRST_KEY', environment: 'Production' },
        { id: 'b', name: 'SECOND_KEY', environment: 'Production' },
        { id: 'c', name: 'THIRD_KEY', environment: 'Local' },
    ] });
    const state = mount(t, { project });
    state.toggleSelected('c');
    state.environment.value = 'Production';
    assert.equal(state.selectAllState.value, false);

    state.toggleSelected('a');
    assert.equal(state.selectAllState.value, 'indeterminate');
    state.toggleVisible();
    assert.equal(state.selectAllState.value, true);
    assert.deepEqual(Array.from(state.selectedIds.value).sort(), ['a', 'b', 'c']);

    project.secrets = [...project.secrets, { id: 'd', name: 'FOURTH_KEY', environment: 'Production' }];
    assert.equal(state.selectAllState.value, 'indeterminate');
    state.toggleVisible();
    assert.equal(state.selectAllState.value, true);
    state.toggleVisible();
    assert.equal(state.selectAllState.value, false);
    assert.deepEqual(Array.from(state.selectedIds.value), ['c']);

    state.selectedIds.value = ['a', 'c', 'missing'];
    project.secrets = project.secrets.filter(secret => secret.id !== 'c');
    await vue.nextTick();
    assert.deepEqual(Array.from(state.selectedIds.value), ['a']);
    state.query.value = 'no matching key';
    assert.equal(state.selectAllState.value, false);
    state.toggleVisible();
    assert.deepEqual(Array.from(state.selectedIds.value), ['a']);
});

test('bulk deletion sends the confirmed selection and flushed revisions then clears deleted values', async t => {
    const project = vue.reactive({ id: 'project', revision: 3, secrets: [
        { id: 'a', revision: 2, name: 'FIRST_KEY', environment: 'Production' },
        { id: 'b', revision: 5, name: 'SECOND_KEY', environment: 'Production' },
        { id: 'c', revision: 1, name: 'THIRD_KEY', environment: 'Local' },
    ] });
    const state = mount(t, { project }, async () => ({ ok: true, json: async () => ({ values: {} }) }));
    state.selectedIds.value = ['a', 'b'];
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    state.description.value = { dirty: true, saving: false, flush: async () => {
        project.revision = 4;
        project.secrets = project.secrets.map(secret => secret.id === 'a' ? { ...secret, revision: 3 } : secret);
        return true;
    } };
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify({ removed: true, deleted: 2 }), headers: {} };
    });
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });

    await state.confirmBulkRemoval();
    assert.equal(state.bulkRemoveOpen.value, true);
    await settle();
    state.selectedIds.value = ['c'];
    state.values.value = { a: 'first-value', b: 'second-value', c: 'retained-value' };
    await state.removeSelected();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'delete');
    assert.equal(requests[0].url, '/projects/project/secrets/bulk');
    assert.deepEqual(JSON.parse(requests[0].data), { project_revision: 4, secrets: [{ id: 'a', revision: 3 }, { id: 'b', revision: 5 }] });
    assert.equal(state.bulkRemoveOpen.value, false);
    assert.equal(state.selectedIds.value.length, 0);
    assert.deepEqual(Object.keys(state.values.value), ['c']);
    assert.equal(state.successToasts.length, 1);
    assert.match(state.successToasts[0], /2.*deleted|deleted.*2/i);
    assert.equal(reloads, 1);
});

test('failed description flushing or bulk deletion preserves the selected secrets', async t => {
    const state = mount(t, { project: { id: 'project', revision: 3, secrets: [{ id: 'a', revision: 2, name: 'KEY', environment: 'Production' }] } });
    state.selectedIds.value = ['a'];
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    state.description.value = { dirty: true, saving: false, flush: async () => false };
    let requests = 0;
    t.mock.method(http.getClient(), 'request', async () => {
        requests++;
        return { status: 422, data: JSON.stringify({ errors: { secrets: 'The selected secrets could not be deleted.' } }), headers: {} };
    });
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });

    await state.confirmBulkRemoval();
    assert.equal(state.bulkRemoveOpen.value, false);
    assert.deepEqual(Array.from(state.selectedIds.value), ['a']);
    assert.equal(requests, 0);
    state.description.value = null;
    await state.confirmBulkRemoval();
    await state.removeSelected();

    assert.equal(requests, 1);
    assert.equal(state.bulkRemoveOpen.value, true);
    assert.deepEqual(Array.from(state.selectedIds.value), ['a']);
    assert.equal(state.toasts.length, 1);
    assert.match(state.toasts[0], /deleted/i);
    assert.deepEqual(state.successToasts, []);
    assert.equal(reloads, 0);
});

test('the shared indeterminate checkbox renders a mixed state with a minus indicator', async () => {
    registerTS(() => ts);
    const source = new URL('../resources/js/components/ui/checkbox/Checkbox.vue', import.meta.url);
    const { descriptor } = parse(readFileSync(source, 'utf8'), { filename: source.pathname });
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'checkbox-test', inlineTemplate: true, fs: ts.sys }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const modules = { vue, '@lucide/vue': lucide, '@vueuse/core': vueuse, 'reka-ui': reka, '@/lib/utils': { cn: (...classes) => classes.filter(Boolean).join(' ') } };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);

    const html = await renderToString(vue.createSSRApp(context.exports.default, { modelValue: 'indeterminate', 'aria-label': 'Select visible secrets' }));

    assert.match(html, /aria-checked="mixed"/);
    assert.match(html, /data-state="indeterminate"/);
    assert.match(html, /lucide-minus/);
    assert.doesNotMatch(html, /lucide-check/);
});

test('the details pane follows selection and available rows while masking each newly selected value', t => {
    const project = vue.reactive({ id: 'project', revision: 3, secrets: [
        { id: 'a', name: 'FIRST_KEY', environment: 'Production' },
        { id: 'b', name: 'SECOND_KEY', environment: 'Local' },
        { id: 'c', name: 'THIRD_KEY', environment: 'Production' },
    ] });
    const state = mount(t, { project });
    assert.equal(state.activeSecret.value.id, 'a');
    assert.equal(state.valueRevealed.value, false);

    state.valueRevealed.value = true;
    state.activeSecretId.value = 'b';
    assert.equal(state.activeSecret.value.id, 'b');
    assert.equal(state.valueRevealed.value, false);

    state.valueRevealed.value = true;
    state.environment.value = 'Production';
    assert.equal(state.activeSecret.value.id, 'a');
    assert.equal(state.valueRevealed.value, false);

    state.activeSecretId.value = 'c';
    assert.equal(state.activeSecret.value.id, 'c');
    state.valueRevealed.value = true;
    project.secrets = project.secrets.filter(secret => secret.id !== 'c');
    assert.equal(state.activeSecret.value.id, 'a');
    assert.equal(state.valueRevealed.value, false);

    state.valueRevealed.value = true;
    state.query.value = 'no matching key';
    assert.equal(state.activeSecret.value, null);
    assert.equal(state.valueRevealed.value, false);
    assert.equal(mount(t).activeSecret.value, null);
});

test('description failures protect selection and editing while manual lock stays immediate', async t => {
    const project = vue.reactive({ id: 'project', revision: 3, secrets: [
        { id: 'a', revision: 2, name: 'FIRST_KEY', environment: 'Production' },
        { id: 'b', revision: 1, name: 'SECOND_KEY', environment: 'Local' },
    ] });
    const state = mount(t, { project });
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    state.description.value = { dirty: true, saving: false, flush: async () => false };
    t.mock.method(http.getClient(), 'request', () => assert.fail('A failed description save must block editing.'));

    await state.selectSecret('b');
    await state.filterSecrets('query', 'SECOND');
    await state.edit(project.secrets[0]);

    assert.equal(state.activeSecret.value.id, 'a');
    assert.equal(state.query.value, '');
    assert.equal(state.unlocked.value, true);
    assert.equal(state.editorOpen.value, false);
    assert.equal(await state.flushDescription(), false);

    state.lock();
    assert.equal(state.unlocked.value, false);
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    state.description.value.flush = () => new Promise(() => {});
    state.lock();
    assert.equal(state.unlocked.value, false);
});

test('a removed secret stays active while its description draft is unsaved', t => {
    const project = vue.reactive({ id: 'project', revision: 3, secrets: [
        { id: 'a', revision: 2, name: 'FIRST_KEY', environment: 'Production', description: 'Original description' },
        { id: 'b', revision: 1, name: 'SECOND_KEY', environment: 'Local' },
    ] });
    const state = mount(t, { project });
    state.description.value = { dirty: true, saving: false, flush: async () => false };

    project.secrets = project.secrets.filter(secret => secret.id !== 'a');

    assert.equal(state.activeSecret.value.id, 'a');
    assert.equal(state.activeSecret.value.description, 'Original description');
    state.description.value.dirty = false;
    assert.equal(state.activeSecret.value.id, 'b');
});

test('Edit uses the updated secret and project revisions after flushing its description', async t => {
    const original = { id: 'secret', revision: 2, name: 'API_KEY', environment: 'Production', description: 'Original description' };
    const project = vue.reactive({ id: 'project', revision: 3, secrets: [original] });
    const state = mount(t, { project }, async () => ({ ok: true, json: async () => ({ values: { secret: 'current-value' } }) }));
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    let request;
    t.mock.method(http.getClient(), 'request', async value => { request = value; return { status: 200, data: JSON.stringify({ value: 'current-value' }), headers: {} }; });
    state.description.value = { dirty: true, saving: false, flush: async () => {
        project.revision = 4;
        project.secrets = [{ ...original, revision: 3, description: 'Saved description' }];
        return true;
    } };

    await state.edit(original);

    assert.equal(new URL(request.url, 'https://orbit.test').searchParams.get('revision'), '3');
    assert.equal(state.form.project_revision, 4);
    assert.equal(state.form.revision, 3);
    assert.equal(state.form.description, 'Saved description');
    assert.equal(state.form.value, 'current-value');
});

test('secret search opens metadata without revealing a value and handles removed targets', t => {
    const secret = { id: 'secret', name: 'KEY', environment: 'Production', service: 'Stripe', description: 'Billing dashboard', revision: 1 };
    const state = mount(t, { project: { id: 'project', revision: 3, secrets: [secret] }, native: false, targetSecretId: 'secret' });
    assert.equal(state.editorOpen.value, true);
    assert.equal(state.metadataOnly.value, true);
    assert.equal(state.editing.value.id, 'secret');
    assert.equal(state.form.value, '');
    assert.equal(state.form.service, 'Stripe');
    const removed = mount(t, { targetSecretId: 'removed' });
    assert.equal(removed.editorOpen.value, false);
    assert.deepEqual(removed.toasts, ['This secret no longer exists in this project.']);
});

test('context edits use the metadata endpoint independently of value replacement', async t => {
    const state = mount(t);
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify({ saved: true }), headers: {} };
    });
    t.mock.method(inertia.router, 'reload', () => {});
    state.edit({ id: 'secret', revision: 2, name: 'KEY', environment: 'Production', service: 'Stripe' }, true);
    state.form.description = 'Billing dashboard';
    await state.save();
    assert.equal(requests[0].url, '/projects/project/secrets/secret/metadata');
    assert.equal(JSON.parse(requests[0].data).description, 'Billing dashboard');
    assert.equal(JSON.parse(requests[0].data).revision, 2);
    assert.equal(state.editorOpen.value, false);
});

test('editing preloads the current value and saves every secret field together', async t => {
    const secret = { id: 'secret', revision: 2, name: 'API_KEY', environment: 'Production', service: 'Stripe', description: 'Billing dashboard', management_url: 'https://dashboard.stripe.com' };
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        if (requests.length === 2) return { status: 422, data: JSON.stringify({ errors: { name: 'Use a valid environment variable name.' } }), headers: {} };
        return { status: 200, data: JSON.stringify(request.method === 'get' ? { value: 'current-value' } : { saved: true }), headers: {} };
    });
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });
    const state = mount(t);
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);

    await state.edit(secret);

    const preloadUrl = new URL(requests[0].url, 'https://orbit.test');
    assert.equal(requests[0].method, 'get');
    assert.equal(preloadUrl.pathname, '/projects/project/secrets/secret/value');
    assert.equal(preloadUrl.searchParams.get('revision'), '2');
    assert.equal(state.editorOpen.value, true);
    assert.equal(state.editorReady.value, true);
    assert.equal(state.metadataOnly.value, false);
    assert.equal(state.form.name, 'API_KEY');
    assert.equal(state.form.environment, 'Production');
    assert.equal(state.form.service, 'Stripe');
    assert.equal(state.form.description, 'Billing dashboard');
    assert.equal(state.form.management_url, 'https://dashboard.stripe.com');
    assert.equal(state.form.value, 'current-value');
    assert.equal(state.editorValue.response, null);

    Object.assign(state.form, { name: 'INVALID NAME', environment: 'Staging', service: 'Billing', description: 'New description', management_url: 'https://example.com/manage' });
    await state.save();
    assert.equal(state.editorOpen.value, true);
    assert.equal(state.form.value, 'current-value');

    state.form.name = 'BILLING_KEY';
    state.form.value = 'updated-value';
    await state.save();

    assert.equal(requests.length, 3);
    assert.equal(requests[2].method, 'put');
    assert.equal(requests[2].url, '/projects/project/secrets/secret');
    assert.deepEqual(JSON.parse(requests[2].data), { environment: 'Staging', name: 'BILLING_KEY', value: 'updated-value', service: 'Billing', description: 'New description', management_url: 'https://example.com/manage', project_revision: 3, revision: 2 });
    assert.equal(state.editorOpen.value, false);
    assert.equal(state.editorReady.value, false);
    assert.equal(state.form.value, '');
    assert.equal(reloads, 1);
});

test('a pending or rejected current-value load cannot submit an edit', async t => {
    const requests = [];
    let resolveValue;
    t.mock.method(http.getClient(), 'request', request => {
        requests.push(request);
        return request.method === 'get'
            ? new Promise(resolve => { resolveValue = resolve; })
            : Promise.resolve({ status: 200, data: JSON.stringify({ saved: true }), headers: {} });
    });
    const state = mount(t);
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    const preload = state.edit({ id: 'secret', revision: 2, name: 'API_KEY', environment: 'Production' });

    await state.save();
    assert.equal(requests.length, 1);
    assert.equal(state.editorReady.value, false);

    resolveValue({ status: 422, data: JSON.stringify({ errors: { secret: 'Credential could not be read.' } }), headers: {} });
    await preload;
    await state.save();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'get');
    assert.equal(state.editorReady.value, false);
    assert.equal(state.form.value, '');
});

for (const close of ['closeEditor', 'lockVault']) {
    test(`${close} cancels a pending edit and rejects late plaintext`, async t => {
        let request;
        let resolveValue;
        t.mock.method(http.getClient(), 'request', value => {
            request = value;
            return new Promise(resolve => { resolveValue = resolve; });
        });
        const state = mount(t);
        state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
        const preload = state.edit({ id: 'secret', revision: 2, name: 'API_KEY', environment: 'Production' });

        state[close]();
        resolveValue({ status: 200, data: JSON.stringify({ value: 'late-value' }), headers: {} });
        await preload;

        assert.equal(request.signal.aborted, true);
        assert.equal(state.editorOpen.value, false);
        assert.equal(state.editorReady.value, false);
        assert.equal(state.form.value, '');
        assert.equal(state.editorValue.response, null);
    });
}

test('a rejected PIN clears the pending unlock state and entered digits', async t => {
    const state = mount(t);
    state.pinSet.value = true;
    state.pinForm.pin = '9999';
    t.mock.method(http.getClient(), 'request', async () => ({ status: 422,
        data: JSON.stringify({ errors: { pin: 'Incorrect PIN.' } }), headers: {} }));

    await settle();

    assert.equal(state.unlocking.value, false);
    assert.equal(state.unlocked.value, false);
    assert.equal(state.pinForm.pin, '');
    assert.equal(state.error.value, 'Incorrect PIN.');
});

test('completing the PIN unlocks automatically without duplicate requests', async t => {
    const until = Math.floor(Date.now() / 1000) + 28800;
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify({ unlocked: true, unlocked_until: until }), headers: {} };
    });
    const state = mount(t, {}, async () => ({ ok: true, json: async () => ({ values: { secret: 'value' } }) }));
    state.pinSet.value = true;
    state.pinForm.pin = '012';
    await settle();
    assert.equal(requests.length, 0);

    state.pinForm.pin = '0123';
    await vue.nextTick();
    await state.unlockVault();
    await settle();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/secrets/unlock');
    assert.equal(JSON.parse(requests[0].data).pin, '0123');
    assert.equal(state.unlocked.value, true);
    assert.equal(state.values.value.secret, 'value');
    assert.equal(state.pinForm.pin, '');
});

test('projects without a PIN cannot submit an unlock or set a project PIN', async t => {
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify({ unlocked: true, unlocked_until: Math.floor(Date.now() / 1000) + 28800 }), headers: {} };
    });
    const state = mount(t);
    state.pinForm.pin = '0123';
    await settle();
    await state.unlockVault();
    await settle();

    assert.equal(requests.length, 0);
    assert.equal(state.unlocked.value, false);
    assert.equal(state.pinSet.value, false);
});

test('rate limited PINs show feedback and wait for another entry', async t => {
    let requests = 0;
    t.mock.method(http.getClient(), 'request', async () => {
        requests++;
        return { status: 429, data: JSON.stringify({ message: 'Too many attempts. Try again in 30 seconds.' }), headers: {} };
    });
    const state = mount(t);
    state.pinSet.value = true;

    state.pinForm.pin = '9999';
    await settle();

    assert.equal(requests, 1);
    assert.equal(state.unlocked.value, false);
    assert.equal(state.unlocking.value, false);
    assert.equal(state.pinForm.pin, '');
    assert.match(state.error.value, /Too many attempts/);
});

test('refresh restores the server session and keeps its original expiry', async t => {
    const now = Date.UTC(2026, 8, 26, 8);
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now });
    const requests = [];
    const until = now / 1000 + 60;
    const state = mount(t, {}, async url => {
        requests.push(url);
        return { ok: true, json: async () => url.endsWith('/status')
            ? { pin_set: true, unlocked: true, unlocked_until: until }
            : { values: { secret: 'value' } } };
    });

    await state.refreshVaultStatus();
    state.focused.value = false;
    state.visible.value = 'hidden';
    await vue.nextTick();
    t.mock.timers.tick(59000);
    state.focused.value = true;
    state.visible.value = 'visible';
    await settle();

    assert.equal(state.unlocked.value, true);
    assert.equal(state.unlockedUntil.value, until * 1000);
    assert.equal(state.values.value.secret, 'value');
    assert.deepEqual(requests, ['/secrets/unlock/status', '/projects/project/secrets/values', '/secrets/unlock/status', '/projects/project/secrets/values']);

    state.valueRevealed.value = true;
    t.mock.timers.tick(1000);

    assert.equal(state.unlocked.value, false);
    assert.equal(state.valueRevealed.value, false);
    assert.equal(Object.keys(state.values.value).length, 0);
});

test('server revocation clears displayed and editable plaintext when the window regains focus', async t => {
    const state = mount(t, {}, async () => ({ ok: true, json: async () => ({ pin_set: true, unlocked: false, unlocked_until: null }) }));
    state.resumeVault(Math.floor(Date.now() / 1000) + 3600);
    state.values.value = { secret: 'revealed-secret' };
    state.valueRevealed.value = true;
    state.form.value = 'edited-secret';
    state.pasteForm.entries = 'KEY=pasted-secret';
    state.pasteOpen.value = true;
    state.focused.value = false;
    await vue.nextTick();

    state.focused.value = true;
    await settle();

    assert.equal(state.unlocked.value, false);
    assert.equal(state.valueRevealed.value, false);
    assert.equal(Object.keys(state.values.value).length, 0);
    assert.equal(state.form.value, '');
    assert.equal(state.pasteForm.entries, '');
    assert.equal(state.pasteOpen.value, false);
});

test('a locked vault rejects a delayed status response without requesting plaintext', async t => {
    let finish;
    let requests = 0;
    const state = mount(t, {}, async () => {
        requests++;
        return new Promise(resolve => { finish = resolve; });
    });
    const refresh = state.refreshVaultStatus();
    state.clearVault();

    finish({ ok: true, json: async () => ({ pin_set: true, unlocked: true, unlocked_until: Math.floor(Date.now() / 1000) + 3600 }) });
    await refresh;

    assert.equal(requests, 1);
    assert.equal(state.unlocked.value, false);
    assert.equal(Object.keys(state.values.value).length, 0);
});

test('expiration while plaintext is loading cannot display the late response', async t => {
    const now = Date.UTC(2026, 8, 26, 8);
    t.mock.timers.enable({ apis: ['setTimeout', 'Date'], now });
    let finish;
    const state = mount(t, {}, url => url.endsWith('/status')
        ? Promise.resolve({ ok: true, json: async () => ({ pin_set: true, unlocked: true, unlocked_until: now / 1000 + 1 }) })
        : url.endsWith('/values') ? new Promise(resolve => { finish = resolve; }) : Promise.resolve({}));
    const refresh = state.refreshVaultStatus();
    await settle();
    t.mock.timers.tick(1000);

    finish({ ok: true, json: async () => ({ values: { secret: 'expired-secret' } }) });
    await refresh;

    assert.equal(state.unlocked.value, false);
    assert.equal(Object.keys(state.values.value).length, 0);
});

test('an unlock rejected while loading plaintext leaves the renderer locked', async t => {
    const state = mount(t, {}, async () => ({ ok: false, status: 423 }));
    state.pinSet.value = true;
    t.mock.method(http.getClient(), 'request', async () => ({ status: 200,
        data: JSON.stringify({ unlocked: true, unlocked_until: Math.floor(Date.now() / 1000) + 3600 }), headers: {} }));
    state.pinForm.pin = '0123';

    await settle();

    assert.equal(state.unlocked.value, false);
    assert.equal(Object.keys(state.values.value).length, 0);
    assert.match(state.error.value, /could not be loaded/);
});

test('leaving the page clears values without revoking the server unlock', async t => {
    const requests = [];
    let resolveValues;
    const state = mount(t, {}, (url, options) => {
        requests.push({ url, options });
        return new Promise(resolve => { resolveValues = resolve; });
    });
    state.resumeVault(Math.floor(Date.now() / 1000) + 28800);
    state.values.value = { secret: 'value' };
    state.valueRevealed.value = true;
    const pending = state.loadValues();

    state.scope.stop();
    resolveValues({ ok: true, json: async () => ({ values: { secret: 'late value' } }) });
    await pending;

    assert.equal(requests.length, 1);
    assert.equal(requests[0].options.signal.aborted, true);
    assert.equal(state.unlocked.value, false);
    assert.equal(state.valueRevealed.value, false);
    assert.equal(Object.keys(state.values.value).length, 0);
});

test('manual lock clears values, rejects delayed reveals and revokes the session', async t => {
    const requests = [];
    let resolveValues;
    const state = mount(t, {}, (url, options) => {
        requests.push({ url, options });
        return url.endsWith('/values') ? new Promise(resolve => { resolveValues = resolve; }) : Promise.resolve({});
    });
    state.resumeVault(Math.floor(Date.now() / 1000) + 28800);
    state.values.value = { secret: 'value' };
    state.valueRevealed.value = true;
    const pending = state.loadValues();

    state.lockVault();
    resolveValues({ ok: true, json: async () => ({ values: { secret: 'late value' } }) });
    await pending;

    assert.equal(state.unlocked.value, false);
    assert.equal(state.valueRevealed.value, false);
    assert.equal(Object.keys(state.values.value).length, 0);
    assert.equal(requests[1].url, '/secrets/lock');
    assert.equal(requests[1].options.method, 'POST');
});

test('PIN recovery clears old unlock errors and staged files before refreshing secret metadata', async t => {
    const state = mount(t);
    const reloads = [];
    t.mock.method(inertia.router, 'reload', options => reloads.push(options));
    state.pinForm.pin = '012';
    await vue.nextTick();
    state.pinForm.setError('pin', 'Incorrect PIN.');
    state.error.value = 'Incorrect PIN.';
    state.importOpen.value = true;
    state.importPreview.value = { source: '.env', entries: [] };
    state.exportOpen.value = true;
    state.exportPreview.value = { destination: '.env', exists: false };

    state.recoveredPin();

    assert.equal(state.unlocked.value, false);
    assert.equal(state.pinForm.pin, '');
    assert.equal(state.pinForm.hasErrors, false);
    assert.equal(state.error.value, '');
    assert.equal(state.importOpen.value, false);
    assert.equal(state.importPreview.value, null);
    assert.equal(state.exportOpen.value, false);
    assert.equal(state.exportPreview.value, null);
    assert.deepEqual(Array.from(reloads[0].only), ['selectedProject']);
});
