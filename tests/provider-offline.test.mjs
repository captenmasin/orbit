import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import { http } from '@inertiajs/core';
import ts from 'typescript';
import * as vue from 'vue';

test('offline activity preserves snapshots, stops requests, and resumes on reconnect', async t => {
    const online = vue.ref(false);
    const focused = vue.ref(true);
    const visible = vue.ref('visible');
    let tick;
    const modules = {
        vue,
        '@inertiajs/vue3': inertia,
        '@vueuse/core': {
            useOnline: () => online,
            useWindowFocus: () => focused,
            useDocumentVisibility: () => visible,
            useIntervalFn: callback => { tick = callback; },
        },
    };
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProjectProviderActivity.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'provider-offline-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const repository = { id: 'repository', provider_connection_id: 'connection', provider_connection: { state: 'Current' }, provider_snapshots: [{ state: 'Current', checked_at: '2026-09-19T12:00:00Z', payload: { items: [{ id: '1', title: 'Cached issue' }] } }] };
    const props = vue.reactive({ projectId: 'project', repositories: [repository], connections: [], active: true });
    const before = JSON.stringify(props.repositories);
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(JSON.parse(request.data));
        return { status: 200, data: JSON.stringify({ queued: true }), headers: {} };
    });
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', options => { reloads++; options.onFinish(); });
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const component = scope.run(() => context.exports.default.setup(props, { expose() {} }));

    await tick();
    await component.refresh(repository);
    assert.equal(component.disabled(repository), true);
    assert.equal(requests.length, 0);
    assert.equal(reloads, 0);
    assert.equal(JSON.stringify(props.repositories), before);

    online.value = true;
    await vue.nextTick();
    await new Promise(resolve => setImmediate(resolve));
    assert.equal(component.disabled(repository), false);
    assert.equal(requests.length, 1);
    assert.equal(requests[0].only_stale, true);
    assert.equal(reloads, 1);
    online.value = false;
    await vue.nextTick();
    await tick();
    assert.equal(requests.length, 1);
    assert.equal(JSON.stringify(props.repositories), before);
});
