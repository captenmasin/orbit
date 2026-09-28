import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

const { http } = inertia;

function mount(t, events = [], props = {}) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/WorkspaceBackups.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'backup-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const toast = Object.assign(() => {}, { success() {}, error() {} });
    const modules = { vue: { ...vue, onMounted() {}, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia, 'vue-sonner': { toast } };
    const context = { exports: {}, URL, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    return scope.run(() => context.exports.default.setup({ native: true, pinSet: true, ...props }, { expose() {}, emit(...args) { events.push(args); } }));
}

test('backup export submits the entered password then clears it from state', async t => {
    const events = [];
    const state = mount(t, events);
    state.destination.value = { destination: 'workspace.orbitbackup', exists: false };
    state.password.value = state.confirmation.value = 'dummy-backup-password';
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(JSON.parse(request.data));
        return { status: 200, data: JSON.stringify({ exported: true, revision: 2,
            backups: { folder: null, last_export_at: '2026-09-27T12:00:00+00:00', last_export_path: '/workspace.orbitbackup' } }), headers: {} };
    });

    await state.exportBackup();

    assert.equal(requests[0].password, 'dummy-backup-password');
    assert.equal(requests[0].password_confirmation, 'dummy-backup-password');
    assert.equal(state.password.value, '');
    assert.equal(state.confirmation.value, '');
    assert.equal(state.form.password, '');
    assert.equal(state.form.password_confirmation, '');
    assert.equal(state.destination.value, null);
    assert.equal(state.backupPreferences.value.last_export_path, '/workspace.orbitbackup');
    assert.equal(state.folderForm.revision, 2);
    assert.deepEqual(events, [['saved', 2]]);
});

test('backup folder loads and saves with the preference revision contract', async t => {
    const events = [];
    const state = mount(t, events);
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify(request.method === 'get'
            ? { revision: 2, backups: { folder: '/previous', last_export_at: null, last_export_path: null } }
            : { preferences: { revision: 3 } }), headers: {} };
    });

    await state.loadPreferences();
    assert.equal(state.folderForm.folder, '/previous');
    assert.equal(state.folderForm.isDirty, false);
    state.folderForm.folder = '/chosen';
    await state.saveFolder();

    assert.deepEqual(JSON.parse(requests[1].data), { revision: 2, folder: '/chosen' });
    assert.equal(state.folderForm.revision, 3);
    assert.equal(state.backupPreferences.value.folder, '/chosen');
    assert.equal(state.folderForm.isDirty, false);
    assert.deepEqual(events, [['saved', 2], ['saved', 3]]);
});

test('recovered folder drafts require reviewing fresh preferences before saving', async t => {
    t.mock.method(inertia.router, 'restore', () => ({ folder: '/draft', baseline: '/old', revision: 2 }));
    const remembered = [];
    t.mock.method(inertia.router, 'remember', (data, key) => remembered.push({ data, key }));
    const state = mount(t);
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request.url);
        return { status: 200, data: JSON.stringify({ revision: 4, backups: { folder: '/current', last_export_at: null, last_export_path: null } }), headers: {} };
    });
    await state.loadPreferences();
    assert.equal(state.folderForm.folder, '/draft');
    assert.equal(state.folderForm.revision, 2);
    assert.match(state.folderForm.errors.revision, /saved folder changed/);
    await state.saveFolder();
    assert.deepEqual(requests, ['/backups/preferences']);

    await state.loadPreferences(true);
    assert.equal(state.folderForm.folder, '/draft');
    assert.equal(state.folderForm.revision, 4);
    assert.equal(state.folderForm.isDirty, true);
    assert.equal(state.folderForm.hasErrors, false);
    state.discardFolder();
    assert.equal(state.folderForm.folder, '/current');
    assert.equal(state.folderForm.isDirty, false);
    assert.equal(remembered.at(-1).data, null);
});

test('rejected restores clear consumed staging and do not reload', async t => {
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
    assert.equal(state.restorePreview.value, null);
    assert.equal(state.restoreApply.confirm, false);
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

test('starting another preview immediately removes the old destructive candidate and consent', async t => {
    const state = mount(t);
    let finish;
    t.mock.method(http.getClient(), 'request', () => new Promise(resolve => { finish = resolve; }));
    state.restorePreview.value = { source: '/A.orbitbackup', projects: 1 };
    state.restoreApply.confirm = true;
    state.restoreApplyPassword.value = 'old-password';
    state.restorePassword.value = 'new-password';
    const pending = state.previewRestore();
    assert.equal(state.restorePreview.value, null);
    assert.equal(state.restoreApply.confirm, false);
    assert.equal(state.restoreApplyPassword.value, '');
    await vue.nextTick();
    finish({ status: 422, data: JSON.stringify({ errors: { backup: 'Damaged backup.' } }), headers: {} });
    await pending;
    assert.equal(state.restorePreview.value, null);
    assert.equal(state.restore.password, '');
    t.mock.method(http.getClient(), 'request', async () => ({ status: 200, data: JSON.stringify({ preview: null }), headers: {} }));
    await state.previewRestore();
    assert.equal(state.restorePreview.value, null);
});

test('missing or unavailable PIN prevents inclusive exports before unlocking', async t => {
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => { requests.push(request.url); return { status: 200, data: '{}', headers: {} }; });
    for (const pinSet of [false, null, undefined]) {
        const state = mount(t, [], { pinSet });
        state.destination.value = { destination: 'workspace.orbitbackup', exists: false };
        state.form.include_secrets = true;
        await state.exportBackup();
        assert.equal(requests.length, 0);
        state.form.include_secrets = false;
        await state.exportBackup();
        assert.equal(requests.pop(), '/backups/export');
    }
});

test('successful replacement reports restore completion only after fresh props arrive', async t => {
    const events = [];
    const state = mount(t, events);
    let reload;
    t.mock.method(inertia.router, 'reload', options => { reload = options; });
    t.mock.method(http.getClient(), 'request', async () => ({ status: 200, data: JSON.stringify({ restored: true }), headers: {} }));
    state.restorePreview.value = { source: '/A.orbitbackup', projects: 1 };
    await state.applyRestore();
    assert.deepEqual(events, []);
    assert.equal(state.restorePreview.value, null);
    reload.onSuccess();
    assert.deepEqual(events, [['restored']]);
});
