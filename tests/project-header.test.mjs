import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { compileScript, compileTemplate, parse, registerTS } from '@vue/compiler-sfc';
import * as vueuse from '@vueuse/core';
import ts from 'typescript';
import * as vue from 'vue';

function script(source, modules) {
    const { outputText } = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } });
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    return context.exports;
}

function find(node, predicate) {
    if (!node || typeof node !== 'object') return;
    if (predicate(node)) return node;
    const children = Array.isArray(node.children) ? node.children : node.children?.default?.() ?? [];
    for (const child of children) {
        const found = find(child, predicate);
        if (found) return found;
    }
}

function mount(t, lines) {
    registerTS(() => ts);
    const filename = fileURLToPath(new URL('../resources/js/components/ProjectHeader.vue', import.meta.url));
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename });
    const compiled = compileScript(descriptor, { id: 'project-header-test', fs: { fileExists: existsSync, readFile: path => readFileSync(path, 'utf8') } });
    let resize;
    let mounted;
    const modules = {
        vue: { ...vue, onMounted: callback => { mounted = callback; } },
        '@vueuse/core': { ...vueuse, useResizeObserver: (_, callback) => { resize = callback; } },
        '@inertiajs/vue3': { Link: 'a' },
        '@/components/ui/button': { Button: 'button' },
        '@/components/MarkdownContent.vue': { __esModule: true, default: 'article' },
        '@/components/ProjectIcon.vue': { __esModule: true, default: 'span' },
        '@/components/ProjectStatusDot.vue': { __esModule: true, default: 'span' },
        '@/components/ChoiceSelect.vue': { __esModule: true, default: 'select' },
        '@lucide/vue': { PencilIcon: 'svg' },
    };
    const component = script(compiled.content, modules).default;
    const template = compileTemplate({ source: descriptor.template.content, filename, id: 'project-header-test', compilerOptions: { bindingMetadata: compiled.bindings } });
    assert.deepEqual(template.errors, []);
    const { render } = script(template.code, { vue });
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const props = vue.reactive({ project: { id: 'one', name: 'Sitepulse', description: 'Project description', description_html: '<p>Project description</p>', tags: [] }, statuses: ['Live'], statusDisabled: false, statusError: '', statusNeedsReload: false });
    const state = scope.run(() => component.setup(props, { expose() {}, emit() {} }));
    const classes = new Set(['line-clamp-3']);
    const element = {
        lines,
        classList: { contains: name => classes.has(name), add: name => classes.add(name), remove: name => classes.delete(name) },
        get clientHeight() { return (classes.has('line-clamp-3') ? Math.min(this.lines, 3) : this.lines) * 24; },
        get scrollHeight() { return this.lines * 24; },
    };
    state.descriptionElement.value = element;
    scope.run(() => vue.watch(state.descriptionExpanded, expanded => {
        if (expanded) classes.delete('line-clamp-3');
        else classes.add('line-clamp-3');
    }, { flush: 'post' }));
    mounted();
    function view() {
        const tree = render(vue.proxyRefs({ ...state, ...props }), [], props, vue.proxyRefs(state));
        const content = find(tree, node => node.type === 'article');
        const description = find(tree, node => Array.isArray(node.children) && node.children.includes(content));
        if (description?.props.class?.split(' ').includes('line-clamp-3')) classes.add('line-clamp-3');
        else classes.delete('line-clamp-3');
        return { tree, content, description, button: find(tree, node => node.props?.['aria-controls'] !== undefined) };
    }
    return { state, props, element, classes, view, resize: () => resize() };
}

test('project tags link to the dashboard filtered by their names', t => {
    const current = mount(t, 1);
    current.props.project.tags = [{ id: 'php', name: 'php' }, { id: 'special', name: 'c++ & café/#?' }];

    const { tree } = current.view();

    for (const tag of current.props.project.tags) {
        const link = find(tree, node => node.props?.['aria-label'] === `Filter projects by ${tag.name}`);
        assert.ok(link);
        assert.equal(link.type, 'a');
        assert.equal(link.children[0].children.trim(), tag.name);
        const url = new URL(link.props.href, 'https://orbit.test');
        assert.equal(url.pathname, '/');
        assert.deepEqual(url.searchParams.getAll('tag'), [tag.name]);
        assert.deepEqual([...url.searchParams.keys()], ['tag']);
        assert.equal(url.hash, '');
    }
});

