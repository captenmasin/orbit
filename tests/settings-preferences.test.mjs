import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

const { http } = inertia;
const paths = Object.fromEntries(['php', 'node', 'composer', 'npm', 'pnpm', 'yarn'].map(tool => [tool, null]));
const values = {
    general: { startup_destination: 'dashboard' }, appearance: { theme: 'system', reduce_motion: 'system' },
    security: { lock_minutes: 15, clipboard_seconds: 30 },
    project_defaults: { columns: [{ name: 'Backlog', color: null }, { name: 'Done', color: 'Green' }] },
    tools: { paths },
};

function mount(t, overrides = {}) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/pages/Settings.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'preferences-test' }).content,
        { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const themes = [];
    const motions = [];
    const confirmations = [];
    const successes = [];
    const props = vue.reactive({
        native: false, pinSet: false, preferences: { revision: 7, values: structuredClone(values) }, section: 'general',
        columnColors: ['Green'], defaultColumns: structuredClone(values.project_defaults.columns), connections: [],
        ai: { configured: false, provider: null, model: null, providers: ['openai'] }, launchAtLogin: null,
        nativeError: null, about: { version: '1.0.0', updatesAvailable: true, releaseNotes: null }, mcp: null,
        ...overrides,
    });
    const modules = {
        vue: { ...vue, onMounted() {}, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia,
        '@/lib/appearance': { applyAppearance: theme => themes.push(theme), applyMotionPreference: motion => motions.push(motion) },
        'vue-sonner': { toast: { error() {}, success: message => successes.push(message) } },
    };
    const browserWindow = { addEventListener() {}, removeEventListener() {}, confirm: message => { confirmations.push(message); return true; } };
    const context = { exports: {}, require: name => modules[name] ?? {}, structuredClone,
        URL, setInterval, clearInterval, window: browserWindow };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const state = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    return { ...state, themes, motions, confirmations, successes, browserWindow, scope };
}

function response(data, status = 200) {
    return { status, data: JSON.stringify(data), headers: {} };
}

test('saving one section preserves other drafts and does not dirty untouched sections through the revision', async t => {
    const state = mount(t);
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return response({ preferences: { revision: 8, values: { ...values, general: { startup_destination: 'last_project' } } } });
    });
    state.general.startup_destination = 'last_project';
    state.appearance.theme = 'dark';
    state.appearance.reduce_motion = 'on';
    state.board.columns[0].name = 'Ideas';

    await state.save('general');
    await vue.nextTick();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/settings/general');
    assert.deepEqual(JSON.parse(requests[0].data), { revision: 7, startup_destination: 'last_project' });
    assert.equal(state.appearance.theme, 'dark');
    assert.equal(state.appearance.reduce_motion, 'on');
    assert.equal(state.board.columns[0].name, 'Ideas');
    assert.equal(state.general.isDirty, false);
    assert.equal(state.security.isDirty, false);
    assert.equal(state.tools.isDirty, false);
    assert.equal(state.appearance.isDirty, true);
    assert.equal(state.board.isDirty, true);
    assert.equal(state.appearance.revision, 8);
    assert.equal(state.savedTheme.value, 'system');
    assert.deepEqual(state.successes, ['Settings saved.']);
});

test('a stale save keeps the draft and saved preference intact and reports no success', async t => {
    const state = mount(t, { section: 'appearance' });
    t.mock.method(http.getClient(), 'request', async () => response({ message: 'Settings changed in another window. Reload before saving.' }, 409));
    state.appearance.theme = 'dark';
    state.appearance.reduce_motion = 'on';

    await state.save('appearance');

    assert.match(state.error.value, /Settings changed in another window/);
    assert.deepEqual(state.successes, []);
    assert.equal(state.revision.value, 7);
    assert.equal(state.savedTheme.value, 'system');
    assert.equal(state.savedMotion.value, 'system');
    assert.equal(state.appearance.theme, 'dark');
    assert.equal(state.appearance.reduce_motion, 'on');
    assert.equal(state.appearance.isDirty, true);
});

test('launch at login shows a success toast after the operating system confirms the setting', async t => {
    const state = mount(t, { native: true, launchAtLogin: false });
    t.mock.method(http.getClient(), 'request', async () => response({ enabled: true }));
    state.login.enabled = true;

    await state.saveLogin();

    assert.equal(state.login.enabled, true);
    assert.deepEqual(state.successes, ['Launch at login confirmed.']);
});

test('a failed launch at login save shows the error without a success toast', async t => {
    const state = mount(t, { native: true, launchAtLogin: false });
    t.mock.method(http.getClient(), 'request', async () => response({ message: 'The operating system could not confirm launch at login.' }, 503));

    await state.saveLogin();

    assert.equal(state.error.value, 'The operating system could not confirm launch at login.');
    assert.deepEqual(state.successes, []);
});

test('leaving appearance restores the saved theme and motion after an unsaved preview', async t => {
    const state = mount(t, { section: 'appearance' });
    state.appearance.theme = 'dark';
    state.appearance.reduce_motion = 'on';
    await vue.nextTick();
    assert.deepEqual(state.themes, ['dark']);
    assert.deepEqual(state.motions, ['on']);

    state.activeSection.value = 'general';
    await vue.nextTick();

    assert.equal(state.appearance.theme, 'dark');
    assert.deepEqual(state.themes, ['dark', 'system']);
    assert.equal(state.appearance.reduce_motion, 'on');
    assert.deepEqual(state.motions, ['on', 'system']);
});

test('unmounting settings restores an unsaved appearance preview', async t => {
    const state = mount(t, { section: 'appearance' });
    state.appearance.theme = 'light';
    state.appearance.reduce_motion = 'on';
    await vue.nextTick();

    state.scope.stop();

    assert.deepEqual(state.themes, ['light', 'system']);
    assert.deepEqual(state.motions, ['on', 'system']);
});

test('saving appearance keeps the confirmed motion preference when leaving or unmounting', async t => {
    const state = mount(t, { section: 'appearance' });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return response({ preferences: { revision: 8, values: { ...values, appearance: { theme: 'system', reduce_motion: 'on' } } } });
    });
    state.appearance.reduce_motion = 'on';
    await vue.nextTick();

    await state.save('appearance');
    state.activeSection.value = 'general';
    await vue.nextTick();
    state.scope.stop();

    assert.deepEqual(JSON.parse(requests[0].data), { revision: 7, theme: 'system', reduce_motion: 'on' });
    assert.equal(state.savedMotion.value, 'on');
    assert.equal(state.appearance.reduce_motion, 'on');
    assert.equal(state.appearance.isDirty, false);
    assert.deepEqual(state.motions, ['on', 'on', 'on']);
    assert.deepEqual(state.successes, ['Settings saved.']);
});

test('board ordering and Restore default columns keep reactive props independent from editable drafts', async t => {
    const state = mount(t, { section: 'project_defaults' });
    state.board.columns[0].name = 'Ideas';
    state.board.columns = [state.board.columns[1], state.board.columns[0]];
    assert.deepEqual(Array.from(state.board.columns, column => column.name), ['Done', 'Ideas']);
    assert.equal(state.props.preferences.values.project_defaults.columns[0].name, 'Backlog');

    state.restoreDefaultColumns();
    await vue.nextTick();

    assert.deepEqual(JSON.parse(JSON.stringify(state.board.columns)), values.project_defaults.columns);
    state.board.columns[0].name = 'Draft';
    assert.equal(state.props.defaultColumns[0].name, 'Backlog');
});

test('keyboard column reordering preserves identity and focus and stops at boundaries or while saving', async t => {
    const state = mount(t, { section: 'project_defaults' });
    const column = state.board.columns[1];
    const key = state.columnKey(column);
    const handle = { focus: t.mock.fn() };

    await state.moveColumn(1, -1, { currentTarget: handle });

    assert.equal(state.board.columns[0], column);
    assert.equal(state.columnKey(state.board.columns[0]), key);
    assert.equal(state.columnAnnouncement.value, 'Done moved to position 1 of 2.');
    assert.equal(handle.focus.mock.callCount(), 1);

    await state.moveColumn(0, -1);
    await state.moveColumn(1, 1);
    state.board.processing = true;
    await state.moveColumn(0, 1);
    assert.deepEqual(Array.from(state.board.columns, column => column.name), ['Done', 'Backlog']);
});

test('the executable picker changes only its selected draft path and clears obsolete probe results', async t => {
    const state = mount(t, { native: true, section: 'tools' });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return response({ path: '/selected/php' });
    });
    state.tools.paths.node = '/selected/node';
    await vue.nextTick();
    state.runtimeResults.value = { php: { path: '/old/php', state: 'Current', version: '8.4.0' } };

    await state.pickTool('php');
    await vue.nextTick();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/settings/tools/pick');
    assert.deepEqual(JSON.parse(requests[0].data), { tool: 'php' });
    assert.equal(state.tools.paths.php, '/selected/php');
    assert.equal(state.tools.paths.node, '/selected/node');
    assert.equal(Object.keys(state.runtimeResults.value).length, 0);
    assert.equal(state.revision.value, 7);
});

test('cancelling executable selection keeps the existing draft', async t => {
    const state = mount(t, { section: 'tools' });
    t.mock.method(http.getClient(), 'request', async () => response({ path: null }));
    state.tools.paths.php = '/current/php';

    await state.pickTool('php');

    assert.equal(state.tools.paths.php, '/current/php');
});

test('runtime probes report the submitted draft paths without saving preferences', async t => {
    const state = mount(t, { section: 'tools' });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return response({ runtimes: { php: { path: '/selected/php', state: 'Current', version: '8.5.3', source: 'global' } } });
    });
    state.tools.paths.php = '/selected/php';

    await state.probeTools();

    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/settings/tools/probe');
    assert.deepEqual(JSON.parse(requests[0].data), { paths: { ...paths, php: '/selected/php' } });
    assert.equal(state.runtimeResults.value.php.version, '8.5.3');
    assert.equal(state.runtimeResults.value.php.path, '/selected/php');
    assert.equal(state.revision.value, 7);
});

test('a probe started before a path edit cannot display its obsolete results', async t => {
    const state = mount(t, { section: 'tools' });
    let finish;
    t.mock.method(http.getClient(), 'request', () => new Promise(resolve => { finish = resolve; }));
    state.tools.paths.php = '/old/php';
    const pending = state.probeTools();
    state.tools.paths.php = '/new/php';
    await vue.nextTick();

    finish(response({ runtimes: { php: { path: '/old/php', state: 'Current', version: '8.4.0' } } }));
    await pending;

    assert.equal(state.tools.paths.php, '/new/php');
    assert.equal(Object.keys(state.runtimeResults.value).length, 0);
});

test('installing an update refuses unsaved sections and an unconfirmed restart', async t => {
    const state = mount(t, { section: 'about' });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => { requests.push(request); return response({ status: 'downloaded' }); });
    state.general.startup_destination = 'last_project';
    await vue.nextTick();

    await state.updateApp('install');

    assert.equal(requests.length, 0);
    assert.equal(state.confirmations.length, 0);
    state.general.reset();
    await vue.nextTick();
    state.browserWindow.confirm = () => false;
    await state.updateApp('install');
    assert.equal(requests.length, 0);
    state.browserWindow.confirm = () => true;
    await state.updateApp('install');
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/settings/updates/install');
    assert.deepEqual(JSON.parse(requests[0].data), { confirmed: true });
});

test('successful saves use normalized server values and preserve edits made after submission', async t => {
    const state = mount(t);
    let complete;
    t.mock.method(http.getClient(), 'request', () => new Promise(resolve => { complete = resolve; }));
    state.board.columns = [{ name: '  Review  ', color: null }];
    const save = state.save('project_defaults');
    await vue.nextTick();
    assert.equal(state.board.processing, true);
    await state.save('project_defaults');
    state.restoreDefaultColumns();
    assert.equal(state.board.columns[0].name, '  Review  ');
    complete(response({ preferences: { revision: 8, values: { ...values, project_defaults: { columns: [{ name: 'Review', color: null }] } } } }));
    await save;
    await vue.nextTick();
    assert.equal(state.board.columns[0].name, 'Review');
    assert.equal(state.board.isDirty, false);

    state.general.startup_destination = 'last_project';
    const pending = state.save('general');
    await vue.nextTick();
    state.general.startup_destination = 'dashboard';
    complete(response({ preferences: { revision: 9, values: { ...values, general: { startup_destination: 'last_project' } } } }));
    await pending;
    await vue.nextTick();
    assert.equal(state.general.startup_destination, 'dashboard');
    assert.equal(state.general.isDirty, true);
});

test('confirmed restore replaces only board defaults and resets their saved baseline', async t => {
    const state = mount(t);
    state.board.columns[0].name = 'Old draft';
    state.tools.paths.php = '/draft/php';
    state.props.preferences = { revision: 9, values: { ...structuredClone(values), project_defaults: { columns: [{ name: 'Restored', color: 'Green' }] } } };
    await vue.nextTick();
    assert.equal(state.board.columns[0].name, 'Old draft', 'Ordinary props keep drafts');

    state.restoredDefaults();
    await vue.nextTick();
    assert.equal(state.board.columns[0].name, 'Restored');
    assert.equal(state.board.isDirty, false);
    assert.equal(state.tools.paths.php, '/draft/php');
    assert.equal(state.tools.isDirty, true);
    state.board.columns[0].name = 'New draft';
    state.discardSection('project_defaults');
    await vue.nextTick();
    assert.equal(state.board.columns[0].name, 'Restored');
});

test('restore staging waits for an explicit board draft decision', async t => {
    const state = mount(t);
    state.board.columns[0].name = 'Draft';
    await vue.nextTick();
    const cancelled = state.prepareRestore();
    assert.equal(state.departureOpen.value, true);
    state.finishDeparture(false);
    assert.equal(await cancelled, false);
    assert.equal(state.board.columns[0].name, 'Draft');
    const approved = state.prepareRestore();
    state.discardDeparture();
    assert.equal(await approved, true);
    await vue.nextTick();
    assert.equal(state.board.isDirty, false);
});

test('discarded settings allow the resumed visit immediately', async t => {
    let before;
    const accepted = [];
    const event = { detail: { visit: { method: 'get', url: new URL('https://orbit.test/') } } };
    t.mock.method(inertia.router, 'on', (name, callback) => { before = callback; return () => {}; });
    t.mock.method(inertia.router, 'visit', url => { if (before(event) !== false) accepted.push(url); });
    const state = mount(t);
    state.general.startup_destination = 'last_project'; await vue.nextTick();
    assert.equal(before(event), false);
    state.discardDeparture();
    assert.deepEqual(accepted, ['https://orbit.test/']);
    assert.equal(state.departureOpen.value, false);
    assert.equal(state.general.isDirty, false);
});

test('recovered settings compare fresh saved values and require review for stale drafts', async t => {
    const memory = { data: { revision: 7, startup_destination: 'last_project' }, baseline: { revision: 7, startup_destination: 'dashboard' } };
    t.mock.method(inertia.router, 'restore', key => key === 'settings:general:draft' ? memory : undefined);
    const state = mount(t);
    state.props.preferences = { revision: 9, values: { ...structuredClone(values), general: { startup_destination: 'last_project' } } };
    await vue.nextTick();
    state.reconcileDrafts();
    assert.match(state.general.errors.revision, /Saved settings changed/);
    assert.equal(state.general.revision, 7);
    assert.equal(state.general.startup_destination, 'last_project');
    state.discardSection('general');
    await vue.nextTick();
    assert.equal(state.general.isDirty, false);
});

test('Settings sections follow server navigation and preserve appearance drafts', async t => {
    const state = mount(t, { section: 'appearance' });
    const visits = [];
    t.mock.method(inertia.router, 'get', (...args) => visits.push(args));
    state.appearance.theme = 'dark';
    await vue.nextTick();
    state.selectSection('tools');
    assert.equal(visits[0][0], '/settings?section=tools');
    assert.equal(visits[0][2].preserveState, true);
    state.props.section = 'tools';
    await vue.nextTick();
    assert.equal(state.appearance.theme, 'dark');
    assert.equal(state.activeSection.value, 'tools');
    state.props.section = 'unsupported';
    await vue.nextTick();
    assert.equal(state.activeSection.value, 'general');
});
