import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import { http } from '@inertiajs/core';
import * as vueuse from '@vueuse/core';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t, props = {}) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectSecrets.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'secrets-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia, '@vueuse/core': vueuse };
    const context = { exports: {}, require: name => modules[name] ?? {}, setTimeout, clearTimeout };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    return scope.run(() => context.exports.default.setup({ project: { id: 'project', revision: 3, secrets: [] }, native: true, ...props }, { expose() {} }));
}

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
    assert.match(state.notice.value, /1.*imported/i);
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
    assert.equal(state.notice.value, '');
    assert.equal(state.error.value, 'Unable to save secrets.');
    assert.equal(reloads, 0);
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
    assert.match(removed.error.value, /no longer exists/);
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

test('a rejected PIN clears the pending unlock state and entered digits', async t => {
    const state = mount(t);
    state.pinSet.value = true;
    state.pinForm.pin = '9999';
    t.mock.method(http.getClient(), 'request', async () => ({ status: 422,
        data: JSON.stringify({ errors: { pin: 'Incorrect PIN.' } }), headers: {} }));

    await state.unlockVault();

    assert.equal(state.unlocking.value, false);
    assert.equal(state.unlocked.value, false);
    assert.equal(state.pinForm.pin, '');
    assert.equal(state.error.value, 'Incorrect PIN.');
});
