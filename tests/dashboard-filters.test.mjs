import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import ts from 'typescript';
import * as vue from 'vue';

test('dashboard searches after typing and applies dropdown filters immediately', () => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/pages/Dashboard.vue', import.meta.url), 'utf8'));
    const script = compileScript(descriptor, { id: 'dashboard-filters-test' });
    const { outputText } = ts.transpileModule(script.content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const requests = [];
    let pendingSearch;
    const modules = {
        vue: { ...vue, onBeforeUnmount() {} },
        '@inertiajs/vue3': { usePage: () => ({ props: { sidebarProjects: [] } }), router: { get: (...args) => requests.push(args) } },
    };
    const context = {
        exports: {},
        require: name => modules[name] ?? {},
        setTimeout: callback => { pendingSearch = callback; return 1; },
        clearTimeout: () => { pendingSearch = undefined; },
    };
    runInNewContext(outputText, context);
    const dashboard = context.exports.default.setup({ filters: { q: '', status: '', tag: [], sort: 'name' }, projects: { data: [] }, statuses: [], tags: [] }, { expose() {} });

    dashboard.search.q = 'sitepulse';
    dashboard.queueSearch();
    assert.equal(requests.length, 0);
    pendingSearch();
    assert.equal(requests.length, 1);
    assert.equal(requests[0][1].q, 'sitepulse');
    assert.equal(requests[0][2].replace, true);

    dashboard.search.status = 'Live';
    dashboard.applyFilters();
    assert.equal(requests.length, 2);
    assert.equal(requests[1][1].status, 'Live');
    assert.equal('sort' in requests[1][1], false);

    dashboard.filterByTag('vue');
    assert.equal(requests[2][1].q, 'sitepulse');
    assert.equal(requests[2][1].status, 'Live');
    assert.deepEqual(Array.from(requests[2][1].tag), ['vue']);
    dashboard.filterByTag('laravel');
    assert.deepEqual(Array.from(requests[3][1].tag), ['vue', 'laravel']);
    dashboard.filterByTag('vue');
    assert.deepEqual(Array.from(requests[4][1].tag), ['laravel']);
    dashboard.filterByTag('laravel');
    assert.equal('tag' in requests[5][1], false);

    dashboard.clearFilters();
    assert.equal(requests.length, 7);
    assert.deepEqual(Object.keys(requests[6][1]), []);
});
