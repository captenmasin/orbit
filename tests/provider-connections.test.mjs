import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

const { http } = inertia;

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


test('label editing submits metadata only and retains a rejected draft', async t => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ProviderConnections.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'label-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const context = { exports: {}, require: name => name === 'vue' ? { ...vue, onBeforeUnmount: vue.onScopeDispose } : name === '@inertiajs/vue3' ? inertia : {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope(); t.after(() => scope.stop());
    const connection = { id: 'work', label: 'Work', revision: 4 };
    const state = scope.run(() => context.exports.default.setup({ connections: [connection], native: true }, { expose() {} }));
    const requests = []; let fail = true;
    t.mock.method(inertia.router, 'reload', () => {});
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push({ url: request.url, data: JSON.parse(request.data) });
        if (fail) throw new Error('Offline');
        return { status: 200, data: JSON.stringify({ saved: true }), headers: {} };
    });
    state.editLabel(connection); state.labelForm.label = 'Team';
    await state.rename();
    assert.equal(state.renaming.value.id, 'work'); assert.equal(state.labelForm.label, 'Team'); assert.match(state.labelError.value, /review your draft/);
    fail = false; await state.rename();
    assert.equal(state.renaming.value, null);
    assert.deepEqual(requests[0], { url: '/connections/work/label', data: { label: 'Team', revision: 4 } });
});
