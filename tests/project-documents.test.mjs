import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

function mount(t, file, input, globals = {}, overrides = {}) {
    const { descriptor } = parse(readFileSync(new URL(`../resources/js/components/${file}.vue`, import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'documents-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue: { ...vue, useId: () => 'test', onMounted() {} }, '@inertiajs/vue3': { ...inertia, ...overrides } };
    const context = { exports: {}, require: name => modules[name] ?? {}, ...globals };
    runInNewContext(outputText, context);
    const props = vue.reactive(input);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    return { props, state: scope.run(() => context.exports.default.setup(props, { expose() {} })) };
}

test('documents retain their draft through conflict reloads and do not substitute removed search targets', async t => {
    const document = { id: 'first', title: 'Hosting', body: 'Saved body', revision: 1 };
    const { state, props } = mount(t, 'ProjectDocuments', { project: { id: 'project', revision: 1, documents: [document] }, targetDocumentId: 'gone' }, {}, {
        router: { reload: options => options.onSuccess() },
    });
    assert.equal(state.selected.value, undefined);
    assert.equal(state.missing.value, true);
    props.targetDocumentId = 'first';
    await vue.nextTick();
    assert.equal(state.selected.value.title, 'Hosting');
    state.edit(state.selected.value);
    state.form.title = 'Draft title';
    state.form.body = '\nUntouched draft\n';
    props.project = { id: 'project', revision: 4, documents: [{ ...document, body: 'Changed elsewhere', revision: 3 }] };
    await vue.nextTick();
    assert.equal(state.form.revision, 1);
    assert.equal(state.form.document_revision, 1);
    state.reload();
    assert.equal(state.form.revision, 4);
    assert.equal(state.form.document_revision, 3);
    assert.equal(state.form.title, 'Draft title');
    assert.equal(state.form.body, '\nUntouched draft\n');
});

test('Markdown heading controls focus headings and code copy preserves whitespace and line breaks', async t => {
    const source = "printf 'hello'\n  ./deploy --safe\n\n";
    let copied;
    let button;
    let focused = false;
    let scrolled = false;
    const heading = { textContent: 'Deploy', focus: () => { focused = true; }, scrollIntoView: () => { scrolled = true; } };
    const code = { textContent: source };
    const block = { querySelector: selector => selector === 'button' ? button : code, prepend: value => { button = value; } };
    const { state } = mount(t, 'MarkdownContent', { html: '<h2>Deploy</h2><pre><code>commands</code></pre>', navigation: true }, {
        navigator: { clipboard: { writeText: async text => { copied = text; } } },
        document: { createElement: () => ({}), getElementById: () => heading },
    });
    state.content.value = { querySelectorAll: selector => selector === 'pre' ? [block] : [heading] };
    await state.enhance();
    assert.equal(state.headings.value[0].text, 'Deploy');
    state.goToHeading(state.headings.value[0].id);
    assert.equal(focused && scrolled, true);
    await button.onclick();
    assert.equal(copied, source);
    assert.equal(state.copyMessage.value, 'Code copied.');
    const firstButton = button;
    await state.enhance();
    assert.equal(button, firstButton);
});
