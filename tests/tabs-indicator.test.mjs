import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { fileURLToPath } from 'node:url';
import { compileScript, parse, registerTS } from '@vue/compiler-sfc';
import * as vueuse from '@vueuse/core';
import ts from 'typescript';
import * as vue from 'vue';

test('the underline follows selected tabs across rows and snaps on resize', async t => {
    registerTS(() => ts);
    const filename = fileURLToPath(new URL('../resources/js/components/ui/tabs/TabsList.vue', import.meta.url));
    const { descriptor } = parse(readFileSync(filename, 'utf8'), { filename });
    const { outputText } = ts.transpileModule(compileScript(descriptor, {
        id: 'tabs-indicator-test', fs: { fileExists: existsSync, readFile: path => readFileSync(path, 'utf8') },
    }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    let activeTab = { offsetLeft: 0, offsetTop: 0, offsetWidth: 90, offsetHeight: 32, dataset: { orientation: 'horizontal' } };
    const underline = { style: {}, getBoundingClientRect() {} };
    const list = { querySelector: () => activeTab, querySelectorAll: () => activeTab ? [activeTab] : [] };
    let selectTab;
    let resize;
    const modules = {
        vue: { ...vue, useTemplateRef: name => vue.shallowRef(name === 'tabList' ? list : underline) },
        '@vueuse/core': { ...vueuse, useMutationObserver: (_, callback) => { selectTab = callback; }, useResizeObserver: (_, callback) => { resize = callback; } },
    };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    scope.run(() => context.exports.default.setup(vue.reactive({ variant: 'line' }), { expose() {} }));
    await vue.nextTick();
    assert.equal(underline.style.transform, 'translate(0px, 35px)');
    assert.equal(underline.style.transition, '');

    activeTab = { ...activeTab, offsetLeft: 240, offsetWidth: 120 };
    selectTab();
    assert.equal(underline.style.transform, 'translate(240px, 35px)');
    assert.equal(underline.style.width, '120px');
    assert.equal(underline.style.height, '2px');

    activeTab = { ...activeTab, offsetLeft: 0, offsetTop: 36 };
    selectTab();
    assert.equal(underline.style.transform, 'translate(0px, 71px)');
    activeTab.offsetLeft = 400;
    activeTab.offsetTop = 0;
    const transitions = [];
    Object.defineProperty(underline.style, 'transition', { set: value => transitions.push(value) });
    resize();
    assert.equal(underline.style.transform, 'translate(400px, 35px)');
    assert.deepEqual(transitions, ['none', '']);

    activeTab = { ...activeTab, offsetLeft: 0, offsetTop: 80, dataset: { orientation: 'vertical' } };
    selectTab();
    assert.equal(underline.style.transform, 'translate(123px, 80px)');
    assert.equal(underline.style.width, '2px');
    assert.equal(underline.style.height, '32px');
    activeTab = null;
    selectTab();
    assert.equal(underline.style.transform, 'translate(123px, 80px)');
});
