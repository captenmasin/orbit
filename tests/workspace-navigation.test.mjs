import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

function workspace(initialPage = {}, savedRecents = [], deferMessageClear = false) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/WorkspaceLayout.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'workspace-navigation-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const navigation = { canGoBack: false, canGoForward: false };
    const calls = [];
    const visits = [];
    const toasts = [];
    const page = vue.reactive({ component: 'Dashboard', url: '/', ...initialPage, props: { sidebarProjects: [], selectedProject: null, native: true, message: 'Saved', ...initialPage.props } });
    const storage = vue.ref(savedRecents);
    let mounted;
    let unmounted;
    let navigate;
    let wheel;
    let keydown;
    let message;
    let resetSwipe;
    let listenerRemoved = false;
    let shortcutRemoved = false;
    let menuListenerRemoved = false;
    let pendingMessageClear;
    class ScrollableElement {
        scrollWidth = 200;
        clientWidth = 100;
        parentElement = null;
        isContentEditable = false;
        selectors = [];
        closest(selector) {
            return selector.split(',').some(candidate => this.selectors.includes(candidate.trim())) ? this : this.parentElement?.closest(selector) ?? null;
        }
    }
    const modules = {
        vue: { ...vue, onMounted: callback => mounted = callback, onBeforeUnmount: callback => unmounted = callback },
        '@inertiajs/vue3': {
            usePage: () => page,
            router: {
                visit: url => visits.push(url),
                on: (event, callback) => { assert.equal(event, 'navigate'); navigate = callback; return () => calls.push('unsubscribe'); },
                replaceProp: (name, value) => {
                    assert.equal(name, 'message');
                    if (deferMessageClear) pendingMessageClear = () => { page.props.message = value; };
                    else page.props.message = value;
                },
            },
        },
        '@vueuse/core': { useLocalStorage: (key, fallback, options) => {
            assert.equal(key, 'orbit:recent-items');
            assert.deepEqual(Array.from(fallback), []);
            assert.equal(options.flush, 'sync');
            return storage;
        } },
        'vue-sonner': { toast: { success: message => toasts.push(message) } },
    };
    const context = {
        exports: {},
        require: name => modules[name] ?? {},
        navigator: { platform: 'MacIntel' },
        HTMLElement: ScrollableElement,
        document: { body: {} },
        getComputedStyle: () => ({ overflowX: 'auto' }),
        setTimeout: callback => { resetSwipe = callback; return 1; },
        clearTimeout() {},
        URL,
        window: {
            location: { origin: 'http://localhost:8000' },
            navigation,
            history: { length: 1, back: () => calls.push('back'), forward: () => calls.push('forward') },
            addEventListener: (name, listener) => { if (name === 'message') message = listener; else if (name === 'wheel') wheel = listener; else { assert.equal(name, 'keydown'); keydown = listener; } },
            removeEventListener: (name, listener) => { if (name === 'message') menuListenerRemoved = listener === message; else if (name === 'wheel') listenerRemoved = listener === wheel; else { assert.equal(name, 'keydown'); shortcutRemoved = listener === keydown; } },
        },
    };
    runInNewContext(outputText, context);
    const layout = context.exports.default.setup({}, { expose() {} });
    return {
        layout, page, navigation, calls, visits, toasts, storage, ScrollableElement,
        mounted: () => mounted(), unmounted: () => unmounted(), navigate: () => navigate(),
        wheel: event => wheel(event), keydown: event => keydown(event), resetSwipe: () => resetSwipe(),
        listenerRemoved: () => listenerRemoved, shortcutRemoved: () => shortcutRemoved,
        menu: (id, overrides = {}) => message({ source: context.window, origin: context.window.location.origin, data: { type: 'native-event', event: 'Native\\Desktop\\Events\\Menu\\MenuItemClicked', payload: { item: { id } } }, ...overrides }),
        menuRegistered: () => Boolean(message), menuListenerRemoved: () => menuListenerRemoved,
        flushMessageClear: () => pendingMessageClear(),
    };
}

test('native workspace menu opens existing pages and search without resetting the current form', () => {
    const { layout, page, visits, mounted, unmounted, menu, menuListenerRemoved } = workspace();
    mounted();
    for (const [id, destination] of [
        ['new-project', '/projects/create'], ['settings', '/settings'], ['backups', '/settings/backups'],
        ['connections', '/settings/connections'], ['tools', '/settings?section=tools'], ['about', '/settings?section=about'],
    ]) {
        layout.searchOpen.value = true;
        menu(id);
        assert.equal(layout.searchOpen.value, false);
        assert.equal(visits.at(-1), destination);
    }
    page.component = 'ShowProject';
    page.url = '/projects/orbit';
    menu('dashboard');
    assert.equal(visits.at(-1), '/');
    const visitCount = visits.length;
    menu('search');
    menu('search');
    assert.equal(layout.searchOpen.value, true);
    page.component = 'CreateProject';
    menu('new-project');
    page.url = '/settings?section=tools';
    menu('tools');
    assert.equal(visits.length, visitCount);
    unmounted();
    assert.equal(menuListenerRemoved(), true);
});

test('native menu ignores unrelated, foreign, and unknown messages and stays disabled in browsers', () => {
    const { layout, visits, mounted, menu } = workspace();
    mounted();
    for (const overrides of [
        { source: {} }, { origin: 'https://example.com' }, { data: null }, { data: { type: 'other' } },
        { data: { type: 'native-event', event: 'OtherEvent' } },
        { data: { type: 'native-event', event: 'Native\\Desktop\\Events\\Menu\\MenuItemClicked' } },
    ]) menu('search', overrides);
    for (const id of ['unknown', '__proto__', 'constructor', '/settings', 'https://example.com', null, {}]) menu(id);
    assert.equal(layout.searchOpen.value, false);
    assert.deepEqual(visits, []);
    const browser = workspace({ props: { native: false } });
    browser.mounted();
    assert.equal(browser.menuRegistered(), false);
});

test('workspace navigation and success messages follow browser visits', () => {
    const { layout, page, navigation, calls, toasts, mounted, unmounted, navigate, wheel, keydown, resetSwipe, ScrollableElement, listenerRemoved, shortcutRemoved } = workspace();

    mounted();
    assert.equal(layout.canGoBack.value, false);
    assert.equal(layout.canGoForward.value, false);
    assert.deepEqual(toasts, ['Saved']);
    assert.equal(page.props.message, null);
    page.props.message = 'Saved';
    navigate();
    assert.deepEqual(toasts, ['Saved', 'Saved']);

    const shortcut = (overrides = {}) => {
        const event = { key: 'k', metaKey: true, ctrlKey: false, altKey: false, shiftKey: false, isComposing: false, preventDefault() { this.prevented = true; }, ...overrides };
        keydown(event);
        return event;
    };
    assert.equal(shortcut().prevented, true);
    assert.equal(layout.searchOpen.value, true);
    assert.equal(shortcut({ key: 'K', metaKey: false, ctrlKey: true }).prevented, true);
    assert.equal(layout.searchOpen.value, false);
    for (const overrides of [{ isComposing: true }, { altKey: true }, { shiftKey: true }, { metaKey: false }, { key: 'b' }]) {
        assert.equal(shortcut(overrides).prevented, undefined);
        assert.equal(layout.searchOpen.value, false);
    }

    navigation.canGoBack = true;
    navigation.canGoForward = true;
    navigate();
    assert.equal(layout.canGoBack.value, true);
    assert.equal(layout.canGoForward.value, true);
    layout.goBack();
    layout.goForward();
    const swipe = (deltaX, overrides = {}) => {
        const event = { deltaMode: 0, deltaX, deltaY: 0, target: null, preventDefault() { this.prevented = true; }, ...overrides };
        wheel(event);
        return event;
    };
    swipe(-45);
    swipe(-50);
    swipe(-100);
    assert.deepEqual(calls, ['back', 'forward', 'back']);
    resetSwipe();
    swipe(100);
    assert.deepEqual(calls, ['back', 'forward', 'back', 'forward']);
    resetSwipe();
    assert.equal(swipe(-100, { target: new ScrollableElement() }).prevented, undefined);
    assert.equal(swipe(-100, { deltaY: 200 }).prevented, undefined);
    unmounted();
    assert.equal(listenerRemoved(), true);
    assert.equal(shortcutRemoved(), true);
    assert.deepEqual(calls, ['back', 'forward', 'back', 'forward', 'unsubscribe']);
});

test('new project shortcut opens the creation page across the workspace without resetting an existing form', () => {
    const { layout, page, mounted, keydown, visits, ScrollableElement } = workspace();
    mounted();
    const shortcut = (overrides = {}) => {
        const event = { key: 'n', metaKey: true, preventDefault() { this.prevented = true; }, ...overrides };
        keydown(event);
        return event;
    };

    for (const component of ['Dashboard', 'ShowProject', 'Settings']) {
        page.component = component;
        layout.searchOpen.value = true;
        assert.equal(shortcut().prevented, true);
        assert.equal(layout.searchOpen.value, false);
    }
    const input = new ScrollableElement();
    input.selectors = ['input'];
    assert.equal(shortcut({ key: 'N', metaKey: false, ctrlKey: true, target: input }).prevented, true);
    assert.deepEqual(visits, Array(4).fill('/projects/create'));

    for (const overrides of [{ metaKey: false }, { altKey: true }, { shiftKey: true }, { isComposing: true }, { defaultPrevented: true }, { repeat: true }, { key: 'x' }]) {
        assert.equal(shortcut(overrides).prevented, undefined);
    }
    page.component = 'CreateProject';
    assert.equal(shortcut().prevented, true);
    assert.equal(visits.length, 4);
});

test('number shortcuts open projects in the current dashboard order', () => {
    const projects = { data: [{ id: 'zebra' }, { id: 'alpha' }, { id: 'three' }, { id: 'four' }, { id: 'five' }, { id: 'six' }, { id: 'seven' }, { id: 'eight' }, { id: 'nine' }] };
    const { page, mounted, keydown, visits } = workspace({ props: { projects, sidebarProjects: [{ id: 'other', name: 'Other' }] } });
    mounted();
    const shortcut = key => {
        const event = { key, metaKey: true, preventDefault() { this.prevented = true; } };
        keydown(event);
        return event;
    };

    for (const key of ['1', '2', '9']) assert.equal(shortcut(key).prevented, true);
    assert.deepEqual(visits, ['/projects/zebra', '/projects/alpha', '/projects/nine']);

    page.props.projects = { data: [{ id: 'filtered' }, { id: 'zebra' }] };
    shortcut('1');
    shortcut('2');
    assert.deepEqual(visits.slice(-2), ['/projects/filtered', '/projects/zebra']);
    assert.equal(shortcut('3').prevented, undefined);
    assert.equal(visits.length, 5);
});

test('number shortcuts leave editing, dialogs, unavailable projects, and other pages alone', () => {
    const { layout, page, mounted, keydown, visits, ScrollableElement } = workspace({ props: { projects: { data: [{ id: 'orbit' }] } } });
    mounted();
    const shortcut = (overrides = {}) => {
        const event = { key: '1', metaKey: true, preventDefault() { this.prevented = true; }, ...overrides };
        keydown(event);
        assert.equal(event.prevented, undefined);
    };

    for (const overrides of [{ key: '0' }, { key: '10' }, { key: '2' }, { metaKey: false }, { altKey: true }, { shiftKey: true }, { isComposing: true }, { defaultPrevented: true }, { repeat: true }]) shortcut(overrides);
    for (const selector of ['input', 'textarea', 'select', '[role="dialog"]', '[role="menu"]']) {
        const parent = new ScrollableElement();
        parent.selectors = [selector];
        const target = new ScrollableElement();
        target.parentElement = parent;
        shortcut({ target });
    }
    const editor = new ScrollableElement();
    editor.isContentEditable = true;
    shortcut({ target: editor });
    layout.searchOpen.value = true;
    shortcut();
    layout.searchOpen.value = false;
    page.component = 'ShowProject';
    shortcut();
    page.component = 'Dashboard';
    page.props.projects = undefined;
    shortcut();
    page.props.projects = { data: [] };
    shortcut();

    assert.deepEqual(visits, []);
});

test('success messages appear once before asynchronous clearing and for each later save on the same page', () => {
    const { page, toasts, mounted, navigate, flushMessageClear } = workspace({}, [], true);

    mounted();
    navigate();
    navigate();
    assert.deepEqual(toasts, ['Saved']);
    assert.equal(page.props.message, 'Saved');
    flushMessageClear();
    assert.equal(page.props.message, null);

    page.props.message = 'Saved';
    assert.deepEqual(toasts, ['Saved', 'Saved']);
    navigate();
    assert.deepEqual(toasts, ['Saved', 'Saved']);
    flushMessageClear();
});

test('recent destinations follow project visits and deep links outside search', () => {
    const selectedProject = {
        id: 'orbit', name: 'Orbit', documents: [{ id: 'guide', title: 'Guide' }],
        board_columns: [{ tasks: [{ id: 'fix', title: 'Fix typing' }] }],
        links: [{ id: 'docs', label: 'Docs', url: 'https://example.com' }],
        secrets: [{ id: 'key', name: 'API key', value: 'never-save-this' }],
    };
    const { layout, page, mounted, navigate } = workspace({ component: 'ShowProject', url: '/projects/orbit', props: { selectedProject, sidebarProjects: [{ id: 'orbit', name: 'Orbit' }] } });

    mounted();
    assert.equal(layout.recentItems.value[0].title, 'Orbit');
    assert.equal(layout.recentItems.value[0].url, '/projects/orbit?tab=overview');
    for (const [url, title] of [
        ['/projects/orbit?tab=documents&document=guide', 'Guide'],
        ['/projects/orbit?tab=board&task=fix', 'Fix typing'],
        ['/projects/orbit?tab=overview&link=docs', 'Docs'],
        ['/projects/orbit?tab=secrets&secret=key', 'API key'],
    ]) {
        page.url = url;
        navigate();
        assert.equal(layout.recentItems.value[0].title, title);
        assert.equal(layout.recentItems.value[0].url, url);
    }
    page.url = '/projects/orbit?tab=documents&document=deleted';
    navigate();
    assert.equal(layout.recentItems.value[0].type, 'project');
    assert.equal(layout.recentItems.value.length, 5);
    assert.equal(JSON.stringify(layout.storedRecentItems.value).includes('never-save-this'), false);
});

test('search records at most eight unique destinations without persisting content', () => {
    const { layout, storage } = workspace({ props: { sidebarProjects: [{ id: 'orbit', name: 'Orbit' }] } });
    const result = id => ({ id, project_id: 'orbit', project: 'Orbit', type: 'document', title: `Guide ${id}`, excerpt: 'private content', value: 'private secret', url: `/projects/orbit?tab=documents&document=${id}` });

    for (let id = 1; id <= 10; id++) layout.navigateFromSearch(result(String(id)));
    layout.searchOpen.value = true;
    layout.navigateFromSearch(result('5'));

    assert.deepEqual(Array.from(layout.recentItems.value, item => item.id), ['5', '10', '9', '8', '7', '6', '4', '3']);
    assert.equal(layout.searchOpen.value, false);
    assert.deepEqual(Object.keys(storage.value[0]).sort(), ['excerpt', 'id', 'project', 'project_id', 'title', 'type', 'url']);
    assert.equal(storage.value.every(item => item.excerpt === ''), true);
    assert.equal(JSON.stringify(storage.value).includes('private'), false);
    layout.clearRecentItems();
    assert.equal(layout.recentItems.value.length, 0);
    assert.equal(storage.value.length, 0);
});

test('recent destinations reject unsafe stored URLs and refresh renamed or deleted records', () => {
    const selectedProject = { id: 'orbit', name: 'Orbit renamed', documents: [{ id: 'guide', title: 'Guide renamed' }], links: [] };
    const result = (id, type = 'document') => ({ id, project_id: 'orbit', project: 'Old name', type, title: 'Old title', excerpt: 'old content', url: `/projects/orbit?tab=${type === 'project' ? 'overview' : 'documents'}${type === 'project' ? '' : `&document=${id}`}` });
    const { layout, page, storage } = workspace({ props: { selectedProject, sidebarProjects: [{ id: 'orbit', name: 'Orbit renamed' }] } }, [
        result('guide'), result('orbit', 'project'), result('deleted'), null, { ...result('guide'), url: 'https://example.com' },
        { ...result('guide'), url: '//example.com/projects/orbit' }, { ...result('guide'), url: '/projects/orbit\\example.com' },
        { ...result('guide'), project_id: 'gone', url: '/projects/gone?tab=documents&document=guide' },
        { ...result('guide'), type: { toString: null } },
    ]);

    assert.deepEqual(Array.from(layout.recentItems.value, item => item.title), ['Guide renamed', 'Orbit renamed']);
    assert.equal(layout.recentItems.value.every(item => item.project === 'Orbit renamed' && item.excerpt === ''), true);
    layout.rememberRecent({ ...result('guide'), url: 'javascript:alert(1)' });
    assert.equal(layout.recentItems.value.length, 2);
    page.props.selectedProject.documents = [];
    assert.deepEqual(Array.from(layout.recentItems.value, item => item.type), ['project']);
    page.props.sidebarProjects = [];
    assert.equal(layout.recentItems.value.length, 0);
    storage.value = { corrupt: true };
    assert.equal(layout.recentItems.value.length, 0);
});
