import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

test('workspace navigation and success messages follow browser visits', () => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/WorkspaceLayout.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'workspace-navigation-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const navigation = { canGoBack: false, canGoForward: false };
    const calls = [];
    const toasts = [];
    const page = vue.reactive({ component: 'Dashboard', props: { selectedProject: null, native: true, message: 'Saved' } });
    let mounted;
    let unmounted;
    let navigate;
    let wheel;
    let resetSwipe;
    let listenerRemoved = false;
    class ScrollableElement {
        scrollWidth = 200;
        clientWidth = 100;
        parentElement = null;
    }
    const modules = {
        vue: { ...vue, onMounted: callback => mounted = callback, onBeforeUnmount: callback => unmounted = callback },
        '@inertiajs/vue3': {
            usePage: () => page,
            router: {
                on: (event, callback) => { assert.equal(event, 'navigate'); navigate = callback; return () => calls.push('unsubscribe'); },
                replaceProp: (name, value) => { assert.equal(name, 'message'); page.props.message = value; },
            },
        },
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
        window: {
            navigation,
            history: { length: 1, back: () => calls.push('back'), forward: () => calls.push('forward') },
            addEventListener: (name, listener) => { assert.equal(name, 'wheel'); wheel = listener; },
            removeEventListener: (name, listener) => { listenerRemoved = name === 'wheel' && listener === wheel; },
        },
    };
    runInNewContext(outputText, context);
    const layout = context.exports.default.setup({}, { expose() {} });

    mounted();
    assert.equal(layout.canGoBack.value, false);
    assert.equal(layout.canGoForward.value, false);
    assert.deepEqual(toasts, ['Saved']);
    assert.equal(page.props.message, null);
    page.props.message = 'Saved';
    navigate();
    assert.deepEqual(toasts, ['Saved', 'Saved']);

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
    assert.equal(listenerRemoved, true);
    assert.deepEqual(calls, ['back', 'forward', 'back', 'forward', 'unsubscribe']);
});
