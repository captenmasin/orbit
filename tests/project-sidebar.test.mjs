import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import * as vueuse from '@vueuse/core';
import ts from 'typescript';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';

test('sidebar project drags follow changes to the reduced motion preference', async () => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/WorkspaceSidebar.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'sidebar-motion-test', inlineTemplate: true });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const reducedMotion = vue.ref(false);
    const animations = [];
    const passthrough = (_, { slots }) => slots.default?.();
    const controls = new Proxy({ default: passthrough }, { get: (target, name) => target[name] ?? passthrough });
    const modules = {
        vue, '@inertiajs/vue3': inertia, '@/lib/appearance': { reducedMotion },
        '@vueuse/core': { useLocalStorage: (_, value) => vue.ref(value) },
        '@/components/ui/sidebar': new Proxy({ useSidebar: () => ({ setOpenMobile() {} }) }, { get: (target, name) => target[name] ?? passthrough }),
        'vue-draggable-plus': { VueDraggable: vue.defineComponent({
            inheritAttrs: false,
            props: ['animation'],
            setup: (props, { slots }) => () => { animations.push(props.animation); return slots.default?.(); },
        }) },
    };
    const context = { exports: {}, require: name => modules[name] ?? controls };
    runInNewContext(outputText, context);
    const props = { projects: [{ id: 'alpha', name: 'Alpha', status: 'Idea' }], selectedProject: null, page: 'Dashboard' };

    for (const [reduced, expected] of [[false, [150]], [true, [0]], [false, [150]]]) {
        reducedMotion.value = reduced;
        animations.length = 0;
        await renderToString(vue.createSSRApp(context.exports.default, props));
        assert.deepEqual(animations, expected);
    }
});

test('sidebar status groups save project order and open state', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/WorkspaceSidebar.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'sidebar-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const toasts = [];
    const stored = new Map();
    const storage = { getItem: key => stored.get(key) ?? null, setItem: (key, value) => stored.set(key, value), removeItem: key => stored.delete(key) };
    const modules = { vue, '@inertiajs/vue3': inertia, '@vueuse/core': { useLocalStorage: (key, value, options) => vueuse.useStorage(key, value, storage, options) }, 'vue-sonner': { toast: { error: message => toasts.push(message) } }, '@/components/ui/sidebar': { useSidebar: () => ({ setOpenMobile() {} }) } };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const requests = [];
    t.mock.method(inertia.router, 'put', (url, data, callbacks) => {
        callbacks.onStart();
        requests.push({ url, data, callbacks });
    });
    const props = vue.reactive({ projects: [{ id: 'alpha', name: 'Alpha', status: 'Idea' }, { id: 'live', name: 'Live site', status: 'Live' }, { id: 'beta', name: 'Beta', status: 'Idea' }, { id: 'gamma', name: 'Gamma', status: 'Live' }], selectedProject: null, page: 'Dashboard' });
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const sidebar = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    assert.deepEqual(Array.from(sidebar.groups.value, group => group.status), ['Idea', 'Live']);
    assert.deepEqual(Array.from(sidebar.groups.value[0].projects, project => project.id), ['alpha', 'beta']);
    sidebar.projectQuery.value = '  beta ';
    assert.deepEqual(Array.from(sidebar.groups.value[0].projects, project => project.id), ['beta']);
    sidebar.reorderGroup('Idea', []);
    assert.deepEqual(Array.from(sidebar.ordered.value, project => project.id), ['alpha', 'live', 'beta', 'gamma']);
    sidebar.projectQuery.value = '';
    sidebar.saveOrder();
    assert.equal(requests.length, 0);
    sidebar.reorderGroup('Idea', [...sidebar.groups.value[0].projects].reverse());
    sidebar.saveOrder();
    sidebar.saveOrder();
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/projects/order');
    assert.deepEqual(Array.from(requests[0].data.ids), ['beta', 'live', 'alpha', 'gamma']);
    assert.equal(props.projects[0].id, 'alpha');
    requests[0].callbacks.onError({ ids: 'The project list changed.' });
    requests[0].callbacks.onFinish();
    assert.equal(sidebar.ordered.value[0].id, 'alpha');
    assert.deepEqual(toasts, ['Could not save project order. Reload and try again.']);
    sidebar.move('Idea', 0, 1);
    assert.deepEqual(Array.from(requests[1].data.ids), ['beta', 'live', 'alpha', 'gamma']);
    requests[1].callbacks.onCancel();
    requests[1].callbacks.onFinish();
    assert.equal(sidebar.ordered.value[0].id, 'alpha');
    sidebar.move('Idea', 1, 1);
    assert.equal(requests.length, 2);
    sidebar.move('Live', 0, 1);
    assert.deepEqual(Array.from(requests[2].data.ids), ['alpha', 'gamma', 'beta', 'live']);
    props.projects = [props.projects[0], props.projects[3], props.projects[2], props.projects[1]];
    await vue.nextTick();
    await requests[2].callbacks.onSuccess({});
    requests[2].callbacks.onFinish();
    assert.deepEqual(Array.from(sidebar.groups.value[1].projects, project => project.id), ['gamma', 'live']);
    assert.equal(sidebar.announcement.value, 'Project order saved.');

    sidebar.setGroupOpen('Idea', { currentTarget: { open: false } });
    assert.deepEqual(JSON.parse(stored.get('orbit:sidebar-groups')), { Idea: false });
    const reloaded = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    assert.equal(reloaded.groupOpen.value.Idea, false);
    assert.equal(reloaded.groupOpen.value.Live, undefined);

    props.statuses = ['Live', 'Unused', 'Idea', 'Archived'];
    await vue.nextTick();
    assert.deepEqual(Array.from(sidebar.groups.value, group => group.status), ['Live', 'Idea']);
    assert.deepEqual(Array.from(sidebar.groups.value[0].projects, project => project.id), ['gamma', 'live']);
});

test('shared project duplicate requires confirmation and prevents repeat requests', t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectContextMenu.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'project-actions' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const requests = [];
    const modules = { vue, '@inertiajs/vue3': { ...inertia, usePage: () => ({ props: { statuses: ['Idea'] } }), router: { post: (...args) => requests.push(args) } } };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope(); t.after(() => scope.stop());
    const props = vue.reactive({ project: { id: 'one', name: 'One', revision: 1, status: 'Idea' }, disabled: false });
    const state = scope.run(() => context.exports.default.setup(props, { expose() {} }));
    state.duplicateProject(); assert.equal(requests.length, 0);
    state.duplicating.value = true; state.duplicating.value = false; state.duplicateProject(); assert.equal(requests.length, 0);
    state.duplicating.value = true; props.disabled = true; state.duplicateProject(); assert.equal(requests.length, 0);
    props.disabled = false; state.duplicateProject(); state.duplicateProject(); assert.equal(requests.length, 1);
    assert.equal(requests[0][0], '/projects/one/duplicate');
    requests[0][2].onSuccess(); requests[0][2].onFinish(); assert.equal(state.duplicating.value, false); assert.equal(state.duplicateBusy.value, false);
});
