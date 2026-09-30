import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { webcrypto } from 'node:crypto';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import * as vueuse from '@vueuse/core';
import ts from 'typescript';
import * as vue from 'vue';

function script(source, modules) {
    const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const context = { exports: {}, require: name => modules[name] ?? {}, crypto: webcrypto, URL, URLSearchParams, setTimeout, clearTimeout };
    runInNewContext(outputText, context);
    return context.exports;
}
const projectHelpers = script(readFileSync(new URL('../resources/js/lib/project.ts', import.meta.url), 'utf8'), {});
const dependencyHelpers = script(readFileSync(new URL('../resources/js/lib/dependencies.ts', import.meta.url), 'utf8'), {});

function harness(t, inertiaOverrides = {}, notifications = []) {
    const stored = new Map();
    const storage = { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) };
    const modules = { vue: { ...vue, onMounted() {} }, '@inertiajs/vue3': { ...inertia, usePage: () => ({ url: '/' }), ...inertiaOverrides }, '@/lib/project': projectHelpers, '@/lib/dependencies': dependencyHelpers,
        'vue-sonner': { toast: { error: message => notifications.push(message), success: message => notifications.push(message) } },
        '@vueuse/core': { ...vueuse, useSessionStorage: (key, value, options) => vueuse.useStorage(key, value, storage, options) } };
    return function mount(file, input) {
        const { descriptor } = parse(readFileSync(new URL(`../resources/js/${file}.vue`, import.meta.url), 'utf8'));
        const component = script(compileScript(descriptor, { id: 'project-tab-test' }).content, modules).default;
        const scope = vue.effectScope();
        t.after(() => scope.stop());
        const props = vue.reactive(input);
        const emitted = [];
        const state = scope.run(() => component.setup(props, { expose() {}, emit: (...args) => emitted.push(args) }));
        return { state, props, emitted, unmount: () => scope.stop() };
    };
}

test('project view tabs survive navigation and follow each selected project', async t => {
    const mount = harness(t);
    const view = id => mount('pages/ShowProject', { selectedProject: { id, tags: [], revision: 1 }, inspection: [], activity: [], connections: [], native: false, notesHtml: '' });
    for (const tab of ['sources', 'documents', 'board', 'assets', 'dependencies', 'secrets', 'overview']) {
        const current = view('project-a');
        current.state.tab.value = tab;
        current.unmount();
        const reloaded = view('project-a');
        assert.equal(reloaded.state.tab.value, tab);
        reloaded.unmount();
    }
    const current = view('project-a');
    current.state.tab.value = 'board';
    current.props.selectedProject = { id: 'project-b', tags: [], revision: 1 };
    await vue.nextTick();
    assert.equal(current.state.tab.value, 'overview');
    current.props.selectedProject = { id: 'project-a', tags: [], revision: 1 };
    await vue.nextTick();
    assert.equal(current.state.tab.value, 'board');
    current.props.inspection = [{ id: 'folder', path: '/project', branch: 'main', last_commit_at: '2026-09-15T12:00:00Z' }];
    await vue.nextTick();
    assert.equal(current.state.latestCommit.value.branch, 'main');
    current.props.activity = [{ remote_commit_at: '2026-09-19T12:00:00Z', default_branch: 'release', provider_name: 'team/repo', provider_snapshots: [{ resource: 'overview', state: 'Stale' }] }];
    await vue.nextTick();
    assert.equal(current.state.latestCommit.value.branch, 'release');
    assert.match(current.state.latestCommit.value.path, /Stale/);
});

test('project Sources deep link selects the Sources tab', t => {
    const current = harness(t, { usePage: () => ({ url: '/projects/project-a?tab=sources' }) })('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 1 }, inspection: [], activity: [], connections: [], native: false,
    });

    assert.equal(current.state.tab.value, 'sources');
});

test('leaving the Secrets tab waits for a successful description flush', async t => {
    const current = harness(t)('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 1 }, inspection: [], activity: [], connections: [], native: false,
    });
    current.state.tab.value = 'secrets';
    let saved = false;
    current.state.secretsPage.value = { flush: async () => saved };

    await current.state.changeTab('board');
    assert.equal(current.state.tab.value, 'secrets');

    saved = true;
    await current.state.changeTab('board');
    assert.equal(current.state.tab.value, 'board');
});

test('project overview previews saved card order independently of list names', async t => {
    const mount = harness(t);
    const current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 1, board_columns: [
            { name: 'Backlog', tasks: [{ id: 'one' }, { id: 'two' }] },
            { name: 'In Progress', tasks: [{ id: 'three' }, { id: 'four' }] },
            { name: 'Done', tasks: [{ id: 'five' }] },
        ] },
        inspection: [], activity: [], connections: [], native: false,
    });

    assert.equal(current.state.taskCount.value, 5);
    assert.deepEqual(Array.from(current.state.previewTasks.value, task => [task.id, task.column.name]), [['one', 'Backlog'], ['two', 'Backlog'], ['three', 'In Progress']]);
    current.props.selectedProject.board_columns = [{ tasks: [] }];
    await vue.nextTick();
    assert.equal(current.state.taskCount.value, 0);
    assert.equal(current.state.previewTasks.value.length, 0);
});

test('overview card menu moves cards and keeps delete confirmation open after an error', t => {
    const notifications = [];
    const mount = harness(t, {}, notifications);
    const requests = [];
    t.mock.method(inertia.router, 'put', (url, data, callbacks) => {
        callbacks.onStart();
        requests.push({ url, data, callbacks });
    });
    const task = { id: 'first', title: 'First card' };
    const backlog = { id: 'backlog', name: 'Backlog', tasks: [task, { id: 'second' }] };
    const todo = { id: 'todo', name: 'To Do', tasks: [] };
    const current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 7, board_columns: [backlog, todo] },
        inspection: [], activity: [], connections: [], native: false,
    });

    current.state.reorderPreviewTask(backlog, task, 1);
    assert.equal(requests.length, 1);
    assert.deepEqual({ ...requests[0].data }, { action: 'task.move', revision: 7, id: 'first', column_id: 'backlog', position: 1 });
    current.state.movePreviewTask(task, todo);
    assert.equal(requests.length, 1, 'A second move cannot start while saving');
    requests[0].callbacks.onError({ revision: 'Reload before moving.' });
    requests[0].callbacks.onFinish();
    assert.deepEqual(notifications, ['Reload before moving.']);

    current.state.movePreviewTask(task, todo);
    assert.deepEqual({ ...requests[1].data }, { action: 'task.move', revision: 7, id: 'first', column_id: 'todo', position: 0 });
    requests[1].callbacks.onFinish();

    current.state.deletingPreviewTask.value = task;
    current.state.updatePreviewTask('task.delete', task);
    assert.deepEqual({ ...requests[2].data }, { action: 'task.delete', revision: 7, id: 'first' });
    requests[2].callbacks.onError({ revision: 'Reload before deleting.' });
    requests[2].callbacks.onFinish();
    assert.equal(current.state.deletingPreviewTask.value.id, 'first');
    assert.equal(current.state.previewError.value, 'Reload before deleting.');

    current.state.updatePreviewTask('task.delete', task);
    requests[3].callbacks.onSuccess();
    requests[3].callbacks.onFinish();
    assert.equal(current.state.deletingPreviewTask.value, null);
});

test('project overview prefers the latest repo commit and counts queued tasks', t => {
    const current = harness(t)('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 1, updated_at: '2026-09-20T12:00:00Z', board_columns: [
            { name: 'Backlog', tasks: [{ id: 'one' }] },
            { name: 'To Do', tasks: [{ id: 'two' }, { id: 'three' }] },
            { name: 'Done', tasks: [{ id: 'four' }] },
        ] },
        inspection: [{ id: 'folder', git_root: '/project', last_commit_at: '2026-09-19T12:00:00Z' }],
        activity: [{ remote_commit_at: '2026-09-18T12:00:00Z' }], connections: [], native: false,
    });

    assert.equal(current.state.lastUpdateAt.value, '2026-09-19T12:00:00Z');
    assert.equal(current.state.lastUpdateFromGit.value, true);
    assert.equal(current.state.taskCount.value, 4);

    current.props.activity[0].remote_commit_at = '2026-09-21T12:00:00Z';
    assert.equal(current.state.lastUpdateAt.value, '2026-09-21T12:00:00Z');
    assert.equal(current.state.lastUpdateFromGit.value, true);

    current.props.inspection[0].last_commit_at = null;
    current.props.activity = [];
    assert.equal(current.state.lastUpdateAt.value, null);
    current.props.inspection = [];
    assert.equal(current.state.lastUpdateAt.value, '2026-09-20T12:00:00Z');
    assert.equal(current.state.lastUpdateFromGit.value, false);
});

test('project overview retains confirmed attention during unrelated scans and hides invalidated findings', t => {
    const current = harness(t)('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 1 },
        inspection: [{ availability: 'Missing', scan_state: 'Queued', package_roots: [{ scan_state: 'Current' }] }],
        activity: [], connections: [], native: false,
    });

    assert.equal(current.state.needsAttention.value, true);
    current.props.inspection[0].scan_state = 'Current';
    assert.equal(current.state.needsAttention.value, true);
    current.props.inspection[0].package_roots[0].scan_state = 'Scanning';
    assert.equal(current.state.needsAttention.value, true);
    current.props.inspection[0].package_roots[0].scan_state = 'Current';
    current.props.inspection[0].availability = 'Available';
    assert.equal(current.state.needsAttention.value, false);
    current.props.inspection[0].package_roots[0] = {
        id: 'root', relative_path: '.', scan_state: 'Current',
        snapshot: { fingerprint: 'current', unsupported_lockfiles: [], files: {} },
        security: { fingerprint: 'current', checked: 1, unavailable: 0, skipped: 0, packages: [{
            name: 'vue', ecosystem: 'npm', current: '3.5.20',
            advisories: [{ id: 'GHSA-example', severity: 'High', fixed_versions: [] }],
        }] },
    };
    assert.equal(current.state.needsAttention.value, true);
    for (const scanState of ['Queued', 'Scanning', 'Current']) {
        current.props.inspection[0].package_roots[0].scan_state = scanState;
        assert.equal(current.state.needsAttention.value, true);
        assert.equal(current.state.dependencies.value.issueCount, 1);
    }
    current.props.inspection[0].package_roots[0].scan_state = 'Queued';
    current.props.inspection[0].package_roots[0].snapshot.fingerprint = 'changed';
    assert.equal(current.state.needsAttention.value, false);
});

test('project header changes status with current details and reports conflicts', t => {
    const requests = [];
    const mount = harness(t, { router: { on: () => () => {}, put: (...args) => requests.push(args) } });
    const current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', name: 'Sitepulse', description: 'Health app', status: 'In Progress', revision: 4, tags: [] },
        statuses: ['Idea', 'In Progress', 'Live', 'Paused', 'Maintenance', 'Archived'],
        inspection: [], activity: [], connections: [], native: false,
    });

    current.state.changeStatus('In Progress');
    assert.equal(requests.length, 0);
    current.state.scratchpad.value = { saving: true };
    current.state.changeStatus('Live');
    assert.equal(requests.length, 0);
    current.state.scratchpad.value = null;
    current.state.changeStatus('Live');
    assert.equal(requests.length, 1);
    const [url, data, options] = requests[0];
    assert.equal(url, '/projects/project-a');
    assert.deepEqual(JSON.parse(JSON.stringify(data)), { name: 'Sitepulse', description: 'Health app', status: 'Live', revision: 4 });
    assert.equal(options.preserveScroll, true);
    current.state.changeStatus('Paused');
    assert.equal(requests.length, 1);

    options.onError({ revision: 'This project changed.' });
    assert.equal(current.state.statusError.value, 'This project changed.');
    assert.equal(current.state.statusNeedsReload.value, true);
    options.onFinish();
    assert.equal(current.state.statusSaving.value, false);
});

test('work surface shows recent documents and keeps all linked resources reachable', async t => {
    const page = vue.reactive({ url: '/projects/project-a?link=9' });
    const mount = harness(t, { usePage: () => page });
    const current = mount('pages/ShowProject', {
        selectedProject: {
            id: 'project-a', tags: [], revision: 1,
            documents: [
                { id: 'older', updated_at: '2026-09-01T10:00:00Z' },
                { id: 'newest', updated_at: '2026-09-03T10:00:00Z' },
                { id: 'middle', updated_at: '2026-09-02T10:00:00Z' },
            ],
            repositories: Array.from({ length: 3 }, (_, index) => ({ id: `repo-${index}` })),
            links: Array.from({ length: 10 }, (_, index) => ({ id: String(index) })),
        },
        inspection: [
            { id: 'folder', availability: 'Missing', scan_error: null, scanned_at: '2026-09-01T10:00:00Z' },
            { id: 'folder-two', availability: 'Available', scan_error: null, scanned_at: '2026-09-04T10:00:00Z' },
            { id: 'folder-three', availability: 'Available', scan_error: null, scanned_at: '2026-09-05T10:00:00Z' },
        ], activity: [], connections: [], native: false,
    });

    assert.deepEqual(Array.from(current.state.recentDocuments.value, document => document.id), ['newest', 'middle']);
    assert.equal(current.state.visibleLinks.value.length, 10);
    assert.deepEqual(Array.from(current.state.visibleRepositories.value, repo => repo.id), ['repo-0', 'repo-1']);
    assert.deepEqual(Array.from(current.state.visibleFolders.value, folder => folder.id), ['folder', 'folder-two']);
    assert.equal(current.state.folderIssueCount.value, 1);

    page.url = '/projects/project-a';
    await vue.nextTick();
    assert.deepEqual(Array.from(current.state.visibleLinks.value, link => link.id), ['0', '1', '2']);
    current.state.linksOpen.value = true;
    assert.equal(current.state.visibleLinks.value.length, 10);
    current.state.sourcesOpen.value = true;
    assert.equal(current.state.visibleRepositories.value.length, 3);
    assert.equal(current.state.visibleFolders.value.length, 3);
});

test('project links keep saved order through expansion and category changes', t => {
    const mount = harness(t);
    const current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 1, links: [
            { id: 'social-a', category: 'Social' },
            { id: 'uncategorized', category: null },
            { id: 'docs', category: 'Documentation' },
            { id: 'social-b', category: 'Social' },
            { id: 'hosting', category: 'Hosting' },
        ] },
        inspection: [], activity: [], connections: [], native: false,
    });
    const ids = () => Array.from(current.state.visibleLinks.value, link => link.id);
    assert.deepEqual(ids(), ['social-a', 'uncategorized', 'docs']);
    current.state.linksOpen.value = true;
    assert.deepEqual(ids(), ['social-a', 'uncategorized', 'docs', 'social-b', 'hosting']);
    current.props.selectedProject.links[0].category = 'ZZZ';
    assert.deepEqual(ids(), ['social-a', 'uncategorized', 'docs', 'social-b', 'hosting']);
});

test('scratchpad suggestions include project actions and saved notes clear without losing new drafts', async t => {
    const requests = [];
    const actions = [{ type: 'link', label: 'Buff', url: 'https://usebuff.app/', description: '' }];
    const http = {
        revision: 0, processing: false, errors: {}, cancel() {}, clearErrors() {},
        async post(url) {
            requests.push({ url, revision: this.revision });
            return { actions, statuses: ['Idea', 'In Progress'] };
        },
    };
    const mount = harness(t, { useHttp: () => http });
    const current = mount('components/ProjectScratchpad', {
        project: { id: 'project-a', revision: 3, scratchpad: 'https://usebuff.app/' },
    });
    const reviewed = [];
    current.state.actionReview.value = { begin: suggestions => reviewed.push(...suggestions) };

    await current.state.generateActions();

    assert.deepEqual(requests.at(-1), { url: '/projects/project-a/scratchpad/actions/preview', revision: 3 });
    assert.deepEqual(reviewed, actions);
    assert.deepEqual(Array.from(current.state.actionStatuses.value), ['Idea', 'In Progress']);

    current.props.project = { ...current.props.project, revision: 4, scratchpad: '' };
    await vue.nextTick();
    assert.equal(current.state.scratchpadForm.scratchpad, '');

    current.state.scratchpadForm.scratchpad = 'New unsaved note';
    current.props.project = { ...current.props.project, revision: 5 };
    await vue.nextTick();
    assert.equal(current.state.scratchpadForm.scratchpad, 'New unsaved note');
});

test('scratchpad autosaves settled notes and generates actions only on request without losing edits made during a save', async t => {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    const actions = [{ type: 'task', title: 'Ship release', column_id: null }];
    const previews = [];
    const saves = [];
    const notifications = [];
    let finishSave;
    let current;
    const saveHttp = {
        revision: 0, scratchpad: '', processing: false, errors: {},
        put(url) {
            const note = this.scratchpad;
            const revision = this.revision;
            saves.push({ url, note, revision });
            this.processing = true;
            return new Promise((resolve, reject) => {
                finishSave = async (result = { revision: revision + 1, updated_at: '2026-09-25T12:00:00Z' }) => {
                    this.processing = false;
                    if (result instanceof Error) reject(result);
                    else if (result.errors) { this.errors = result.errors; resolve(undefined); }
                    else resolve(result);
                    await new Promise(setImmediate);
                    await vue.nextTick();
                };
            });
        },
    };
    const http = {
        revision: 0, processing: false, errors: {}, cancel() {}, clearErrors() {},
        async post(url) { previews.push({ url, revision: this.revision }); return { actions, statuses: ['Idea'] }; },
    };
    const router = { on() { return () => {}; }, replaceProp(name, value) { current.props.project = value(current.props.project); } };
    const mount = harness(t, { router, useHttp: data => 'scratchpad' in data ? saveHttp : http }, notifications);
    current = mount('components/ProjectScratchpad', {
        project: { id: 'project-a', revision: 1, scratchpad: '' },
    });
    const form = current.state.scratchpadForm;
    const reviewed = [];
    current.state.actionReview.value = { begin: suggestions => reviewed.push(...suggestions) };

    form.scratchpad = 'First draft';
    await vue.nextTick();
    t.mock.timers.tick(799);
    assert.equal(saves.length, 0);
    t.mock.timers.tick(1);
    assert.deepEqual(saves, [{ url: '/projects/project-a/scratchpad', note: 'First draft', revision: 1 }]);

    form.scratchpad = '';
    await vue.nextTick();
    await finishSave();
    assert.equal(form.scratchpad, '');
    assert.equal(current.props.project.updated_at, '2026-09-25T12:00:00Z');
    t.mock.timers.tick(800);
    assert.deepEqual(saves.at(-1), { url: '/projects/project-a/scratchpad', note: '', revision: 2 });
    await finishSave();
    assert.equal(previews.length, 0);

    form.scratchpad = 'Ship release';
    await vue.nextTick();
    t.mock.timers.tick(800);
    assert.deepEqual(saves.at(-1), { url: '/projects/project-a/scratchpad', note: 'Ship release', revision: 3 });
    await finishSave();
    t.mock.timers.tick(4000);
    await new Promise(setImmediate);
    assert.equal(previews.length, 0);
    assert.equal(reviewed.length, 0);

    await current.state.generateActions();
    assert.deepEqual(previews, [{ url: '/projects/project-a/scratchpad/actions/preview', revision: 4 }]);
    assert.deepEqual(Array.from(current.state.suggestedActions.value), actions);
    assert.deepEqual(reviewed, actions);
    assert.equal(previews.length, 1);

    form.scratchpad = ' ';
    await vue.nextTick();
    t.mock.timers.tick(800);
    await finishSave();
    t.mock.timers.tick(4000);
    assert.equal(previews.length, 1);

    form.scratchpad = 'Network failure';
    await vue.nextTick();
    t.mock.timers.tick(800);
    const failedSaveCount = saves.length;
    await finishSave(new Error('Network failure'));
    t.mock.timers.tick(800);
    assert.equal(saves.length, failedSaveCount);
    assert.equal(form.errors.scratchpad, undefined);
    assert.equal(current.state.saveError.value, 'Could not save notes. Try again.');
    assert.equal(form.scratchpad, 'Network failure');

    form.scratchpad = 'Stale note';
    await vue.nextTick();
    t.mock.timers.tick(800);
    await finishSave(Object.assign(new Error('Conflict'), { response: { status: 409 } }));
    assert.equal(form.errors.revision, 'This project changed. Reload the scratchpad before trying again.');
    assert.equal(form.scratchpad, 'Stale note');

    form.clearErrors('revision');
    form.scratchpad = 'Invalid note';
    await vue.nextTick();
    t.mock.timers.tick(800);
    await finishSave({ errors: { scratchpad: 'Write a shorter note.' } });
    assert.equal(form.errors.scratchpad, 'Write a shorter note.');
    assert.equal(form.scratchpad, 'Invalid note');
});

test('leaving a project saves pending scratchpad notes before navigating', async t => {
    let beforeVisit;
    const destinations = [];
    let current;
    const router = { ...inertia.router,
        on(event, listener) { if (event === 'before') beforeVisit = listener; return () => {}; },
        visit(url) { destinations.push(url); },
        replaceProp(name, value) { current.props.project = value(current.props.project); },
    };
    let finishSave;
    const saveHttp = {
        revision: 0, scratchpad: '', processing: false, errors: {},
        put() {
            this.processing = true;
            return new Promise(resolve => { finishSave = () => { this.processing = false; resolve({ revision: 2, updated_at: '2026-09-25T12:00:00Z' }); }; });
        },
    };
    const mount = harness(t, { router, useHttp: data => 'scratchpad' in data ? saveHttp : inertia.useHttp(data) });
    current = mount('components/ProjectScratchpad', {
        project: { id: 'project-a', revision: 1, scratchpad: '' },
    });
    const form = current.state.scratchpadForm;

    form.scratchpad = 'Take this with me';
    await vue.nextTick();
    const allowed = beforeVisit({ detail: { visit: { method: 'get', url: new URL('http://orbit.local/projects/project-b') } } });
    assert.equal(allowed, false);
    assert.equal(saveHttp.processing, true);
    assert.deepEqual(destinations, []);

    finishSave();
    await new Promise(setImmediate);
    await vue.nextTick();
    assert.equal(current.props.project.scratchpad, 'Take this with me');
    assert.deepEqual(destinations, ['http://orbit.local/projects/project-b']);
});

test('edit forms retain dirty names and tags when the saved revision changes', async t => {
    const mount = harness(t);
    const current = mount('components/EditProjectForm', { project: { id: 'project-a', tags: [], revision: 1 }, statuses: ['Idea'], native: false });
    current.state.form.name = 'Unsaved name';
    current.state.form.tags = ['new-tag'];
    await vue.nextTick();
    current.props.project.revision = 2;
    await vue.nextTick();
    assert.equal(current.state.form.name, 'Unsaved name');
    assert.deepEqual(Array.from(current.state.form.tags), ['new-tag']);
});

test('new projects can be created without linking a repository or folder', t => {
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data) => requests.push({ url, data }));
    const current = harness(t, { router: { on: () => () => {}, restore() {}, remember() {} } })('components/CreateProjectForm', { statuses: ['Idea'] });
    current.state.form.name = 'New project';

    current.state.submit();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/projects');
    assert.equal(requests[0].data.name, 'New project');
    assert.equal('repositories' in requests[0].data, false);
    assert.equal('folders' in requests[0].data, false);
});

test('new project drafts retain details and links without restoring removed source selections', t => {
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data) => requests.push({ url, data }));
    const recovered = { data: {
        name: 'Draft project', description: 'Keep these notes', revision: 9,
        repositories: [{ id: 'repo', name: 'Old source', remote_url: 'https://github.com/team/repo.git' }],
        folders: [{ id: 'folder', path: '/old-source', repository_id: 'repo' }],
        links: [{ id: 'website', label: 'Website', url: 'https://example.com' }],
    } };
    const current = harness(t, { router: { on: () => () => {}, restore: () => recovered, remember() {} } })('components/CreateProjectForm', { statuses: ['Idea'] });

    current.state.submit();

    assert.equal(requests[0].data.name, 'Draft project');
    assert.equal(requests[0].data.description, 'Keep these notes');
    assert.equal(requests[0].data.links[0].url, 'https://example.com');
    assert.equal('repositories' in requests[0].data, false);
    assert.equal('folders' in requests[0].data, false);
    assert.equal('revision' in requests[0].data, false);
});

test('creation and editing retain independent drafts when open together', async t => {
    const drafts = new Map();
    const mount = harness(t, { router: {
        on: () => () => {}, reload() {},
        restore: key => drafts.get(key),
        remember: (data, key) => drafts.set(key, structuredClone(data)),
    } });
    const project = { id: 'one', name: 'Saved project', tags: [], revision: 3 };
    const edit = mount('components/EditProjectForm', { project, statuses: ['Idea'], native: false });
    const create = mount('components/CreateProjectForm', { statuses: ['Idea'] });
    edit.state.form.name = 'Unsaved edit';
    create.state.form.name = 'New project draft';
    await vue.nextTick();

    create.state.discardDraft();
    await vue.nextTick();

    assert.equal(edit.state.form.name, 'Unsaved edit');
    assert.equal(drafts.get('project-form:one').data.name, 'Unsaved edit');
    assert.equal(drafts.get('project-form:create'), null);
    const recoveredEdit = mount('components/EditProjectForm', { project, statuses: ['Idea'], native: false });
    const recoveredCreate = mount('components/CreateProjectForm', { statuses: ['Idea'] });
    assert.equal(recoveredEdit.state.form.name, 'Unsaved edit');
    assert.equal(recoveredCreate.state.form.name, '');
});

test('new projects and archive restoration use the first configured status', t => {
    const mount = harness(t);
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data) => requests.push({ url, data }));
    const statuses = ['Planning', 'Review', 'Archived'];
    const create = mount('components/CreateProjectForm', { statuses });
    assert.equal(create.state.form.status, 'Planning');
    const archived = mount('components/EditProjectForm', { project: { id: 'archived', name: 'Archived project', status: 'Archived', previous_status: null, tags: [], revision: 2 }, statuses, native: false });

    archived.state.archive();

    assert.equal(requests[0].url, '/projects/archived');
    assert.equal(requests[0].data.status, 'Planning');
    archived.props.project.previous_status = 'Review';
    archived.state.archive();
    assert.equal(requests[1].data.status, 'Review');
});

test('edit navigation opens the requested section despite a saved tab', t => {
    let url = '/projects/project-a/edit';
    const mount = harness(t, { usePage: () => ({ url }) });
    const project = { id: 'project-a', tags: [], revision: 1 };
    const previous = mount('components/EditProjectForm', { project, statuses: ['Idea'], native: false });
    previous.state.tab.value = 'repositories';
    previous.unmount();

    url = '/projects/project-a/edit?tab=links';
    const current = mount('components/EditProjectForm', { project, statuses: ['Idea'], native: false });
    assert.equal(current.state.tab.value, 'links');
    current.unmount();
    url = '/projects/project-a/edit?tab=repositories';
    const folders = mount('components/EditProjectForm', { project, statuses: ['Idea'], native: false });
    assert.equal(folders.state.tab.value, 'repositories');
});

test('adding folders to existing projects preserves project details and derives repository names without overwriting custom names', t => {
    const mount = harness(t);
    const current = mount('components/EditProjectForm', { project: { id: 'project-a', name: 'Saved name', description: 'Saved description', tags: [], revision: 1 }, statuses: ['Idea'], native: false });
    current.state.useFolder({ name: 'Folder name', path: '/projects/Folder name', remote_url: 'git@github.com:owner/repository.git', description: 'Description', git_state: 'Git repository' });
    assert.equal(current.state.form.name, 'Saved name');
    assert.equal(current.state.form.description, 'Saved description');
    assert.equal(current.state.form.repositories[0].name, 'repository');
    const repo = current.state.form.repositories[0];
    current.state.updateRemote(repo, 'https://github.com/owner/next.git');
    assert.equal(repo.name, 'next');
    repo.name = 'Custom label';
    current.state.updateRemote(repo, 'ssh://git@github.com/owner/another.git');
    assert.equal(repo.name, 'Custom label');
    assert.equal(projectHelpers.repositoryName('ssh://git@github.com/owner/repository.git'), 'repository');
    assert.equal(projectHelpers.repositoryName('not a URL'), '');
});

test('folder inspection applies the selection immediately and leaves folders intact on cancellation or failure', async t => {
    const notifications = [];
    const selected = { name: 'Folder name', path: '/projects/folder', remote_url: 'git@github.com:owner/repository.git', description: null, git_state: 'Git repository' };
    let folder = selected;
    let rejected = false;
    const inspection = vue.reactive({
        path: '', processing: false, errors: {}, hasErrors: false, cancel() {},
        async post() {
            if (rejected) throw new Error('Inspection failed');
            return { folder };
        },
        setError(key, message) { this.errors[key] = message; this.hasErrors = true; },
        clearErrors() { this.errors = {}; this.hasErrors = false; },
    });
    const mount = harness(t, { useHttp: () => inspection }, notifications);
    const current = mount('components/EditProjectForm', { project: { id: 'project-a', tags: [], revision: 1 }, statuses: ['Idea'], native: true });

    await current.state.inspectFolder(null, true);
    assert.equal(current.state.form.folders.length, 1);
    assert.equal(current.state.form.folders[0].path, selected.path);
    const id = current.state.form.folders[0].id;
    const repositoryId = current.state.form.folders[0].repository_id;

    folder = null;
    await current.state.inspectFolder(null, true);
    assert.equal(current.state.form.folders.length, 1);

    folder = selected;
    await current.state.inspectFolder(null, true);
    assert.equal(current.state.form.folders.length, 1);
    assert.equal(inspection.errors.path, 'This folder is already linked.');

    folder = { ...selected, path: '/projects/relinked' };
    await current.state.inspectFolder(id, true);
    assert.equal(current.state.form.folders.length, 1);
    assert.equal(current.state.form.folders[0].id, id);
    assert.equal(current.state.form.folders[0].path, '/projects/relinked');
    assert.equal(current.state.form.folders[0].repository_id, repositoryId);

    rejected = true;
    await current.state.inspectFolder(null, true);
    assert.equal(current.state.form.folders.length, 1);
    assert.equal(current.state.form.folders[0].path, '/projects/relinked');
    assert.equal(inspection.errors.path, undefined);
    assert.equal(notifications.at(-1), 'The folder could not be inspected. Try again.');
});

test('connected repository selection links the chosen connection and does not add the same remote twice', t => {
    const mount = harness(t);
    const current = mount('components/EditProjectForm', { project: { id: 'project-a', name: 'Saved name', description: 'Saved description', tags: [], revision: 1 }, statuses: ['Idea'], native: true, connections: [{ id: 'connection', label: 'Work', provider: 'github' }] });
    const repository = { connection_id: 'connection', full_name: 'team/repo', name: 'repo', remote_url: 'https://github.com/team/repo.git', description: 'Project description' };
    current.state.addConnectedRepository(repository);
    assert.equal(current.state.form.name, 'Saved name');
    assert.equal(current.state.form.description, 'Saved description');
    assert.equal(current.state.form.repositories[0].provider_connection_id, 'connection');
    assert.equal(current.state.form.repositories[0].provider_full_name, 'team/repo');
    current.state.addConnectedRepository({ ...repository, remote_url: 'https://github.com/team/repo' });
    assert.equal(current.state.form.repositories.length, 1);
    current.state.form.folders.push({ id: 'local-folder', path: '/projects/local', repository_id: current.state.form.repositories[0].id });
});

test('link reorder handles preserve link details, focus and list boundaries', async t => {
    const current = harness(t, { router: { on: () => () => {}, restore() {}, remember() {} } })('components/CreateProjectForm', {
        statuses: ['Idea'],
    });
    const website = { id: 'website', label: 'Website', url: 'https://example.com', category: 'Website', description: 'Public site' };
    const docs = { id: 'docs', label: 'Docs', url: 'https://example.com/docs', category: 'Documentation', description: 'Setup notes' };
    current.state.form.links.push(website, docs);
    let focused = 0;
    const event = { currentTarget: { focus() { focused++; } } };

    await current.state.moveLink(0, -1, event);
    await current.state.moveLink(1, 1, event);
    assert.deepEqual(Array.from(current.state.form.links, link => ({ ...link })), [website, docs]);
    assert.equal(focused, 0);

    await current.state.moveLink(1, -1, event);
    assert.deepEqual(Array.from(current.state.form.links, link => ({ ...link })), [docs, website]);
    assert.equal(focused, 1);
    assert.equal(current.state.linkAnnouncement.value, 'Docs moved to position 1 of 2.');

    current.state.form.processing = true;
    await current.state.moveLink(0, 1, event);
    assert.deepEqual(Array.from(current.state.form.links, link => ({ ...link })), [docs, website]);
    assert.equal(focused, 1);
});

test('disposing the project form cancels folder requests without reporting failures', async t => {
    const notifications = [];
    let requestSignal;
    t.mock.method(inertia.http.getClient(), 'request', request => {
        requestSignal = request.signal;
        return new Promise((resolve, reject) => {
            request.signal.addEventListener('abort', () => reject(Object.assign(new Error('Cancelled'), { name: 'AbortError' })), { once: true });
        });
    });
    const mount = harness(t, {}, notifications);
    for (const operation of ['inspect', 'replace']) {
        const current = mount('components/EditProjectForm', { project: { id: 'project-a', tags: [], revision: 1 }, statuses: ['Idea'], native: true });
        current.state.relinking.value = 'folder';
        const pending = operation === 'inspect' ? current.state.inspectFolder() : current.state.replaceFolder();

        current.unmount();
        await pending;

        assert.equal(requestSignal.aborted, true);
        assert.deepEqual(notifications, []);
        assert.equal(current.state.replacement.hasErrors, false);
        assert.equal(current.state.form.repositories.length, 0);
        assert.equal(current.state.form.folders.length, 0);
    }
});

test('project form departures retain drafts, recover per project, reject stale saves and clear on discard', async t => {
    const remembered = new Map(); const visits = []; const callbacks = []; let before;
    const router = {
        on(event, callback) { before = callback; return () => { before = null; }; },
        remember(value, key) { remembered.set(key, structuredClone(value)); },
        restore(key) { return remembered.get(key); },
        visit(url) { visits.push(url); }, reload() {},
        post(url, data, options) { callbacks.push({ url, data, options }); },
    };
    t.mock.method(inertia.router, 'post', router.post);
    const mount = harness(t, { router, usePage: () => ({ url: '/projects/one/edit' }) });
    const project = { id: 'one', name: 'Saved', tags: [], revision: 1 };
    let current = mount('components/EditProjectForm', { project, statuses: ['Idea'], native: false });
    current.state.form.name = 'Draft'; current.state.addLink(); current.state.form.icon_file = new File(['dummy'], 'selected.png');
    await vue.nextTick(); await vue.nextTick();
    let prevented = false;
    before({ detail: { visit: { method: 'get', url: new URL('https://orbit.test/') } }, preventDefault() { prevented = true; } });
    assert.equal(prevented, true); assert.equal(current.state.departureOpen.value, true); assert.equal(visits.length, 0);
    current.state.departureOpen.value = false;
    current.state.submit(); callbacks[0].options.onError({ 'links.0.url': 'Enter a URL' });
    assert.equal(current.state.form.name, 'Draft'); assert.equal(current.state.tab.value, 'links');
    current.unmount(); assert.equal(before, null);
    current = mount('components/EditProjectForm', { project: { ...project, revision: 2 }, statuses: ['Idea'], native: false });
    assert.equal(current.state.form.name, 'Draft'); assert.equal(current.state.form.links.length, 1);
    assert.equal(current.state.form.icon_file, null); assert.equal(current.state.imageNeedsSelection.value, true);
    assert.match(current.state.form.errors.revision, /changed/);
    current.state.submit(); assert.equal(callbacks.length, 1);
    const other = mount('components/EditProjectForm', { project: { ...project, id: 'two' }, statuses: ['Idea'], native: false });
    assert.equal(other.state.form.name, 'Saved'); other.unmount();
    current.state.discardDraft(); await vue.nextTick();
    assert.equal(remembered.get('project-form:one'), null);
});

test('choosing an emoji preserves its skin tone and sequence, clears pending uploads and ignores non-Unicode selections', t => {
    const current = harness(t)('components/ProjectIconPicker', { id: 'project-icon', name: 'Orbit', type: 'initials', emoji: '🪐' });
    current.state.open.value = true;

    current.state.selectEmoji({ detail: { unicode: '🧑🏽‍💻' } });

    assert.deepEqual(current.emitted, [['update:emoji', '🧑🏽‍💻'], ['update:file', null], ['update:type', 'emoji']]);
    assert.equal(current.state.open.value, false);

    current.state.open.value = true;
    current.state.selectEmoji({ detail: { name: 'custom-emoji' } });
    assert.equal(current.emitted.length, 3);
    assert.equal(current.state.open.value, true);
});

test('icon choices commit on selection and cancelling an image leaves the current icon intact', t => {
    const current = harness(t)('components/ProjectIconPicker', { id: 'project-icon', name: 'Orbit', type: 'initials', emoji: '' });
    current.state.open.value = true;
    current.state.tab.value = 'image';
    current.state.selectImage({ target: { files: [] } });

    assert.deepEqual(current.emitted, []);
    assert.equal(current.state.open.value, true);

    const file = new File(['image'], 'icon.png', { type: 'image/png' });
    current.state.selectImage({ target: { files: [file] } });

    assert.deepEqual(current.emitted, [['update:file', file], ['update:type', 'image']]);
    assert.equal(current.state.open.value, false);

    current.state.selectType('initials');
    assert.deepEqual(current.emitted.slice(2), [['update:file', null], ['update:type', 'initials']]);
});

test('project image submissions reject oversized files immediately and allow retrying with a valid file', t => {
    const requests = [];
    t.mock.method(inertia.router, 'post', (url, data, options) => requests.push({ url, data, options }));
    const mount = harness(t, { router: { on: () => () => {}, restore() {}, remember() {} } });

    for (const project of [undefined, { id: 'one', name: 'Saved', status: 'Idea', tags: [], revision: 1 }]) {
        const current = mount(project ? 'components/EditProjectForm' : 'components/CreateProjectForm', { project, statuses: ['Idea'], native: false });
        const oversized = new File([new Uint8Array(5242881)], 'large.png', { type: 'image/png' });
        current.state.form.icon_type = 'image';
        current.state.form.icon_file = oversized;
        if (project) current.state.tab.value = 'links';
        const previousRequests = requests.length;

        current.state.submit();

        assert.equal(requests.length, previousRequests);
        assert.equal(current.state.form.errors.icon_file, 'Choose an image no larger than 5 MB.');
        assert.equal(current.state.form.processing, false);
        if (project) assert.equal(current.state.tab.value, 'overview');
        assert.equal(current.state.form.icon_file, oversized);

        const valid = new File([new Uint8Array(5242880)], 'valid.png', { type: 'image/png' });
        current.state.form.icon_file = valid;
        current.state.submit();

        assert.equal(requests.length, previousRequests + 1);
        const request = requests.at(-1);
        assert.equal(request.url, project ? '/projects/one' : '/projects');
        assert.equal(request.data.icon_file, valid);
        assert.equal(request.data._method, project ? 'put' : undefined);
        request.options.onStart({});
        request.options.onError({ icon_file: 'Invalid image dimensions.' });
        request.options.onFinish({});
        assert.equal(current.state.form.errors.icon_file, 'Invalid image dimensions.');
        assert.equal(current.state.form.processing, false);
        current.unmount();
    }
});

test('discarded project drafts allow the resumed visit immediately', async t => {
    let before;
    const accepted = [];
    const destination = new URL('https://orbit.test/');
    const visit = () => {
        let prevented = false;
        before({ detail: { visit: { method: 'get', url: destination } }, preventDefault() { prevented = true; } });
        if (!prevented) accepted.push(destination.href);
    };
    const mount = harness(t, { router: { on(event, callback) { before = callback; return () => {}; }, restore() {}, remember() {}, visit }, usePage: () => ({ url: '/projects/create' }) });
    const current = mount('components/CreateProjectForm', { statuses: ['Idea'] });
    current.state.form.name = 'Draft'; await vue.nextTick();
    visit();
    assert.equal(current.state.departureOpen.value, true);
    current.state.discardDraft();
    assert.deepEqual(accepted, ['https://orbit.test/']);
    assert.equal(current.state.departureOpen.value, false);
    assert.equal(current.state.form.name, '');
});

test('closing project creation keeps a dirty draft until discard and blocks dismissal during submission', async t => {
    const current = harness(t, { router: { on: () => () => {}, restore() {}, remember() {} } })('components/CreateProjectForm', { statuses: ['Idea'] });

    current.state.requestClose();

    assert.deepEqual(current.emitted, [['cancel']]);
    current.state.form.name = 'Draft';
    await vue.nextTick();
    current.state.form.processing = true;
    current.state.requestClose();
    assert.equal(current.state.departureOpen.value, false);
    assert.equal(current.emitted.length, 1);
    current.state.form.processing = false;
    current.state.requestClose();
    assert.equal(current.state.departureOpen.value, true);
    assert.equal(current.state.form.name, 'Draft');
    assert.equal(current.emitted.length, 1);
    current.state.departureOpen.value = false;
    assert.equal(current.state.form.name, 'Draft');
    current.state.requestClose();
    current.state.discardDraft();
    assert.equal(current.state.form.name, '');
    assert.equal(current.state.departureOpen.value, false);
    assert.deepEqual(current.emitted, [['cancel'], ['cancel']]);
});

test('opening creation over an unsaved project does not trigger its departure prompt', async t => {
    let before;
    const current = harness(t, { router: { on(event, callback) { before = callback; return () => {}; }, restore() {}, remember() {} } })('components/EditProjectForm', {
        project: { id: 'one', name: 'Saved', status: 'Idea', tags: [], revision: 1 }, statuses: ['Idea'], native: false,
    });
    current.state.form.name = 'Unsaved';
    await vue.nextTick();

    before({ defaultPrevented: true, detail: { visit: { method: 'get', url: new URL('https://orbit.test/projects/create') } }, preventDefault() { assert.fail('Already intercepted'); } });

    assert.equal(current.state.departureOpen.value, false);
    assert.equal(current.state.form.name, 'Unsaved');
});

test('creation validation retains the dialog draft and link errors before a successful save closes it', async t => {
    const requests = [];
    let focused = false;
    t.mock.method(inertia.router, 'post', (url, data, options) => requests.push({ url, data, options }));
    const current = harness(t, { router: { on: () => () => {}, restore() {}, remember() {} } })('components/CreateProjectForm', { statuses: ['Idea'] });
    current.state.formElement.value = { querySelector: () => ({ focus() { focused = true; } }) };
    current.state.form.name = 'Draft';
    current.state.submit();
    const failed = requests[0].options;

    failed.onStart({});
    failed.onError({ 'links.0.url': 'Enter a valid URL.', links: 'You can add up to 200 links.' });
    failed.onFinish({});
    await vue.nextTick();

    assert.equal(current.state.form.name, 'Draft');
    assert.equal(focused, true);
    assert.equal(current.state.form.errors['links.0.url'], 'Enter a valid URL.');
    assert.deepEqual({ ...current.state.generalErrors.value }, { links: 'You can add up to 200 links.' });
    assert.deepEqual(current.emitted, []);
    current.state.submit();
    const saved = requests[1].options;
    saved.onStart({});
    saved.onSuccess({ props: { errors: {} } });
    saved.onFinish({});
    assert.deepEqual(current.emitted, [['saved']]);
    assert.equal(current.state.form.isDirty, false);
});

test('browser relink preserves the separate Add folder input', async t => {
    const current = harness(t)('components/EditProjectForm', {
        project: { id: 'project-a', tags: [], revision: 1, folders: [{ id: 'checkout', path: '/checkout', repository_id: null }] },
        statuses: ['Idea'], native: false,
    });
    current.props.native = false; current.state.inspection.path = '/independent-add-draft';
    const folder = current.state.form.folders[0]; current.state.relinkFolder(folder);
    assert.equal(current.state.replacement.path, '/checkout');
    t.mock.method(current.state.replacement, 'post', async () => { throw new Error('Offline'); });
    current.state.replacement.path = '/new-checkout'; await current.state.replaceFolder();
    assert.equal(current.state.form.folders[0].path, '/checkout'); assert.equal(current.state.replacement.path, '/new-checkout');
    t.mock.method(current.state.replacement, 'post', async () => ({ folder: { name: 'repo', path: '/new-checkout', git_state: 'Git repository' } }));
    await current.state.replaceFolder(); assert.equal(current.state.form.folders[0].id, folder.id); assert.equal(current.state.form.folders[0].path, '/new-checkout');
    assert.equal(current.state.inspection.path, '/independent-add-draft'); assert.equal(current.state.relinking.value, null);
});

test('Sources delegates connection and refresh to the correct activity record', async t => {
    const repository = { id: 'second' }; const activity = { id: 'second', provider_revision: 4 };
    const current = harness(t)('pages/ShowProject', { selectedProject: { id: 'one', tags: [], revision: 1 }, inspection: [], activity: [{ id: 'first' }, activity], connections: [], native: false });
    const calls = []; current.state.providerActivity.value = { edit: item => calls.push(['edit', item]), refresh: item => calls.push(['refresh', item]) };
    await current.state.connectProvider(repository); await current.state.refreshActivity(repository);
    assert.equal(current.state.providerActivityOpen.value, true);
    assert.deepEqual(calls.map(([action, item]) => [action, item.id, item.provider_revision]), [['edit', 'second', 4], ['refresh', 'second', 4]]);
});
