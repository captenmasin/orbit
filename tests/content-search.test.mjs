import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, compileTemplate, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t, properties = {}) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ContentSearch.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'content-search-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const props = vue.reactive({ launcher: false, compact: false, recentItems: [], ...properties });
    const requests = [];
    const timers = new Map();
    const visits = [];
    const events = [];
    const eventArguments = [];
    const toasts = [];
    let timerId = 0;
    let unmount;
    const modules = {
        vue: { ...vue, onBeforeUnmount: callback => unmount = callback },
        '@inertiajs/vue3': { router: { visit: url => visits.push(url) } },
        'vue-sonner': { toast: { error: message => toasts.push(message) } },
    };
    const context = {
        exports: {},
        require: name => modules[name] ?? {},
        AbortController,
        URLSearchParams,
        setTimeout: (callback, delay) => { const id = ++timerId; timers.set(id, { callback, delay }); return id; },
        clearTimeout: id => timers.delete(id),
        fetch: (url, options) => new Promise((resolve, reject) => requests.push({ url, ...options, resolve, reject })),
    };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    const state = scope.run(() => context.exports.default.setup(props, { expose() {}, emit: (event, ...arguments_) => { events.push(event); eventArguments.push(arguments_); } }));
    const stop = () => { unmount(); scope.stop(); };
    t.after(stop);
    const render = () => {
        const template = compileTemplate({ source: descriptor.template.content, filename: 'ContentSearch.vue', id: 'content-search-test', compilerOptions: { expressionPlugins: ['typescript'] } });
        assert.deepEqual(template.errors, []);
        const { outputText } = ts.transpileModule(template.code, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
        const rendered = { exports: {}, require: () => ({ ...vue, resolveComponent: name => name }) };
        runInNewContext(outputText, rendered);
        return rendered.exports.render(vue.proxyRefs({ ...state, ...props }), []);
    };
    const runTimer = () => {
        assert.equal(timers.size, 1);
        const [id, timer] = timers.entries().next().value;
        assert.equal(timer.delay, 200);
        timers.delete(id);
        timer.callback();
    };
    return { ...state, props, requests, timers, visits, events, eventArguments, toasts, runTimer, stop, render };
}

function findNode(node, type) {
    if (node.type === type) return node;
    for (const child of Array.isArray(node.children) ? node.children : []) {
        const found = findNode(child, type);
        if (found) return found;
    }
}

async function settle() {
    await vue.nextTick();
    await new Promise(resolve => setImmediate(resolve));
}

function respond(request, results = [], hasMore = false, metadata = {}) {
    request.resolve({ ok: true, json: async () => ({ results, has_more: hasMore, ...metadata }) });
}

test('launcher stays open for prevented input interactions and closes for outside clicks', t => {
    const state = mount(t, { launcher: true });
    const input = findNode(state.render(), 'ComboboxInput');
    input.props['onUpdate:modelValue']('deploy');
    assert.equal(state.query.value, 'deploy');
    const content = findNode(state.render(), 'ComboboxContent');
    const inputInteraction = new Event('pointerDownOutside', { cancelable: true });
    inputInteraction.preventDefault();

    content.props.onPointerDownOutside(inputInteraction);
    assert.deepEqual(state.events, []);

    content.props.onPointerDownOutside(new Event('pointerDownOutside', { cancelable: true }));
    assert.deepEqual(state.events, ['close']);
});

test('launcher debounces typing and ignores cancelled responses after changing or clearing the query', async t => {
    const state = mount(t, { launcher: true });
    state.query.value = 'render';
    await vue.nextTick();
    state.query.value = 'renderer';
    await vue.nextTick();
    assert.equal(state.requests.length, 0);
    state.runTimer();
    assert.equal(new URL(state.requests[0].url, 'https://orbit.test').searchParams.get('q'), 'renderer');

    state.query.value = 'deploy';
    await vue.nextTick();
    assert.equal(state.requests[0].signal.aborted, true);
    respond(state.requests[0], [{ id: 'old', title: 'Old result' }]);
    await settle();
    assert.equal(state.results.value.length, 0);
    state.runTimer();
    assert.equal(state.requests.length, 2);

    state.query.value = ' ';
    await vue.nextTick();
    assert.equal(state.requests[1].signal.aborted, true);
    respond(state.requests[1], [{ id: 'late', title: 'Late result' }], true);
    await settle();
    assert.equal(state.results.value.length, 0);
    assert.equal(state.searched.value, false);
    assert.equal(state.loading.value, false);
    assert.equal(state.hasMore.value, false);
    assert.equal(state.timers.size, 0);
    assert.deepEqual(state.toasts, []);
});

test('launcher opens a result only after searching finishes', async t => {
    const state = mount(t, { launcher: true });
    const result = { id: 'document', type: 'document', title: 'Deploy', project: 'Orbit', excerpt: '', url: '/projects/project?tab=documents&document=document' };
    state.query.value = 'deploy';
    await vue.nextTick();
    state.runTimer();
    state.openResult(result);
    assert.deepEqual(state.visits, []);

    respond(state.requests[0], [result], true);
    await settle();
    assert.equal(state.results.value[0].title, 'Deploy');
    assert.equal(state.hasMore.value, true);
    state.openResult();
    assert.deepEqual(state.visits, []);
    state.openResult(state.results.value[0]);
    assert.deepEqual(state.visits, [result.url]);
    assert.deepEqual(state.events, ['navigate']);
    assert.equal(state.eventArguments[0][0], state.results.value[0]);
});

test('launcher offers workspace commands and matches their titles and aliases', t => {
    const state = mount(t, { launcher: true });
    assert.deepEqual(Array.from(state.matchingCommands.value, command => command.title), ['New project', 'Settings', 'Backups', 'Go to dashboard']);

    for (const [query, commandId, url] of [
        ['NEW PROJECT', 'new-project', '/projects/create'],
        ['add project', 'new-project', '/projects/create'],
        ['settings', 'settings', '/settings'],
        ['appearance preferences', 'settings', '/settings'],
        ['backups', 'backups', '/settings/backups'],
        ['restore export', 'backups', '/settings/backups'],
        ['go to dashboard', 'dashboard', '/'],
        ['home workspace', 'dashboard', '/'],
    ]) {
        state.query.value = query;
        assert.deepEqual(Array.from(state.matchingCommands.value, command => command.id), [commandId]);
        state.openResult(state.matchingCommands.value[0]);
        assert.equal(state.visits.at(-1), url);
    }
    state.query.value = 'home restore';
    assert.equal(state.matchingCommands.value.length, 0);

    for (const query of ['type:task home', 'project:Orbit settings', 'TYPE:document preferences', 'project:"My project" export']) {
        state.query.value = query;
        assert.equal(state.matchingCommands.value.length, 0);
    }
});

test('empty launcher groups recent items and commands and allows clearing recent items', t => {
    const recent = { id: 'recent', type: 'document', title: 'Deployment notes', project: 'Orbit', excerpt: '', url: '/projects/orbit?tab=documents&document=recent' };
    const state = mount(t, { launcher: true, recentItems: [recent] });
    assert.deepEqual(Array.from(state.resultGroups.value, group => group.heading), ['Recent items', 'Commands']);
    assert.equal(state.resultGroups.value[0].items[0].id, 'recent');
    assert.equal(state.itemCount.value, 5);
    assert.equal(state.requests.length, 0);

    const clearButton = findNode(state.render(), 'Button');
    assert.equal(clearButton.props['aria-label'], 'Clear recent items');
    clearButton.props.onClick();
    assert.deepEqual(state.events, ['clearRecents']);

    state.query.value = 'notes';
    assert.deepEqual(Array.from(state.resultGroups.value, group => group.heading), ['Commands', 'Results']);
    assert.equal(state.resultGroups.value.some(group => group.items.some(item => item.id === 'recent')), false);
});

test('clearing recent items removes their selection without changing active search selection', async t => {
    const recent = { id: 'recent', type: 'document', title: 'Deployment notes', project: 'Orbit', excerpt: '', url: '/projects/orbit?tab=documents&document=recent' };
    const state = mount(t, { launcher: true, recentItems: [recent] });
    let highlightCalls = 0;
    state.combobox.value = { highlightFirstItem: () => highlightCalls++ };
    await settle();
    state.highlightedResult.value = recent;
    highlightCalls = 0;

    state.props.recentItems = [];
    await settle();
    assert.equal(state.highlightedResult.value, undefined);
    assert.equal(highlightCalls, 1);

    state.query.value = 'settings';
    await settle();
    const command = state.matchingCommands.value[0];
    state.highlightedResult.value = command;
    const selectedCommand = state.highlightedResult.value;
    highlightCalls = 0;
    state.props.recentItems = [recent];
    await settle();
    assert.equal(state.highlightedResult.value, selectedCommand);
    assert.equal(highlightCalls, 0);
});

test('launcher opens commands while content results are still loading', async t => {
    const state = mount(t, { launcher: true });
    const content = { id: 'document', type: 'document', title: 'Settings notes', project: 'Orbit', excerpt: '', url: '/projects/orbit?tab=documents&document=document' };
    state.query.value = 'settings';
    await vue.nextTick();
    state.runTimer();

    state.openResult(content);
    assert.deepEqual(state.visits, []);
    state.openResult(state.matchingCommands.value[0]);
    assert.deepEqual(state.visits, ['/settings']);
    assert.equal(state.eventArguments[0][0].type, 'command');
});

test('workspace search forwards the preferred project without narrowing the scope', async t => {
    const state = mount(t, { launcher: true, preferredProjectId: 'orbit' });
    state.query.value = 'deploy';
    await vue.nextTick();
    state.runTimer();

    const parameters = new URL(state.requests[0].url, 'https://orbit.test').searchParams;
    assert.equal(parameters.get('preferred_project_id'), 'orbit');
    assert.equal(parameters.has('project_id'), false);
});

test('filtered response highlights the search term literally and preserves plain text content', async t => {
    const state = mount(t, { launcher: true });
    const title = '<img src=x onerror=alert(1)> Release [v2].';
    const result = { id: 'document', type: 'document', title, project: 'Orbit', excerpt: 'Before [v2]. after', url: '/projects/orbit?tab=documents&document=document' };
    state.query.value = 'type:document project:Orbit [v2].';
    await vue.nextTick();
    state.runTimer();
    respond(state.requests[0], [result], false, { term: '[v2].', filters: { type: 'document', project: 'Orbit' } });
    await settle();

    assert.equal(state.matchTerm.value, '[v2].');
    assert.equal(state.filters.value.type, 'document');
    assert.equal(state.filters.value.project, 'Orbit');
    assert.deepEqual(Array.from(state.highlightedParts(title)), ['<img src=x onerror=alert(1)> Release ', '[v2].', '']);
    assert.deepEqual(Array.from(state.highlightedParts('Before [V2]. after')), ['Before ', '[V2].', ' after']);
    assert.deepEqual(Array.from(state.highlightedParts('type:document project:Orbit')), ['type:document project:Orbit']);
    const markup = findNode(state.render(), 'mark');
    assert.equal(markup.children, '[v2].');
    assert.equal(markup.props.innerHTML, undefined);
    assert.equal(findNode(state.render(), 'img'), undefined);
});

test('launcher shows the validation message returned by an invalid filter query', async t => {
    const state = mount(t, { launcher: true });
    state.query.value = 'type:invalid';
    await vue.nextTick();
    state.runTimer();
    state.requests[0].resolve({ ok: false, status: 422, json: async () => ({ errors: { q: ['The search type must be project, document, task, link, or secret.'] } }) });
    await settle();

    assert.equal(state.error.value, 'The search type must be project, document, task, link, or secret.');
    assert.equal(state.loading.value, false);
    assert.deepEqual(state.toasts, []);
});

test('launcher shows an inline failure and allows retrying the query', async t => {
    const state = mount(t, { launcher: true });
    state.query.value = 'deploy';
    await vue.nextTick();
    state.runTimer();
    state.requests[0].resolve({ ok: false, status: 500, json: async () => ({ message: 'Server error' }) });
    await settle();
    assert.match(state.error.value, /could not be completed/i);
    assert.equal(state.loading.value, false);
    assert.deepEqual(state.toasts, []);

    const retry = state.search();
    respond(state.requests[1], [{ id: 'retry', title: 'Deploy' }]);
    await retry;
    assert.equal(state.error.value, '');
    assert.equal(state.results.value[0].id, 'retry');
});

test('launcher clears pending timers and requests when unmounted', async t => {
    const pending = mount(t, { launcher: true });
    pending.query.value = 'pending';
    await vue.nextTick();
    pending.stop();
    assert.equal(pending.timers.size, 0);
    assert.equal(pending.requests.length, 0);

    const searching = mount(t, { launcher: true });
    searching.query.value = 'deploy';
    await vue.nextTick();
    searching.runTimer();
    searching.stop();
    assert.equal(searching.requests[0].signal.aborted, true);
    respond(searching.requests[0], [{ id: 'late', title: 'Late result' }]);
    await settle();
    assert.equal(searching.results.value.length, 0);
});

test('compact project search stays manual, scoped, and resets when the project changes', async t => {
    const state = mount(t, { compact: true, projectId: 'project' });
    state.query.value = 'redis';
    await vue.nextTick();
    assert.equal(state.timers.size, 0);
    assert.equal(state.requests.length, 0);

    const search = state.search();
    const parameters = new URL(state.requests[0].url, 'https://orbit.test').searchParams;
    assert.equal(parameters.get('q'), 'redis');
    assert.equal(parameters.get('project_id'), 'project');
    respond(state.requests[0], [{ id: 'document', title: 'Redis setup' }]);
    await search;
    assert.equal(state.results.value[0].title, 'Redis setup');
    state.props.projectId = 'other-project';
    await vue.nextTick();
    assert.equal(state.query.value, '');
    assert.equal(state.results.value.length, 0);

    state.query.value = 'database';
    const failedSearch = state.search();
    state.requests[1].reject(new Error('Offline'));
    await failedSearch;
    assert.deepEqual(state.toasts, ['Search could not be completed. Try again.']);
});
