import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import * as vueuse from '@vueuse/core';
import ts from 'typescript';
import * as vue from 'vue';

test('project tabs survive remounts and inspection updates preserve unsaved catalog input', async t => {
    const stored = new Map();
    const storage = {
        getItem: key => stored.get(key) ?? null,
        setItem: (key, value) => stored.set(key, value),
        removeItem: key => stored.delete(key),
    };
    // Keep VueUse's real storage behavior, substituting only the browser storage backend.
    const modules = {
        vue,
        '@inertiajs/vue3': inertia,
        '@vueuse/core': { ...vueuse, useSessionStorage: (key, value, options) => vueuse.useStorage(key, value, storage, options) },
    };
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectForm.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'project-tab-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    function mount(id) {
        const scope = vue.effectScope();
        t.after(() => scope.stop());
        const props = vue.reactive({ project: id ? { id, tags: [], revision: 1 } : undefined, scanFolders: [], statuses: ['Idea'], native: false });
        const form = scope.run(() => context.exports.default.setup(props, { expose() {} }));
        return { form, props, unmount: () => scope.stop() };
    }

    for (const tab of ['board', 'dependencies', 'repositories', 'links', 'overview']) {
        const current = mount('project-a');
        current.form.tab.value = tab;
        current.unmount();
        const reloaded = mount('project-a');
        assert.equal(reloaded.form.tab.value, tab, 'Even an immediate reload restores the current tab');
        reloaded.unmount();
    }
    mount('project-a').form.tab.value = 'board';
    assert.equal(mount('project-b').form.tab.value, 'overview');
    const create = mount();
    create.form.tab.value = 'links';
    create.unmount();
    assert.equal(mount().form.tab.value, 'overview');
    assert.equal(mount('project-a').form.tab.value, 'board');

    const draft = mount('dirty-project');
    draft.form.form.name = 'Unsaved catalog name';
    draft.props.scanFolders = [{ id: 'folder', path: '/project', branch: 'new-branch', last_commit_at: '2026-09-15T12:00:00Z' }];
    await vue.nextTick();
    assert.equal(draft.form.form.name, 'Unsaved catalog name');
    assert.equal(draft.form.latestCommit.value.branch, 'new-branch');
    assert.equal(draft.props.project.revision, 1);
});
