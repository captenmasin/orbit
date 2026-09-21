import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import { http } from '@inertiajs/core';
import ts from 'typescript';
import * as vue from 'vue';

test('credential forms retain failures and clear token values and defaults after every submission', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProviderConnections.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'provider-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => name === 'vue' ? { ...vue, onBeforeUnmount: vue.onScopeDispose } : name === '@inertiajs/vue3' ? inertia : {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const component = scope.run(() => context.exports.default.setup({ connections: [], native: true }, { expose() {} }));
    const requests = [];
    let status = 422;
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(JSON.parse(request.data));
        return { status, data: JSON.stringify(status === 200 ? { saved: true } : { message: 'Token required', errors: { connection: 'Token required' } }), headers: {} };
    });
    let reloads = 0;
    t.mock.method(inertia.router, 'reload', () => { reloads++; });
    for (const code of [422, 200]) {
        status = code;
        component.edit(null);
        component.form.label = 'Test';
        component.credential.value = 'DUMMY-TRANSIENT-TOKEN';
        await component.save();
        assert.equal(component.open.value, code === 422);
        assert.equal(component.credential.value, '');
        assert.equal(component.form.token, '');
        component.form.reset();
        assert.equal(component.form.token, '', 'Successful requests must not retain a token in defaults');
        if (code === 422) assert.equal(component.error.value, 'Token required');
    }
    assert.equal(requests.length, 2);
    assert.equal(requests[0].token, 'DUMMY-TRANSIENT-TOKEN');
    assert.equal(reloads, 1);
    component.credential.value = 'unsent-token';
    scope.stop();
    assert.equal(component.credential.value, '');
});
