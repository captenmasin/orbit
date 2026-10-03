import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';
import { renderToString } from 'vue/server-renderer';

function mount(t, props = {}) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/SecretPinRecovery.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'pin-recovery-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    const events = [];
    t.after(() => scope.stop());
    const state = scope.run(() => context.exports.default.setup(props, { expose() {}, emit: (...args) => events.push(args) }));
    return { ...state, scope, events };
}

const response = (data, status = 200) => ({ status, data: JSON.stringify(data), headers: {} });
const settle = () => new Promise(resolve => setImmediate(resolve));

test('recovery renders only the authentication methods available on the device', async () => {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/SecretPinRecovery.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'recovery-methods-render', inlineTemplate: true }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const passthrough = (_, { slots }) => slots.default?.();
    const controls = new Proxy({ default: passthrough }, { get: (target, name) => target[name] ?? passthrough });

    for (const [touchId, windowsHello, recoveryCode, expected] of [
        [true, false, true, ['Touch ID', 'recovery code']],
        [false, true, true, ['Windows Hello', 'recovery code']],
        [false, false, true, ['recovery code']],
        [false, false, false, []],
    ]) {
        let request = 0;
        const modules = { vue, '@inertiajs/vue3': { useHttp: () => request++ === 0
            ? vue.reactive({ processing: false, response: { touch_id_available: touchId, windows_hello_available: windowsHello, recovery_code_set: recoveryCode } })
            : vue.reactive({ processing: false }) } };
        const context = { exports: {}, require: name => modules[name] ?? controls };
        runInNewContext(outputText, context);
        const html = await renderToString(vue.createSSRApp(context.exports.default));

        for (const name of ['Touch ID', 'Windows Hello', 'recovery code']) {
            assert.equal(html.includes(`Use ${name}`), expected.includes(name), `${name} availability`);
        }
        assert.match(html, /Reset secrets/);
        if (!recoveryCode) assert.match(html, /No recovery code saved/);
    }
});

function enterRecovery(state, method) {
    state.open.value = true;
    state.chooseMethod(method);
    Object.assign(state.form, { pin: '2468', pin_confirmation: '2468' });
}

function assertInputsCleared(form) {
    for (const field of ['pin', 'pin_confirmation', 'recovery_code', 'confirmation']) assert.equal(form[field], '');
    assert.equal(form.response, null);
    form.reset();
    for (const field of ['pin', 'pin_confirmation', 'recovery_code', 'confirmation']) assert.equal(form[field], '');
}

test('opening recovery discovers the available methods and closing discards the draft', async t => {
    const state = mount(t);
    const requests = [];
    t.mock.method(inertia.http.getClient(), 'request', async request => {
        requests.push({ method: request.method, url: request.url });
        return response({ touch_id_available: false, recovery_code_set: true });
    });

    state.changeOpen(true);
    await settle();

    assert.deepEqual(requests, [{ method: 'get', url: '/secrets/recovery/status' }]);
    assert.deepEqual(state.status.response, { touch_id_available: false, recovery_code_set: true });
    state.chooseMethod('recovery_code');
    Object.assign(state.form, { recovery_code: 'private-code', pin: '2468', pin_confirmation: '2468' });
    state.changeOpen(false);

    assert.equal(state.open.value, false);
    assert.equal(state.method.value, null);
    assert.equal(state.status.response, null);
    assertInputsCleared(state.form);
});

test('failed recovery discovery reports an error and can be retried', async t => {
    const state = mount(t);
    let attempts = 0;
    t.mock.method(inertia.http.getClient(), 'request', async () => {
        if (++attempts === 1) throw new Error('Offline');
        return response({ touch_id_available: true, recovery_code_set: false });
    });

    state.changeOpen(true);
    await settle();

    assert.equal(state.open.value, true);
    assert.equal(state.status.response, null);
    assert.match(state.error.value, /could not be loaded/);
    assert.deepEqual(state.events, []);

    await state.loadStatus();

    assert.equal(state.error.value, '');
    assert.deepEqual(state.status.response, { touch_id_available: true, recovery_code_set: false });
});

for (const method of ['touch_id', 'windows_hello', 'recovery_code', 'reset']) {
    test(`${method} recovery sends the chosen method and retains the replacement code until acknowledged`, async t => {
        const state = mount(t);
        const requests = [];
        t.mock.method(inertia.http.getClient(), 'request', async request => {
            requests.push({ method: request.method, url: request.url, data: JSON.parse(request.data) });
            return response({ revision: 12, recovery_code: 'replacement-private-code' });
        });
        enterRecovery(state, method);
        if (method === 'recovery_code') state.form.recovery_code = 'saved-private-code';
        if (method === 'reset') state.form.confirmation = 'DELETE ALL SECRETS';

        await state.recover();

        assert.deepEqual(requests, [{ method: 'post', url: method === 'reset' ? '/secrets/reset' : '/secrets/recover', data: {
            method, recovery_code: method === 'recovery_code' ? 'saved-private-code' : '',
            confirmation: method === 'reset' ? 'DELETE ALL SECRETS' : '', pin: '2468', pin_confirmation: '2468',
        } }]);
        assert.deepEqual(state.events, [['saved', 12]]);
        assert.equal(state.recoveryCode.value, 'replacement-private-code');
        assertInputsCleared(state.form);
        state.changeOpen(false);
        assert.equal(state.open.value, true);
        assert.equal(state.recoveryCode.value, 'replacement-private-code');

        state.finish();

        assert.equal(state.open.value, false);
        assert.equal(state.recoveryCode.value, '');
    });
}

test('destructive reset requires the exact confirmation before sending a request', async t => {
    const state = mount(t);
    const request = t.mock.method(inertia.http.getClient(), 'request', async () => response({ revision: 12, recovery_code: 'new-code' }));
    enterRecovery(state, 'reset');

    for (const confirmation of ['', 'delete all secrets', 'DELETE ALL SECRET', 'DELETE ALL SECRETS ']) {
        state.form.confirmation = confirmation;
        await state.recover();
    }

    assert.equal(request.mock.callCount(), 0);
    assert.deepEqual(state.events, []);
    state.form.confirmation = 'DELETE ALL SECRETS';
    await state.recover();
    assert.equal(request.mock.callCount(), 1);
});

test('rejected recovery keeps the dialog open, shows validation errors and clears secret inputs', async t => {
    const state = mount(t);
    t.mock.method(inertia.http.getClient(), 'request', async () => response({ errors: { recovery_code: 'The recovery code is incorrect.' } }, 422));
    enterRecovery(state, 'recovery_code');
    state.form.recovery_code = 'wrong-private-code';

    await state.recover();

    assert.equal(state.open.value, true);
    assert.equal(state.method.value, 'recovery_code');
    assert.equal(state.form.errors.recovery_code, 'The recovery code is incorrect.');
    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assertInputsCleared(state.form);
});

test('Touch ID cancellation and network failures keep recovery open without reporting success', async t => {
    const state = mount(t);
    let offline = false;
    t.mock.method(inertia.http.getClient(), 'request', async () => {
        if (offline) throw new Error('Offline');
        return response({ message: 'Touch ID was cancelled.' }, 403);
    });
    enterRecovery(state, 'touch_id');

    await state.recover();

    assert.equal(state.error.value, 'Touch ID was cancelled.');
    assert.equal(state.open.value, true);
    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assertInputsCleared(state.form);
    offline = true;
    Object.assign(state.form, { pin: '2468', pin_confirmation: '2468' });

    await state.recover();

    assert.match(state.error.value, /could not be reset/);
    assert.equal(state.open.value, true);
    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assertInputsCleared(state.form);
});

test('closing during recovery discovery cancels the request without showing a late error', async t => {
    const state = mount(t);
    let signal;
    t.mock.method(inertia.http.getClient(), 'request', request => {
        signal = request.signal;
        return new Promise((resolve, reject) => request.signal.addEventListener('abort', () => reject(Object.assign(new Error('Cancelled'), { name: 'AbortError' })), { once: true }));
    });
    state.changeOpen(true);

    state.changeOpen(false);
    await settle();

    assert.equal(signal.aborted, true);
    assert.equal(state.open.value, false);
    assert.equal(state.error.value, '');
    assert.equal(state.status.response, null);
    assert.deepEqual(state.events, []);
});

test('pending recovery prevents duplicate submits and unmount ignores a late successful response', async t => {
    const state = mount(t);
    let signal;
    let finish;
    const request = t.mock.method(inertia.http.getClient(), 'request', request => {
        signal = request.signal;
        return new Promise(resolve => { finish = resolve; });
    });
    enterRecovery(state, 'touch_id');
    const pending = state.recover();

    await state.recover();
    state.changeOpen(false);

    assert.equal(request.mock.callCount(), 1);
    assert.equal(state.open.value, true);
    assert.deepEqual(state.events, []);
    state.scope.stop();
    assert.equal(signal.aborted, true);
    assertInputsCleared(state.form);
    finish(response({ revision: 12, recovery_code: 'late-private-code' }));
    await pending;

    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assertInputsCleared(state.form);
});
