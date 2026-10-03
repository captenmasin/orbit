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

function text(node) {
    if (typeof node.children === 'string') return node.children.trim();
    const children = Array.isArray(node.children) ? node.children : node.children.default();
    return children.map(text).join('');
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
    const classes = new Set(['line-clamp-2']);
    const element = {
        lines,
        classList: { contains: name => classes.has(name), add: name => classes.add(name), remove: name => classes.delete(name) },
        get clientHeight() { return (classes.has('line-clamp-2') ? Math.min(this.lines, 2) : this.lines) * 24; },
        get scrollHeight() { return this.lines * 24; },
    };
    state.descriptionElement.value = element;
    scope.run(() => vue.watch(state.descriptionExpanded, expanded => {
        if (expanded) classes.delete('line-clamp-2');
        else classes.add('line-clamp-2');
    }, { flush: 'sync' }));
    mounted();
    function view() {
        const tree = render(vue.proxyRefs({ ...state, ...props }), [], props, vue.proxyRefs(state));
        const content = find(tree, node => node.type === 'article');
        const description = find(tree, node => Array.isArray(node.children) && node.children.includes(content));
        if (description?.props.class?.split(' ').includes('line-clamp-2')) classes.add('line-clamp-2');
        else classes.delete('line-clamp-2');
        return { content, description, button: find(tree, node => node.props?.['aria-controls'] !== undefined) };
    }
    return { state, props, element, classes, view, resize: () => resize() };
}

test('descriptions of up to two lines have no show more control', async t => {
    const current = mount(t, 1);
    await vue.nextTick();
    await vue.nextTick();

    for (const lines of [1, 2]) {
        current.element.lines = lines;
        current.resize();

        const { content, description, button } = current.view();
        assert.equal(button, undefined);
        assert.ok(description.props.class.includes('line-clamp-2'));
        assert.equal(content.props.html, current.props.project.description_html);
    }
});

test('a third description line enables an accessible show more control', async t => {
    const current = mount(t, 3);
    await vue.nextTick();
    await vue.nextTick();
    let { description, button } = current.view();
    assert.equal(button.props.type, 'button');
    assert.equal(button.props['aria-controls'], description.props.id);
    assert.equal(button.props['aria-expanded'], false);
    assert.equal(text(button), 'Show more');

    button.props.onClick();
    await vue.nextTick();

    ({ description, button } = current.view());
    assert.ok(!description.props.class.includes('line-clamp-2'));
    assert.equal(button.props['aria-expanded'], true);
    assert.equal(text(button), 'Show less');
    current.resize();
    assert.equal(current.state.descriptionOverflows.value, true);
    assert.equal(current.classes.has('line-clamp-2'), false);
    assert.equal(current.state.descriptionExpanded.value, true);

    button.props.onClick();
    await vue.nextTick();

    ({ description, button } = current.view());
    assert.ok(description.props.class.includes('line-clamp-2'));
    assert.equal(button.props['aria-expanded'], false);
});

test('description disclosure follows resize and resets after content or project changes', async t => {
    const current = mount(t, 2);
    await vue.nextTick();
    await vue.nextTick();
    current.element.lines = 6;
    current.resize();
    current.view().button.props.onClick();
    await vue.nextTick();
    current.view();

    current.element.lines = 2;
    current.resize();
    assert.equal(current.view().button, undefined);
    current.element.lines = 7;
    current.props.project.description_html = '<p>A replacement description</p>';
    await vue.nextTick();
    await vue.nextTick();
    await vue.nextTick();

    assert.equal(current.view().button.props['aria-expanded'], false);
    current.view().button.props.onClick();
    await vue.nextTick();
    current.view();
    current.props.project = { ...current.props.project, id: 'two' };
    await vue.nextTick();
    await vue.nextTick();
    await vue.nextTick();

    assert.equal(current.view().button.props['aria-expanded'], false);
    current.state.descriptionElement.value = null;
    current.props.project.description = '';
    current.props.project.description_html = '';
    await vue.nextTick();
    await vue.nextTick();
    await vue.nextTick();

    assert.equal(current.state.descriptionOverflows.value, false);
    assert.equal(current.view().content, undefined);
    assert.equal(current.view().button, undefined);
});
