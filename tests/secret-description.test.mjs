import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

const { http } = inertia;

function mount(t) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/SecretDescription.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'description-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const props = vue.reactive({
        project: { id: 'project', revision: 3, secrets: [
            { id: 'secret', revision: 2, name: 'API_KEY', environment: 'Production', description: 'Original description', updated_at: '2026-09-26T12:00:00Z' },
            { id: 'other', revision: 7, description: 'Leave this description alone' },
        ] },
        secret: null,
        native: true,
    });
    props.secret = props.project.secrets[0];
    const guards = new Map();
    t.mock.method(inertia.router, 'on', (event, callback) => { guards.set(event, callback); return () => guards.delete(event); });
    const updates = [];
    t.mock.method(inertia.router, 'replaceProp', (path, update, options) => {
        assert.equal(path, 'selectedProject');
        const secretId = props.secret.id;
        props.project = update(props.project);
        props.secret = props.project.secrets.find(secret => secret.id === secretId);
        updates.push(props.project);
        options?.onFinish?.();
    });
    const toasts = [];
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': { ...inertia, usePage: () => ({ url: '/projects/project?tab=secrets' }) }, 'vue-sonner': { toast: { error: message => toasts.push(message) } } };
    const context = { exports: {}, require: name => modules[name] ?? {}, setTimeout, clearTimeout, URL };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const state = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    const navigate = url => guards.get('before')({ detail: { visit: { method: 'get', url } } });
    return { ...state, props, updates, navigate, toasts };
}

function response(description, revision = 3, projectRevision = 4) {
    return { status: 200, data: JSON.stringify({ description, revision, project_revision: projectRevision, updated_at: '2026-09-26T12:01:00Z' }), headers: {} };
}

async function settle() {
    await vue.nextTick();
    await new Promise(resolve => setImmediate(resolve));
}

test('description autosave waits for an 800ms pause and updates only its secret', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return response('Latest description');
    });
    const state = mount(t);
    state.draft.value = 'First edit';
    await vue.nextTick();
    t.mock.timers.tick(799);
    await settle();
    assert.equal(requests.length, 0);

    state.draft.value = 'Latest description';
    await vue.nextTick();
    t.mock.timers.tick(799);
    await settle();
    assert.equal(requests.length, 0);
    t.mock.timers.tick(1);
    await settle();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'put');
    assert.equal(requests[0].url, '/projects/project/secrets/secret/description');
    assert.deepEqual(JSON.parse(requests[0].data), { project_revision: 3, revision: 2, description: 'Latest description' });
    assert.equal(state.props.project.revision, 4);
    assert.equal(state.props.secret.revision, 3);
    assert.equal(state.props.secret.description, 'Latest description');
    assert.equal(state.props.secret.updated_at, '2026-09-26T12:01:00Z');
    assert.equal(state.props.project.secrets[1].description, 'Leave this description alone');
    assert.equal(state.props.project.secrets[1].revision, 7);
    assert.equal(state.dirty.value, false);
    assert.equal(state.saving.value, false);
    assert.equal(await state.flush(), true);
    assert.equal(requests.length, 1);
});

test('flushing an empty draft clears the saved description', async t => {
    let request;
    t.mock.method(http.getClient(), 'request', async value => { request = value; return response(null); });
    const state = mount(t);
    state.draft.value = '';

    assert.equal(await state.flush(), true);

    assert.equal(JSON.parse(request.data).description, '');
    assert.equal(state.props.secret.description, null);
    assert.equal(state.draft.value, '');
    assert.equal(state.dirty.value, false);
});

test('a locked description keeps its draft and resumes autosaving after unlock', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => { requests.push(request); return response('Kept draft'); });
    const state = mount(t);
    state.draft.value = 'Kept draft';
    await vue.nextTick();
    state.props.native = false;
    await vue.nextTick();
    t.mock.timers.tick(800);
    await settle();

    assert.equal(requests.length, 0);
    assert.equal(state.draft.value, 'Kept draft');
    assert.equal(state.dirty.value, true);
    assert.equal(await state.flush(), false);
    assert.equal(state.navigate('/projects/other'), false);
    assert.match(state.error.value, /Unlock secrets/);
    assert.deepEqual(state.toasts, ['Unlock secrets to save your description before leaving.']);
    state.form.setError('description', 'A previous save failed.');

    state.props.native = true;
    await vue.nextTick();
    assert.equal(state.error.value, '');
    assert.equal(state.form.hasErrors, false);
    t.mock.timers.tick(799);
    await settle();
    assert.equal(requests.length, 0);
    t.mock.timers.tick(1);
    await settle();

    assert.equal(requests.length, 1);
    assert.equal(JSON.parse(requests[0].data).description, 'Kept draft');
    assert.equal(state.dirty.value, false);
});

test('flush saves the latest edit made during a request using the returned revisions', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    const resolvers = [];
    t.mock.method(http.getClient(), 'request', request => {
        requests.push(request);
        return new Promise(resolve => resolvers.push(resolve));
    });
    const state = mount(t);
    state.draft.value = 'First edit';
    const flushed = state.flush();
    await settle();
    assert.equal(state.saving.value, true);
    state.draft.value = 'Latest edit';
    await vue.nextTick();
    t.mock.timers.tick(800);
    await settle();
    assert.equal(requests.length, 1);

    resolvers[0](response('First edit'));
    await settle();

    assert.equal(requests.length, 2);
    assert.deepEqual(JSON.parse(requests[1].data), { project_revision: 4, revision: 3, description: 'Latest edit' });
    assert.equal(state.draft.value, 'Latest edit');
    resolvers[1](response('Latest edit', 4, 5));
    assert.equal(await flushed, true);
    assert.equal(state.props.project.revision, 5);
    assert.equal(state.props.secret.revision, 4);
    assert.equal(state.props.secret.description, 'Latest edit');
    assert.equal(state.dirty.value, false);
    assert.equal(state.saving.value, false);
    assert.equal(requests.length, 2);
});

for (const status of [422, 409]) {
    test(`a ${status} response preserves the draft and blocks a successful flush`, async t => {
        t.mock.method(http.getClient(), 'request', async () => ({ status, data: JSON.stringify({ message: 'Description could not be saved.', errors: { description: 'Description could not be saved.' } }), headers: {} }));
        const state = mount(t);
        state.draft.value = 'Unsaved description';

        assert.equal(await state.flush(), false);

        assert.equal(state.draft.value, 'Unsaved description');
        assert.equal(state.dirty.value, true);
        assert.equal(state.saving.value, false);
        assert.equal(state.needsReload.value, status === 409);
        assert.ok(state.error.value);
        assert.equal(state.props.secret.description, 'Original description');
        assert.equal(state.props.secret.revision, 2);
        assert.equal(state.props.project.revision, 3);
        assert.equal(state.updates.length, 0);
    });
}

for (const status of [200, 409]) {
    test(`navigation waits for description saving and ${status === 200 ? 'resumes after success' : 'stays after failure'}`, async t => {
        let resolveSave;
        t.mock.method(http.getClient(), 'request', () => new Promise(resolve => { resolveSave = resolve; }));
        const visits = [];
        t.mock.method(inertia.router, 'visit', url => visits.push(url));
        const state = mount(t);
        state.draft.value = 'Unsaved description';

        assert.equal(state.navigate('/projects/other'), false);
        assert.deepEqual(visits, []);
        resolveSave(status === 200 ? response('Unsaved description') : { status, data: JSON.stringify({ message: 'This secret changed.' }), headers: {} });
        await settle();

        assert.deepEqual(visits, status === 200 ? ['/projects/other'] : []);
        assert.equal(state.dirty.value, status !== 200);
    });
}

test('conflict reload preserves the draft until an explicit retry uses refreshed revisions', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return requests.length === 1
            ? { status: 409, data: JSON.stringify({ message: 'This secret changed.' }), headers: {} }
            : response('Unsaved description', 7, 9);
    });
    const state = mount(t);
    state.draft.value = 'Unsaved description';
    assert.equal(await state.flush(), false);
    state.props.native = false;
    await vue.nextTick();
    state.props.native = true;
    await vue.nextTick();
    t.mock.timers.tick(800);
    await settle();
    assert.equal(state.needsReload.value, true);
    assert.equal(requests.length, 1);
    t.mock.method(inertia.router, 'reload', options => {
        state.props.project = { ...state.props.project, revision: 8, secrets: state.props.project.secrets.map(secret => secret.id === 'secret' ? { ...secret, revision: 6, description: 'Latest server description' } : secret) };
        state.props.secret = state.props.project.secrets[0];
        options.onSuccess();
        options.onFinish();
    });

    state.reloadProject();
    await settle();
    t.mock.timers.tick(800);
    await settle();

    assert.equal(state.draft.value, 'Unsaved description');
    assert.equal(state.needsReload.value, false);
    assert.equal(state.reloading.value, false);
    assert.equal(requests.length, 1);
    assert.equal(await state.flush(), true);
    assert.deepEqual(JSON.parse(requests[1].data), { project_revision: 8, revision: 6, description: 'Unsaved description' });
    assert.equal(state.props.secret.description, 'Unsaved description');
    assert.equal(state.dirty.value, false);
});
