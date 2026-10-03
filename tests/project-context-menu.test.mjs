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
    assert.deepEqual({ ...requests[0].data }, { name: 'Beta', description: 'Keep this description', status: 'Archived', revision: 7, return_back: true });
    requests[0].callbacks.onError({ revision: 'This project changed. Reload it before saving again.' });
    requests[0].callbacks.onFinish();
    assert.deepEqual(toasts, ['This project changed. Reload it before saving again.']);

    menu.changeStatus('Live');
    assert.equal(requests.length, 2);
    assert.deepEqual({ ...requests[1].data }, { name: 'Beta', description: 'Keep this description', status: 'Live', revision: 7, return_back: true });
    requests[1].callbacks.onSuccess({});
    requests[1].callbacks.onFinish();
    props.project = { ...project, status: 'Archived', revision: 8 };
    menu.changeStatus('Live');
    assert.equal(requests.length, 3);
    assert.deepEqual({ ...requests[2].data }, { name: 'Beta', description: 'Keep this description', status: 'Live', revision: 8, return_back: true });
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

test('project cards and sidebar items offer every status and hide archive for archived projects', async t => {
    const passthrough = (_, { slots }) => slots.default?.();
    const controls = new Proxy({ default: passthrough }, { get: (target, name) => target[name] ?? passthrough });
    const dependencies = { exports: {} };
    runInNewContext(ts.transpileModule(readFileSync(new URL('../resources/js/lib/dependencies.ts', import.meta.url), 'utf8'), {
        compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
    }).outputText, dependencies);
    const modules = {
        vue, '@inertiajs/vue3': { ...inertia, usePage: () => ({ props: { statuses: ['Idea', 'In Progress', 'Live', 'Paused', 'Archived'] } }) },
        '@vueuse/core': { ...vueuse, useLocalStorage: (_, value) => vue.ref(value) },
        '@/components/ui/sidebar': new Proxy({ useSidebar: () => ({ setOpenMobile() {} }) }, { get: (target, name) => target[name] ?? passthrough }),
        '@/components/ui/dialog': new Proxy({ Dialog: (_, { slots, attrs }) => attrs.open ? slots.default?.() : null }, { get: (target, name) => target[name] ?? passthrough }),
        'reka-ui': new Proxy({ DropdownMenuContent: (_, { slots }) => vue.h('section', { 'data-test-menu': 'dropdown' }, slots.default?.()) }, { get: (target, name) => target[name] ?? passthrough }),
        '@/components/ui/context-menu': new Proxy({ ContextMenuContent: (_, { slots }) => vue.h('section', { 'data-test-menu': 'context' }, slots.default?.()), ContextMenuItem: (_, { slots, attrs }) => vue.h('button', { disabled: attrs.disabled }, slots.default?.()) }, { get: (target, name) => target[name] ?? passthrough }),
        '@/lib/appearance': { reducedMotion: vue.ref(false) },
        '@/lib/project': { projectStatusDotClasses: { Paused: '', Archived: '' } },
        '@/lib/dependencies': dependencies.exports,
    };
    for (const component of ['MarkdownContent', 'ProjectContextMenu', 'ProjectCard', 'ProjectHeader', 'WorkspaceSidebar']) {
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

    for (const description of [null, '', 'Project CRM']) {
        const project = { id: 'beta', name: 'Beta', description, description_html: description ? '<p>Project CRM</p>' : '', status: 'Paused', revision: 7, tags: [] };
        const html = await renderToString(vue.createSSRApp(modules['@/components/ProjectCard.vue'].default, { project, selectedTags: [] }));

        if (description) {
            assert.match(html, /Project CRM/);
            assert.doesNotMatch(html, /Add a description…/);
        } else {
            assert.match(html, /<a\b[^>]*href="\/projects\/beta\/edit\?tab=overview"[^>]*>\s*Add a description…\s*<\/a>/);
        }
    }

    await t.test('project cards and headers render formatted Markdown descriptions', async () => {
        const project = { id: 'beta', name: 'Beta', description: '**Project CRM**', description_html: '<p><strong>Project CRM</strong></p>', status: 'Paused', revision: 7, tags: [] };
        for (const component of ['ProjectCard', 'ProjectHeader']) {
            const html = await renderToString(vue.createSSRApp(modules[`@/components/${component}.vue`].default, {
                project, selectedTags: [], statuses: ['Paused'], statusDisabled: false, statusError: '', statusNeedsReload: false,
            }));

            assert.match(html, /<p><strong>Project CRM<\/strong><\/p>/);
            assert.doesNotMatch(html, /\*\*Project CRM\*\*/);
        }
    });

    await t.test('project cards link outstanding tasks and merged dependency issues to their tabs', async () => {
        const root = {
            id: 'root', relative_path: '.', scan_state: 'Current', scan_error: null,
            snapshot: { fingerprint: 'current', unsupported_lockfiles: [], files: {
                'package.json': { state: 'Current', entries: [{ name: 'vue', scope: 'Production' }] },
                'package-lock.json': { state: 'Current', entries: [{ name: 'vue', version: '3.5.20', location: 'node_modules/vue', scope: 'Production' }] },
            } },
            outdated: { fingerprint: 'current', checked: 1, unavailable: 0, skipped: 0, packages: [{ name: 'vue', ecosystem: 'npm', current: '3.5.20', latest: '3.5.30' }] },
            security: { fingerprint: 'current', checked: 1, unavailable: 0, skipped: 0, packages: [{ name: 'vue', ecosystem: 'npm', current: '3.5.20', advisories: [{ severity: 'High' }] }] },
        };
        const project = {
            id: 'beta', name: 'Beta', status: 'Paused', tags: [],
            board_columns: [
                { name: 'Backlog', tasks_count: 2 }, { name: 'To Do', tasks_count: 3 },
                { name: 'In Progress', tasks_count: 4 }, { name: ' DONE ', tasks_count: 5 },
            ],
            folders: [{ id: 'folder', path: '/beta', availability: 'Available', package_roots: [root] }],
        };
        const render = async () => (await renderToString(vue.createSSRApp(modules['@/components/ProjectCard.vue'].default, { project, selectedTags: [] }))).replace(/<!--.*?-->/gs, '');

        const html = await render();

        assert.match(html, /<a\b[^>]*href="\/projects\/beta\?tab=board"[^>]*>.*?9 to do\s*<\/a>/s);
        assert.match(html, /<a\b[^>]*href="\/projects\/beta\?tab=dependencies"[^>]*>.*?1 dependency issue\s*<\/a>/s);
        assert.doesNotMatch(html, /Last commit|No commit data|\d+ repos|\d+ folders/);

        root.outdated.packages = [];
        root.security.packages = [];
        assert.match(await render(), /0 dependency issues/);

        root.snapshot.fingerprint = 'changed';
        const staleHtml = await render();
        assert.doesNotMatch(staleHtml, /href="\/projects\/beta\?tab=dependencies"|Dependencies unchecked/);

        project.board_columns = [];
        project.folders = [];
        const emptyHtml = await render();
        assert.match(emptyHtml, /0 to do/);
        assert.doesNotMatch(emptyHtml, /href="\/projects\/beta\?tab=dependencies"|Dependencies unchecked/);
    });
});
