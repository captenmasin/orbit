import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

const defaultModels = { openai: 'default-openai-model', anthropic: 'default-anthropic-model', gemini: 'default-gemini-model' };

function mount(t, overrides = {}) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/ScratchpadAiSettings.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'scratchpad-ai-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const toasts = [];
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia, 'vue-sonner': { toast: { success: message => toasts.push(message) } } };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    const events = [];
    t.after(() => scope.stop());
    const ai = { configured: true, provider: 'openai', model: 'saved-model', providers: ['openai', 'anthropic', 'gemini'], default_models: defaultModels, ...overrides };
    const state = scope.run(() => context.exports.default.setup({ native: true, revision: 9, ai }, { expose() {}, emit: (...args) => events.push(args) }));
    return { ...state, scope, events, toasts, original: ai };
}

function assertKeyCleared(form) {
    assert.equal(form.key, '');
    form.reset();
    assert.equal(form.key, '');
}

test('new AI connections prefill the selected provider default without changing saved models', t => {
    for (const provider of [null, 'openai', 'anthropic', 'gemini']) {
        const state = mount(t, { configured: false, provider, model: null });

        assert.equal(state.form.provider, provider ?? 'openai');
        assert.equal(state.form.model, defaultModels[provider ?? 'openai']);
    }

    const saved = mount(t);
    assert.equal(saved.form.model, 'saved-model');
});

test('changing AI provider replaces the draft model with its default and permits custom models', async t => {
    const state = mount(t);
    const requests = [];
    t.mock.method(inertia.http.getClient(), 'request', async request => {
        requests.push(JSON.parse(request.data));
        return { status: 200, data: JSON.stringify({ revision: 10, ai: { ...state.original, provider: 'gemini', model: 'custom-model' } }), headers: {} };
    });
    for (const provider of ['anthropic', 'gemini', 'openai', 'gemini']) {
        state.selectProvider(provider);

        assert.equal(state.form.provider, provider);
        assert.equal(state.form.model, defaultModels[provider]);
    }
    assert.deepEqual(state.status.value, state.original);
    state.form.model = 'custom-model';
    state.form.key = 'synthetic-key';

    await state.save();

    assert.deepEqual(requests, [{ provider: 'gemini', model: 'custom-model', key: 'synthetic-key', revision: 9 }]);
});

test('failed removal preserves the saved AI and a later verified save sends the full configuration', async t => {
    const state = mount(t);
    const requests = [];
    const saved = { configured: true, provider: 'gemini', model: 'replacement-model', providers: ['openai', 'anthropic', 'gemini'], default_models: defaultModels };
    t.mock.method(inertia.http.getClient(), 'request', async request => {
        requests.push({ method: request.method, url: request.url, data: JSON.parse(request.data) });
        if (request.method === 'delete') throw new Error('Offline');
        return { status: 200, data: JSON.stringify({ revision: 10, ai: saved }), headers: {} };
    });
    state.form.key = 'unused-private-key';

    await state.remove();

    assert.deepEqual(state.status.value, state.original);
    assert.deepEqual(state.toasts, []);
    assert.match(state.error.value, /Could not remove/);
    assert.deepEqual(state.events, []);
    assertKeyCleared(state.form);
    state.replacing.value = true;
    Object.assign(state.form, { provider: 'gemini', model: 'replacement-model', key: 'replacement-private-key' });

    await state.save();

    assert.deepEqual(requests, [
        { method: 'delete', url: '/settings/connections/ai', data: { revision: 9 } },
        { method: 'put', url: '/settings/connections/ai', data: { provider: 'gemini', model: 'replacement-model', key: 'replacement-private-key', revision: 9 } },
    ]);
    assert.deepEqual(state.status.value, saved);
    assert.deepEqual(state.events, [['saved', 10, saved]]);
    assert.doesNotMatch(JSON.stringify(state.events), /private-key/);
    assert.equal(state.replacing.value, false);
    assert.equal(state.error.value, '');
    assert.deepEqual(state.toasts, ['AI connection verified and saved.']);
    assertKeyCleared(state.form);
});

test('failed verification keeps the working AI status and clears submitted key values and defaults', async t => {
    const state = mount(t);
    state.replacing.value = true;
    state.form.key = 'rejected-private-key';
    t.mock.method(inertia.http.getClient(), 'request', async () => ({ status: 422, data: JSON.stringify({ errors: { key: 'Verification failed. Your saved connection is unchanged.' } }), headers: {} }));

    await state.save();

    assert.deepEqual(state.status.value, state.original);
    assert.equal(state.replacing.value, true);
    assert.equal(state.form.errors.key, 'Verification failed. Your saved connection is unchanged.');
    assert.deepEqual(state.toasts, []);
    assert.deepEqual(state.events, []);
    assertKeyCleared(state.form);
});

test('successful AI removal clears the key and reports success only after confirmation', async t => {
    const state = mount(t);
    const requests = [];
    const removed = { configured: false, provider: null, model: null, providers: ['openai', 'anthropic', 'gemini'], default_models: defaultModels };
    let finish;
    t.mock.method(inertia.http.getClient(), 'request', request => {
        requests.push({ method: request.method, url: request.url, data: JSON.parse(request.data) });
        return new Promise(resolve => { finish = resolve; });
    });
    state.form.key = 'unused-private-key';

    const pending = state.remove();

    assert.deepEqual(state.toasts, []);
    assert.deepEqual(state.events, []);
    assert.deepEqual(state.status.value, state.original);
    assertKeyCleared(state.form);
    finish({ status: 200, data: JSON.stringify({ revision: 10, ai: removed }), headers: {} });
    await pending;

    assert.deepEqual(requests, [{ method: 'delete', url: '/settings/connections/ai', data: { revision: 9 } }]);
    assert.deepEqual(state.status.value, removed);
    assert.deepEqual(state.events, [['saved', 10, removed]]);
    assert.deepEqual(state.toasts, ['AI connection removed. Your notes are unchanged.']);
    assert.equal(state.replacing.value, true);
    assert.equal(state.error.value, '');
    assertKeyCleared(state.form);
});

test('leaving AI settings cancels pending verification and clears the key without reporting a save', async t => {
    const state = mount(t);
    let signal;
    t.mock.method(inertia.http.getClient(), 'request', request => {
        signal = request.signal;
        return new Promise((resolve, reject) => {
            request.signal.addEventListener('abort', () => reject(Object.assign(new Error('Cancelled'), { name: 'AbortError' })), { once: true });
        });
    });
    state.form.key = 'pending-private-key';
    const pending = state.save();

    state.scope.stop();
    await pending;

    assert.equal(signal.aborted, true);
    assert.deepEqual(state.status.value, state.original);
    assert.deepEqual(state.toasts, []);
    assert.deepEqual(state.events, []);
    assertKeyCleared(state.form);
});
