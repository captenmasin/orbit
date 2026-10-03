import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';

const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ui/field/FieldError.vue', import.meta.url), 'utf8'));
const source = compileScript(descriptor, { id: 'field-error-test', inlineTemplate: true }).content;
const modules = { vue, '@/lib/utils': { cn: (...classes) => classes.filter(Boolean).join(' ') } };
const context = { exports: {}, require: name => modules[name] ?? {} };
runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, context);
const FieldError = context.exports.default;

test('field errors occupy no space when empty and display supplied messages', async () => {
    const render = (errors, slots) => renderToString(vue.createSSRApp({ render: () => vue.h(FieldError, { errors }, slots) }));

    for (const errors of [undefined, [], [undefined], ['', undefined], [{ message: undefined }]]) {
        assert.doesNotMatch(await render(errors), /data-slot="field-error"/);
    }
    const single = await render([undefined, 'Enter a valid URL.', 'Enter a valid URL.']);
    assert.match(single, /role="alert"/);
    assert.equal(single.split('Enter a valid URL.').length - 1, 1);
    assert.doesNotMatch(single, /<ul/);
    const multiple = await render(['Enter a valid URL.', { message: 'Choose a category.' }]);
    assert.match(multiple, /<li>Enter a valid URL\.<\/li>/);
    assert.match(multiple, /<li>Choose a category\.<\/li>/);
    assert.match(await render(undefined, { default: () => 'Could not save.' }), /Could not save\./);
});
