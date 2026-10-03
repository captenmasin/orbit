import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';
import { compileScript, parse } from '@vue/compiler-sfc';
import * as inertia from '@inertiajs/vue3';
import ts from 'typescript';
import * as vue from 'vue';

const { http } = inertia;

function mount(t, props = {}) {
    const { descriptor } = parse(readFileSync(new URL('../resources/js/components/SecretsPinSettings.vue', import.meta.url), 'utf8'));
    const { outputText } = ts.transpileModule(compileScript(descriptor, { id: 'settings-test' }).content, { compilerOptions: { module: ts.ModuleKind.CommonJS } });
    const toasts = [];
    const modules = { vue: { ...vue, onBeforeUnmount: vue.onScopeDispose }, '@inertiajs/vue3': inertia, 'vue-sonner': { toast: { success: message => toasts.push(message) } } };
    const context = { exports: {}, require: name => modules[name] ?? {} };
    runInNewContext(outputText, context);
    const scope = vue.effectScope();
    t.after(() => scope.stop());
    const events = [];
    const state = scope.run(() => context.exports.default.setup({ native: true, pinSet: false, ...props }, { expose() {}, emit: (...args) => events.push(args) }));
    return { ...state, scope, events, toasts };
}

function assertPinCleared(form) {
    assert.equal(form.current_pin, '');
    assert.equal(form.pin, '');
    assert.equal(form.pin_confirmation, '');
    assert.equal(form.response, null);
    form.reset();
    assert.equal(form.current_pin, '');
    assert.equal(form.pin, '');
    assert.equal(form.pin_confirmation, '');
}

test('closing the PIN dialog clears cancelled digits, errors and defaults', t => {
    const state = mount(t, { pinSet: true });
    assert.equal(state.open.value, false);
    state.changeOpen(true);
    Object.assign(state.form, { current_pin: '0123', pin: '4567', pin_confirmation: '4567' });
    state.form.setError('current_pin', 'Incorrect PIN.');
    state.error.value = 'Try again.';

    state.changeOpen(false);

    assert.equal(state.open.value, false);
    assert.equal(state.form.hasErrors, false);
    assert.equal(state.error.value, '');
    assertPinCleared(state.form);
    state.changeOpen(true);
    assert.equal(state.open.value, true);
    assert.equal(state.form.pin, '');
});

test('the PIN dialog stays open during saving and until its recovery code is acknowledged', async t => {
    const state = mount(t);
    let finish;
    t.mock.method(http.getClient(), 'request', () => new Promise(resolve => { finish = resolve; }));
    state.changeOpen(true);
    state.form.pin = state.form.pin_confirmation = '0123';

    const pending = state.savePin();
    state.changeOpen(false);
    assert.equal(state.open.value, true);
    finish({ status: 200, data: JSON.stringify({ recovery_code: 'new-private-code' }), headers: {} });
    await pending;
    state.changeOpen(false);

    assert.equal(state.open.value, true);
    assert.equal(state.recoveryCode.value, 'new-private-code');
    state.finishRecovery();
    assert.equal(state.open.value, false);
    assert.equal(state.recoveryCode.value, '');
    assertPinCleared(state.form);
});

test('setting a PIN waits for Save PIN and avoids duplicate requests', async t => {
    const state = mount(t);
    const requests = [];
    let finish;
    t.mock.method(http.getClient(), 'request', request => {
        requests.push(request);
        return new Promise(resolve => { finish = resolve; });
    });
    state.form.pin = state.form.pin_confirmation = '0123';
    await vue.nextTick();
    assert.equal(requests.length, 0);

    const pending = state.savePin();
    await state.savePin();
    assert.deepEqual(state.toasts, []);
    finish({ status: 200, data: JSON.stringify({ saved: true, recovery_code: 'setup-recovery-code' }), headers: {} });
    await pending;

    assert.equal(requests.length, 1);
    assert.equal(requests[0].method, 'post');
    assert.equal(requests[0].url, '/secrets/pin');
    assert.deepEqual(JSON.parse(requests[0].data), { current_pin: '', pin: '0123', pin_confirmation: '0123' });
    assert.equal(state.pinSet.value, true);
    assert.equal(state.recoveryCode.value, 'setup-recovery-code');
    assert.deepEqual(state.toasts, ['PIN saved.']);
    assertPinCleared(state.form);
});

test('changing an existing PIN sends the current PIN and clears all digits afterward', async t => {
    const state = mount(t, { pinSet: true });
    const requests = [];
    t.mock.method(http.getClient(), 'request', async request => {
        requests.push(request);
        return { status: 200, data: JSON.stringify({ revision: 9, recovery_code: 'replacement-recovery-code' }), headers: {} };
    });
    state.form.current_pin = '0123';
    state.form.pin = state.form.pin_confirmation = '4567';

    await state.savePin();

    assert.equal(requests[0].method, 'put');
    assert.equal(requests[0].url, '/secrets/pin');
    assert.deepEqual(JSON.parse(requests[0].data), { current_pin: '0123', pin: '4567', pin_confirmation: '4567' });
    assert.equal(state.pinSet.value, true);
    assert.equal(state.recoveryCode.value, 'replacement-recovery-code');
    assert.deepEqual(state.toasts, ['PIN changed. Secrets are locked.']);
    assert.deepEqual(state.events, [['saved', 9]]);
    assertPinCleared(state.form);
});

for (const pinSet of [false, true]) {
    test(`${pinSet ? 'changing' : 'setting'} the PIN blocks another save until the recovery code is acknowledged`, async t => {
        const state = mount(t, { pinSet });
        const requests = [];
        t.mock.method(http.getClient(), 'request', async request => {
            requests.push(request);
            return { status: 200, data: JSON.stringify({ revision: 12, recovery_code: 'new-private-code' }), headers: {} };
        });
        Object.assign(state.form, { current_pin: pinSet ? '1357' : '', pin: '2468', pin_confirmation: '2468' });
        await state.savePin();
        Object.assign(state.form, { current_pin: '2468', pin: '9876', pin_confirmation: '9876' });

        await state.savePin();

        assert.equal(requests.length, 1);
        assert.equal(state.recoveryCode.value, 'new-private-code');
        assert.deepEqual(state.events, [['saved', 12]]);
        state.recoveryCode.value = '';

        await state.savePin();

        assert.equal(requests.length, 2);
        assert.equal(requests[1].method, 'put');
        assertPinCleared(state.form);
    });
}

for (const pinSet of [false, true]) {
    test(`422 ${pinSet ? 'current PIN' : 'confirmation'} errors preserve PIN status and show no success`, async t => {
        const state = mount(t, { pinSet });
        const field = pinSet ? 'current_pin' : 'pin';
        const message = pinSet ? 'Incorrect PIN.' : 'The PIN confirmation does not match.';
        t.mock.method(http.getClient(), 'request', async () => ({ status: 422,
            data: JSON.stringify({ errors: { [field]: [message] } }), headers: {} }));
        state.form.current_pin = '9999';
        state.form.pin = '0123';
        state.form.pin_confirmation = '4567';

        await state.savePin();

        assert.equal(state.form.errors[field], message);
        assert.equal(state.pinSet.value, pinSet);
        assert.equal(state.recoveryCode.value, '');
        assert.deepEqual(state.events, []);
        assert.deepEqual(state.toasts, []);
        assertPinCleared(state.form);
    });
}

test('429 PIN attempts show the retry message and clear submitted digits', async t => {
    const state = mount(t);
    t.mock.method(http.getClient(), 'request', async () => ({ status: 429,
        data: JSON.stringify({ message: 'Too many attempts. Try again in 30 seconds.' }), headers: {} }));
    state.form.pin = state.form.pin_confirmation = '0123';

    await state.savePin();

    assert.match(state.error.value, /Too many attempts/);
    assert.equal(state.pinSet.value, false);
    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assert.deepEqual(state.toasts, []);
    assertPinCleared(state.form);
});

test('network failures leave the PIN unset and show a retry error', async t => {
    const state = mount(t);
    t.mock.method(http.getClient(), 'request', async () => { throw new Error('Offline'); });
    state.form.pin = state.form.pin_confirmation = '0123';

    await state.savePin();

    assert.match(state.error.value, /could not be saved.*Try again/);
    assert.equal(state.pinSet.value, false);
    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assert.deepEqual(state.toasts, []);
    assertPinCleared(state.form);
});

test('browser settings cannot submit a PIN change', async t => {
    const state = mount(t, { native: false });
    state.changeOpen(true);
    assert.equal(state.open.value, false);
    let requests = 0;
    t.mock.method(http.getClient(), 'request', async () => { requests++; });
    state.form.pin = state.form.pin_confirmation = '0123';

    await state.savePin();

    assert.equal(requests, 0);
    assert.equal(state.pinSet.value, false);
    assert.deepEqual(state.toasts, []);
});

test('leaving settings cancels pending saves and clears PIN values and defaults', async t => {
    const state = mount(t);
    let requestSignal;
    t.mock.method(http.getClient(), 'request', request => {
        requestSignal = request.signal;
        return new Promise((resolve, reject) => {
            request.signal.addEventListener('abort', () => reject(Object.assign(new Error('Cancelled'), { name: 'AbortError' })), { once: true });
        });
    });
    state.form.current_pin = '9999';
    state.form.pin = state.form.pin_confirmation = '0123';
    const pending = state.savePin();

    state.scope.stop();
    await pending;

    assert.equal(requestSignal.aborted, true);
    assert.equal(state.pinSet.value, false);
    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assert.deepEqual(state.toasts, []);
    assertPinCleared(state.form);
});

test('leaving settings ignores a late successful PIN response and clears its recovery code', async t => {
    const state = mount(t);
    let requestSignal;
    let finish;
    t.mock.method(http.getClient(), 'request', request => {
        requestSignal = request.signal;
        return new Promise(resolve => { finish = resolve; });
    });
    state.form.pin = state.form.pin_confirmation = '2468';
    const pending = state.savePin();

    state.scope.stop();
    assertPinCleared(state.form);
    finish({ status: 200, data: JSON.stringify({ revision: 12, recovery_code: 'late-private-code' }), headers: {} });
    await pending;

    assert.equal(requestSignal.aborted, true);
    assert.equal(state.pinSet.value, false);
    assert.equal(state.recoveryCode.value, '');
    assert.deepEqual(state.events, []);
    assert.deepEqual(state.toasts, []);
    assertPinCleared(state.form);
});
