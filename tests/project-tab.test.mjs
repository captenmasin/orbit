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
    const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => modules[name] ?? {}, crypto: webcrypto, URL, setTimeout, clearTimeout };
    runInNewContext(outputText, context);
    return context.exports;
}
const projectHelpers = script(readFileSync(new URL('../resources/js/lib/project.ts', import.meta.url), 'utf8'), {});

function harness(t, inertiaOverrides = {}, notifications = []) {
    const stored = new Map();
    const storage = { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) };
    const modules = { vue, '@inertiajs/vue3': { ...inertia, ...inertiaOverrides }, '@/lib/project': projectHelpers,
        'vue-sonner': { toast: { error: message => notifications.push(message), success: message => notifications.push(message) } },
        '@vueuse/core': { ...vueuse, useSessionStorage: (key, value, options) => vueuse.useStorage(key, value, storage, options) } };
    return function mount(file, input) {
        const { descriptor } = parse(readFileSync(new URL(`../resources/js/${file}.vue`, import.meta.url), 'utf8'));
        const component = script(compileScript(descriptor, { id: 'project-tab-test' }).content, modules).default;
        const scope = vue.effectScope();
        t.after(() => scope.stop());
        const props = vue.reactive(input);
        const state = scope.run(() => component.setup(props, { expose() {} }));
        return { state, props, unmount: () => scope.stop() };
    };
}

test('project view tabs survive navigation and follow each selected project', async t => {
    const mount = harness(t);
    const view = id => mount('pages/ShowProject', { selectedProject: { id, tags: [], revision: 1 }, inspection: [], activity: [], connections: [], native: false, notesHtml: '' });
    for (const tab of ['documents', 'board', 'assets', 'dependencies', 'secrets', 'overview']) {
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

test('project overview counts cards across board columns as they change', async t => {
    const mount = harness(t);
    const current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', tags: [], revision: 1, board_columns: [
            { name: 'Backlog', tasks: [{ id: 'one' }, { id: 'two' }] },
            { name: 'Empty', tasks: [] },
            { name: 'Done', tasks: [{ id: 'three' }] },
        ] },
        inspection: [], activity: [], connections: [], native: false,
    });

    assert.equal(current.state.taskCount.value, 3);
    assert.deepEqual(Array.from(current.state.previewColumns.value, column => column.name), ['Backlog', 'Done']);
    current.props.selectedProject.board_columns = [{ tasks: [] }];
    await vue.nextTick();
    assert.equal(current.state.taskCount.value, 0);
    assert.equal(current.state.previewColumns.value.length, 0);
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
            links: Array.from({ length: 10 }, (_, index) => ({ id: String(index) })),
        },
        inspection: [
            { id: 'folder', availability: 'Missing', scan_error: null, scanned_at: '2026-09-01T10:00:00Z' },
            { id: 'folder-two', availability: 'Available', scan_error: null, scanned_at: '2026-09-04T10:00:00Z' },
        ], activity: [], connections: [], native: false,
    });

    assert.deepEqual(Array.from(current.state.recentDocuments.value, document => document.id), ['newest', 'middle']);
    assert.equal(current.state.visibleLinks.value.length, 10);
    assert.equal(current.state.folderIssueCount.value, 1);

    page.url = '/projects/project-a';
    await vue.nextTick();
    assert.deepEqual(Array.from(current.state.visibleLinks.value, link => link.id), ['0', '1', '2']);
    current.state.linksOpen.value = true;
    assert.equal(current.state.visibleLinks.value.length, 10);
});

test('project links appear under alphabetic category headings with uncategorized last', t => {
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
    const groups = () => Array.from(current.state.linkGroups.value, group => [group.category, Array.from(group.links, link => link.id)]);

    assert.deepEqual(groups(), [['Documentation', ['docs']], ['Hosting', ['hosting']], ['Social', ['social-a']]]);
    current.state.linksOpen.value = true;
    assert.deepEqual(groups(), [['Documentation', ['docs']], ['Hosting', ['hosting']], ['Social', ['social-a', 'social-b']], ['Uncategorized', ['uncategorized']]]);
});

test('scratchpad suggestions include project actions and saved notes clear without losing new drafts', async t => {
    const requests = [];
    const actions = [{ type: 'link', label: 'Buff', url: 'https://usebuff.app/', description: '' }];
    const http = {
        revision: 0, processing: false, errors: {}, clearErrors() {},
        async post(url) {
            requests.push({ url, revision: this.revision });
            return { actions, statuses: ['Idea', 'In Progress'] };
        },
    };
    const mount = harness(t, { useHttp: () => http });
    const current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', revision: 3, scratchpad: 'https://usebuff.app/', tags: [], links: [], repositories: [], board_columns: [] },
        inspection: [], activity: [], connections: [], native: false,
    });
    const reviewed = [];
    current.state.actionReview.value = { begin: suggestions => reviewed.push(...suggestions) };

    await current.state.generateActions();

    assert.deepEqual(requests.at(-1), { url: '/projects/project-a/scratchpad/actions/preview', revision: 3 });
    assert.deepEqual(reviewed, actions);
    assert.deepEqual(Array.from(current.state.actionStatuses.value), ['Idea', 'In Progress']);

    current.props.selectedProject = { ...current.props.selectedProject, revision: 4, scratchpad: '' };
    await vue.nextTick();
    assert.equal(current.state.scratchpadForm.scratchpad, '');

    current.state.scratchpadForm.scratchpad = 'New unsaved note';
    current.props.selectedProject = { ...current.props.selectedProject, revision: 5 };
    await vue.nextTick();
    assert.equal(current.state.scratchpadForm.scratchpad, 'New unsaved note');
});

test('scratchpad autosaves settled notes and prepares actions without losing edits made during a save', async t => {
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
                finishSave = async (result = { revision: revision + 1 }) => {
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
        revision: 0, processing: false, errors: {}, clearErrors() {},
        async post(url) { previews.push({ url, revision: this.revision }); return { actions, statuses: ['Idea'] }; },
    };
    const router = { on() { return () => {}; }, replaceProp(name, value) { current.props[name] = value(current.props[name]); } };
    const mount = harness(t, { router, useHttp: data => 'scratchpad' in data ? saveHttp : http }, notifications);
    current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', revision: 1, scratchpad: '', tags: [], links: [], repositories: [], board_columns: [] },
        inspection: [], activity: [], connections: [], native: false,
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
    assert.deepEqual(previews, [{ url: '/projects/project-a/scratchpad/actions/preview', revision: 4 }]);
    assert.deepEqual(Array.from(current.state.suggestedActions.value), actions);
    assert.equal(reviewed.length, 0);

    await current.state.generateActions();
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
    assert.equal(notifications.at(-1), 'Could not save notes. Try again.');
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
        replaceProp(name, value) { current.props[name] = value(current.props[name]); },
    };
    let finishSave;
    const saveHttp = {
        revision: 0, scratchpad: '', processing: false, errors: {},
        put() {
            this.processing = true;
            return new Promise(resolve => { finishSave = () => { this.processing = false; resolve({ revision: 2 }); }; });
        },
    };
    const mount = harness(t, { router, useHttp: data => 'scratchpad' in data ? saveHttp : inertia.useHttp(data) });
    current = mount('pages/ShowProject', {
        selectedProject: { id: 'project-a', revision: 1, scratchpad: '', tags: [], links: [], repositories: [], board_columns: [] },
        inspection: [], activity: [], connections: [], native: false,
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
    assert.equal(current.props.selectedProject.scratchpad, 'Take this with me');
    assert.deepEqual(destinations, ['http://orbit.local/projects/project-b']);
});

test('edit forms retain dirty names and tags when the saved revision changes', async t => {
    const mount = harness(t);
    const current = mount('components/ProjectForm', { project: { id: 'project-a', tags: [], revision: 1 }, statuses: ['Idea'], native: false });
    current.state.form.name = 'Unsaved name';
    current.state.form.tags = ['new-tag'];
    await vue.nextTick();
    current.props.project.revision = 2;
    await vue.nextTick();
    assert.equal(current.state.form.name, 'Unsaved name');
    assert.deepEqual(Array.from(current.state.form.tags), ['new-tag']);
    const create = mount('components/ProjectForm', { statuses: ['Idea'], native: false });
    assert.equal(create.state.tab.value, 'overview');
});

test('folder creation uses the folder name and derives repository names without overwriting custom names', t => {
    const mount = harness(t);
    const current = mount('components/ProjectForm', { statuses: ['Idea'], native: false });
    current.state.useFolder({ name: 'Folder name', path: '/projects/Folder name', remote_url: 'git@github.com:owner/repository.git', description: 'Description', git_state: 'Git repository' });
    assert.equal(current.state.form.name, 'Folder name');
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
        path: '', processing: false, errors: {}, hasErrors: false,
        async post() {
            if (rejected) throw new Error('Inspection failed');
            return { folder };
        },
        setError(key, message) { this.errors[key] = message; this.hasErrors = true; },
        clearErrors() { this.errors = {}; this.hasErrors = false; },
    });
    const mount = harness(t, { useHttp: () => inspection }, notifications);
    const current = mount('components/ProjectForm', { statuses: ['Idea'], native: true });

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

test('GitHub selection links the chosen connection and does not add the same remote twice', t => {
    const mount = harness(t);
    const current = mount('components/ProjectForm', { statuses: ['Idea'], native: true, connections: [{ id: 'connection', label: 'Work' }] });
    const repository = { connection_id: 'connection', full_name: 'team/repo', name: 'repo', remote_url: 'https://github.com/team/repo.git', description: 'Project description' };
    current.state.addGitHubRepository(repository);
    assert.equal(current.state.form.name, 'repo');
    assert.equal(current.state.form.description, 'Project description');
    assert.equal(current.state.form.repositories[0].github_connection_id, 'connection');
    assert.equal(current.state.form.repositories[0].github_full_name, 'team/repo');
    current.state.addGitHubRepository({ ...repository, remote_url: 'https://github.com/team/repo' });
    assert.equal(current.state.form.repositories.length, 1);
});
