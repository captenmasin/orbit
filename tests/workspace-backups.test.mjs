import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import { http } from '@inertiajs/core';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/WorkspaceBackups.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'backup-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    return scope.run(() => context.exports.default.setup({ native: true }, { expose() {} }));
}

test('backup export submits the entered password then clears it from state', async t => {
    const state = mount(t);
    state.destination.value = { destination: 'workspace.orbitbackup', exists: false };
    state.password.value = state.confirmation.value = 'dummy-backup-password';
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(JSON.parse(request.data));
        return { status: 200, data: JSON.stringify({ exported: true }), headers: {} };
    });

    await state.exportBackup();

    assert.equal(requests[0].password, 'dummy-backup-password');
    assert.equal(requests[0].password_confirmation, 'dummy-backup-password');
    assert.equal(state.password.value, '');
    assert.equal(state.confirmation.value, '');
    assert.equal(state.form.password, '');
    assert.equal(state.form.password_confirmation, '');
    assert.equal(state.destination.value, null);
});

test('rejected backup exports and restores retain the selection and do not reload', async t => {
    const state = mount(t);
    state.destination.value = { destination: 'workspace.orbitbackup', exists: false };
    state.restorePreview.value = { projects: 1 };
    t.mock.method(http.getClient(), 'request', async () => ({ status: 422,
        data: JSON.stringify({ errors: { backup: 'The file changed. Preview it again.' } }), headers: {} }));
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });

    await state.exportBackup();
    await state.applyRestore();

    assert.equal(state.destination.value.destination, 'workspace.orbitbackup');
    assert.equal(state.restorePreview.value.projects, 1);
    assert.match(state.form.errors.backup, /file changed/);
    assert.match(state.restoreApply.errors.backup, /file changed/);
    assert.equal(reloads, 0);
});

test('secret backups unlock with the PIN before exporting and clear it afterward', async t => {
    const state = mount(t);
    state.destination.value = { destination: 'workspace.orbitbackup', exists: false };
    state.form.include_secrets = true;
    state.pinForm.pin = '1234';
    state.password.value = state.confirmation.value = 'dummy-backup-password';
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify(request.url === '/secrets/unlock' ? { unlocked: true } : { exported: true }), headers: {} };
    });

    await state.exportBackup();

    assert.deepEqual(requests.map(request => request.url), ['/secrets/unlock', '/backups/export']);
    assert.equal(JSON.parse(requests[0].data).pin, '1234');
    assert.equal(state.pinForm.pin, '');
    assert.equal(state.destination.value, null);
});

test('a wrong PIN stops a secret backup before export', async t => {
    const state = mount(t);
    state.destination.value = { destination: 'workspace.orbitbackup', exists: false };
    state.form.include_secrets = true;
    state.pinForm.pin = '9999';
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request.url);
        return { status: 422, data: JSON.stringify({ errors: { pin: 'Incorrect PIN.' } }), headers: {} };
    });

    await state.exportBackup();

    assert.deepEqual(requests, ['/secrets/unlock']);
    assert.equal(state.pinForm.pin, '');
    assert.equal(state.pinForm.errors.pin, 'Incorrect PIN.');
    assert.notEqual(state.destination.value, null);
});
