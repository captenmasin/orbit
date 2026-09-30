import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t, overrides = {}) {
    const props = vue.reactive({ connections: [{ id: 'github', provider: 'github' }, { id: 'gitlab', provider: 'gitlab' }], existingUrls: [], native: true, busy: false, ...overrides });
    const listing = { processing: false, errors: {}, cancel() {}, async get() { return { repositories: [], next_page: null }; } };
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ConnectedRepositoryPicker.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'repository-picker-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => name === 'vue' ? { ...vue, useId: () => 'picker', onBeforeUnmount: vue.onScopeDispose } : name === '@inertiajs/vue3' ? { useHttp: () => listing } : {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const events = [];
    const state = scope.run(() => context.exports.default.setup(props, { expose() {}, emit: (event, repository) => events.push({ event, repository: JSON.parse(JSON.stringify(repository)) }) }));
    return { state, props, listing, events };
}

const orbit = { id: 'orbit', full_name: 'team/orbit', name: 'orbit', remote_url: 'https://github.com/team/orbit.git', private: false, description: null };
const docs = { id: 'docs', full_name: 'team/docs', name: 'docs', remote_url: 'https://gitlab.com/team/docs.git', private: true, description: 'Project docs' };

test('adds selected repositories across accounts and pages only after confirmation', async t => {
    const { state, listing, events } = mount(t);
    listing.get = async function (url) {
        if (url.includes('gitlab')) return { repositories: [docs], next_page: null };
        return { repositories: this.page === 1 ? [orbit] : [{ ...orbit, id: 'same-repository' }], next_page: this.page === 1 ? 2 : null };
    };
    state.open.value = true;
    await vue.nextTick();
    state.choose(orbit);
    await state.load(2);
    state.query.value = 'docs';
    assert.equal(state.visible.value.length, 0);
    assert.equal(state.selected(orbit), true);

    state.connectionId.value = 'gitlab';
    await vue.nextTick();
    state.choose(docs);
    assert.equal(state.open.value, true);
    assert.deepEqual(events, []);

    state.addSelected();
    await vue.nextTick();

    assert.deepEqual(events, [
        { event: 'select', repository: { ...orbit, connection_id: 'github' } },
        { event: 'select', repository: { ...docs, connection_id: 'gitlab' } },
    ]);
    assert.equal(state.open.value, false);
    assert.equal(state.selectedRepositories.value.length, 0);
});

test('toggles duplicate remotes and skips already linked repositories', async t => {
    const { state, props, events } = mount(t, { existingUrls: [' HTTPS://GITLAB.COM/TEAM/DOCS/ '] });
    state.open.value = true;
    await vue.nextTick();
    state.choose(docs);
    assert.equal(state.selectedRepositories.value.length, 0);
    state.choose(orbit);
    state.choose({ ...orbit, remote_url: 'https://github.com/team/orbit' });
    assert.equal(state.selectedRepositories.value.length, 0);
    state.choose(orbit);
    props.existingUrls.push(orbit.remote_url);

    state.addSelected();

    assert.deepEqual(events, []);
});

test('cancel discards selections and busy projects cannot add repositories', async t => {
    const { state, props, events } = mount(t);
    state.open.value = true;
    await vue.nextTick();
    state.choose(orbit);
    state.query.value = 'orbit';
    props.busy = true;
    state.choose(docs);
    state.addSelected();
    assert.equal(state.selectedRepositories.value.length, 1);
    assert.equal(state.open.value, true);

    state.open.value = false;
    await vue.nextTick();
    state.open.value = true;
    await vue.nextTick();

    assert.equal(state.selectedRepositories.value.length, 0);
    assert.equal(state.query.value, '');
    state.addSelected();
    assert.deepEqual(events, []);
});
