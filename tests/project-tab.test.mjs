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
    const context = { exports: {}, require: name => modules[name] ?? {}, crypto: webcrypto, URL };
    runInNewContext(outputText, context);
    return context.exports;
}
const projectHelpers = script(readFileSync(new URL('../resources/js/lib/project.ts', import.meta.url), 'utf8'), {});

function harness(t) {
    const stored = new Map();
    const storage = { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) };
    const modules = { vue, '@inertiajs/vue3': inertia, '@/lib/project': projectHelpers,
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
    for (const tab of ['board', 'dependencies', 'secrets', 'overview']) {
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

test('edit forms retain dirty names, notes and tags when the saved revision changes', async t => {
    const mount = harness(t);
    const current = mount('components/ProjectForm', { project: { id: 'project-a', tags: [], revision: 1 }, statuses: ['Idea'], native: false });
    current.state.form.name = 'Unsaved name';
    current.state.form.notes = 'Unsaved notes';
    current.state.form.tags = ['new-tag'];
    await vue.nextTick();
    current.props.project.revision = 2;
    await vue.nextTick();
    assert.equal(current.state.form.name, 'Unsaved name');
    assert.equal(current.state.form.notes, 'Unsaved notes');
    assert.deepEqual(Array.from(current.state.form.tags), ['new-tag']);
    const create = mount('components/ProjectForm', { statuses: ['Idea'], native: false });
    assert.equal(create.state.tab.value, 'overview');
});

test('folder creation uses the folder name and derives repository names without overwriting custom names', t => {
    const mount = harness(t);
    const current = mount('components/ProjectForm', { statuses: ['Idea'], native: false });
    current.state.preview.value = { name: 'Folder name', path: '/projects/Folder name', remote_url: 'git@github.com:owner/repository.git', description: 'Description', git_state: 'Git repository' };
    current.state.useFolder();
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
