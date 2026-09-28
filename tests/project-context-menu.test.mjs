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

test('project menus change status, archive, and confirm deletion using the chosen project revision', t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectContextMenu.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'project-actions-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const toasts = [];
    const modules = { vue, '@vueuse/core': vueuse, '@inertiajs/vue3': { ...inertia, usePage: () => ({ props: { statuses: ['Idea', 'In Progress', 'Live', 'Paused', 'Archived'] } }) }, 'vue-sonner': { toast: { error: message => toasts.push(message) } } };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const requests = [];
    for (const method of ['put', 'delete']) {
        t.mock.method(inertia.router, method, (url, data, callbacks) => {
            if (method === 'delete') {
                callbacks = data;
                data = callbacks.data;
            }
            callbacks.onStart();
            requests.push({ method, url, data, callbacks });
        });
    }
    const project = { id: 'beta', name: 'Beta', description: 'Keep this description', status: 'Paused', revision: 7 };
    const props = vue.reactive({ project, disabled: false });
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const menu = scope.run(() => context.exports.default.setup(props, { expose() {} }));

    menu.changeStatus('Paused');
    menu.removeProject();
    assert.equal(requests.length, 0);
    props.disabled = true;
    menu.changeStatus('Live');
    menu.removing.value = project;
    menu.removeProject();
    assert.equal(requests.length, 0);
    props.disabled = false;
    menu.removing.value = null;
    menu.changeStatus('Archived');
    menu.changeStatus('Live');
    assert.equal(requests.length, 1);
    assert.equal(requests[0].url, '/projects/beta');
    assert.deepEqual({ ...requests[0].data }, { name: 'Beta', description: 'Keep this description', status: 'Archived', revision: 7 });
    requests[0].callbacks.onError({ revision: 'This project changed. Reload it before saving again.' });
    requests[0].callbacks.onFinish();
    assert.deepEqual(toasts, ['This project changed. Reload it before saving again.']);

    menu.changeStatus('Live');
    assert.equal(requests.length, 2);
    assert.deepEqual({ ...requests[1].data }, { name: 'Beta', description: 'Keep this description', status: 'Live', revision: 7 });
    requests[1].callbacks.onSuccess({});
    requests[1].callbacks.onFinish();
    props.project = { ...project, status: 'Archived', revision: 8 };
    menu.changeStatus('Live');
    assert.equal(requests.length, 3);
    assert.deepEqual({ ...requests[2].data }, { name: 'Beta', description: 'Keep this description', status: 'Live', revision: 8 });
    requests[2].callbacks.onSuccess({});
    requests[2].callbacks.onFinish();

    menu.removing.value = project;
    assert.equal(requests.length, 3);
    menu.removeProject();
    menu.removeProject();
    assert.equal(requests.length, 4);
    assert.equal(requests[3].method, 'delete');
    assert.equal(requests[3].url, '/projects/beta');
    assert.deepEqual({ ...requests[3].data }, { revision: 7 });
    requests[3].callbacks.onError({ revision: 'This project changed. Reload it before removing it.' });
    requests[3].callbacks.onFinish();
    assert.equal(menu.removing.value.id, 'beta');
    assert.equal(toasts[1], 'This project changed. Reload it before removing it.');
    menu.removeProject();
    requests[4].callbacks.onSuccess({});
    requests[4].callbacks.onFinish();
    assert.equal(menu.removing.value, null);
});

test('project cards and sidebar items offer every status and hide archive for archived projects', async () => {
    const passthrough = (_, { slots }) => slots.default?.();
    const controls = new Proxy({ default: passthrough }, { get: (target, name) => target[name] ?? passthrough });
    const modules = {
        vue, '@inertiajs/vue3': { ...inertia, usePage: () => ({ props: { statuses: ['Idea', 'In Progress', 'Live', 'Paused', 'Archived'] } }) },
        '@vueuse/core': { ...vueuse, useLocalStorage: (_, value) => vue.ref(value) },
        '@/components/ui/sidebar': new Proxy({ useSidebar: () => ({ setOpenMobile() {} }) }, { get: (target, name) => target[name] ?? passthrough }),
        '@/components/ui/dialog': new Proxy({ Dialog: (_, { slots, attrs }) => attrs.open ? slots.default?.() : null }, { get: (target, name) => target[name] ?? passthrough }),
        'reka-ui': new Proxy({ DropdownMenuContent: (_, { slots }) => vue.h('section', { 'data-test-menu': 'dropdown' }, slots.default?.()) }, { get: (target, name) => target[name] ?? passthrough }),
        '@/components/ui/context-menu': new Proxy({ ContextMenuContent: (_, { slots }) => vue.h('section', { 'data-test-menu': 'context' }, slots.default?.()), ContextMenuItem: (_, { slots, attrs }) => vue.h('button', { disabled: attrs.disabled }, slots.default?.()) }, { get: (target, name) => target[name] ?? passthrough }),
        '@/lib/appearance': { reducedMotion: vue.ref(false) },
        '@/lib/project': { projectStatusDotClasses: { Paused: '', Archived: '' } },
    };
    for (const component of ['ProjectContextMenu', 'ProjectCard', 'WorkspaceSidebar']) {
        const { descriptor } = parse(readFileSync(new URL(`../resources/js/components/${component}.vue`, import.meta.url), 'utf8'));
        const script = compileScript(descriptor, { id: `${component}-render-test`, inlineTemplate: true });
        const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
        const context = { exports: {}, require: name => modules[name] ?? controls };
        runInNewContext(outputText, context);
        modules[`@/components/${component}.vue`] = context.exports;
    }

    for (const component of ['ProjectCard', 'WorkspaceSidebar']) {
        for (const status of ['Paused', 'Archived']) {
            const project = { id: 'beta', name: 'Beta', description: null, status, revision: 7, tags: [] };
            const props = component === 'ProjectCard' ? { project, selectedTags: [] } : { projects: [project], selectedProject: null, page: 'Dashboard' };
            const html = await renderToString(vue.createSSRApp(modules[`@/components/${component}.vue`].default, props));

            const menus = Array.from(html.matchAll(/<section data-test-menu="(?:context|dropdown)"[^>]*>(.*?)<\/section>/gs), ([, content]) => content);
            assert.equal(menus.length, component === 'ProjectCard' ? 2 : 1);
            for (const menuHtml of menus) {
                const links = Array.from(menuHtml.matchAll(/<a\b[^>]*href="([^"]*)"[^>]*>(.*?)<\/a>/gs), ([, href, content]) => [href, content.replace(/<[^>]*>/g, '').trim()]);
                assert.ok(links.some(([href, label]) => href === '/projects/beta' && label === 'Open project'));
                assert.ok(links.some(([href, label]) => href === '/projects/beta/edit' && label === 'Edit project'));
                assert.match(menuHtml, /Duplicate project/);
                assert.match(menuHtml, /Delete project/);
                assert.match(menuHtml, /Change status/);
                const buttons = new Map(Array.from(menuHtml.matchAll(/<button( disabled)?>(.*?)<\/button>/gs), ([, disabled, content]) => [content.replace(/<[^>]*>/g, '').trim(), !!disabled]));
                for (const option of ['Idea', 'In Progress', 'Live', 'Paused', 'Archived']) {
                    assert.equal(buttons.get(option), option === status);
                }
                assert.equal(menuHtml.includes('Mark archived'), status !== 'Archived');
            }
            assert.match(html, /Beta/);
        }
    }
});
