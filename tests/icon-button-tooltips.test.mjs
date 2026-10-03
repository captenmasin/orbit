import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse, registerTS } from '@vue/compiler-sfc';
import * as vueuse from '@vueuse/core';
import { cva } from 'class-variance-authority';
import * as reka from 'reka-ui';
import ts from 'typescript';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';

registerTS(() => ts);
const modules = { vue, '@vueuse/core': vueuse, 'reka-ui': { ...reka, TooltipPortal: (props, { slots }) => vue.h(reka.TooltipPortal, { ...props, forceMount: true }, slots) }, 'class-variance-authority': { cva }, '@/lib/utils': { cn: (...classes) => classes.filter(Boolean).join(' ') } };
function script(source) {
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } }).outputText, context);
    return context.exports;
}
function component(path) {
    const source = new URL(`../resources/js/components/ui/${path}.vue`, import.meta.url);
    const { descriptor } = parse(readFileSync(source, 'utf8'), { filename: source.pathname });
    return script(compileScript(descriptor, { id: path, inlineTemplate: true, fs: ts.sys }).content).default;
}
const Tooltip = component('tooltip/Tooltip');
modules['@/components/ui/tooltip'] = {
    Tooltip: (props, { slots }) => vue.h(Tooltip, { ...props, defaultOpen: true }, slots),
    TooltipTrigger: component('tooltip/TooltipTrigger'),
    TooltipContent: component('tooltip/TooltipContent'),
};
modules['.'] = script(readFileSync(new URL('../resources/js/components/ui/button/index.ts', import.meta.url), 'utf8'));
const Button = component('button/Button');
async function render(props, child = () => vue.h('svg'), wrap = button => button) {
    const context = {};
    const html = await renderToString(vue.createSSRApp({ render: () => vue.h(reka.TooltipProvider, {}, () => wrap(vue.h(Button, props, child))) }), context);
    return html + Object.values(context.teleports ?? {}).join('');
}

test('icon buttons show shadcn tooltips from their title or accessible label and preserve button attributes', async () => {
    for (const size of ['icon', 'icon-xs', 'icon-sm', 'icon-lg']) {
        const html = await render({ size, variant: 'ghost', title: 'Edit label', 'aria-label': 'Edit label for Work', 'aria-describedby': 'help', disabled: true, class: 'drag-handle' });

        assert.match(html, /data-slot="tooltip-content"/);
        assert.match(html, /Edit label<!--/);
        assert.match(html, /<button\b[^>]*aria-label="Edit label for Work"/);
        assert.match(html, /aria-describedby="help reka-tooltip-content-[^"]+"/);
        assert.match(html, /\bdisabled(?:="")?/);
        assert.match(html, /\bdrag-handle\b/);
        assert.doesNotMatch(html, /\btitle=/);
        assert.equal((html.match(/<button\b/g) ?? []).length, 1);
    }
    assert.match(await render({ size: 'icon-sm', 'aria-label': 'Close search' }), /Close search<!--/);
    const link = await render({ size: 'icon-sm', asChild: true, title: 'New project' }, () => vue.h('a', { href: '/projects/create', 'aria-label': 'New project' }, vue.h('svg')));
    assert.match(link, /<a\b[^>]*href="\/projects\/create"/);
    assert.match(link, /New project<!--/);
    assert.doesNotMatch(link, /<button\b/);
    const menu = await render({ size: 'icon-sm', 'aria-label': 'Project actions' }, undefined, button => vue.h(reka.DropdownMenuRoot, {}, () => vue.h(reka.DropdownMenuTrigger, { asChild: true }, () => button)));
    assert.match(menu, /<button\b[^>]*data-state="closed"/);
    assert.match(menu, /aria-haspopup="menu"/);
    const text = await render({ size: 'sm', title: 'Save changes' }, () => 'Save');
    assert.match(text, /title="Save changes"/);
    assert.doesNotMatch(text, /role="tooltip"/);
    assert.doesNotMatch(await render({ size: 'icon-sm' }), /role="tooltip"/);
});
