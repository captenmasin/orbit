import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

function nativeClipboard(t) {
    t.mock.timers.enable({ apis: ['setTimeout'] });
    let text = '';
    let count = 0;
    let countReads = 0;
    let concurrentCopy;
    let fail = false;
    const listeners = new Map();
    const clipboard = {
        readText: () => text,
        writeText: value => { text = value; count++; },
        clear: () => { text = ''; count++; },
    };
    const source = readFileSync(new URL('../bootstrap/native-secret-clipboard.mjs', import.meta.url), 'utf8')
        .replace(/^import .*;\n/gm, '').replace('export function copySecret', 'function copySecret');
    const context = { clipboard, app: { on: (event, callback) => listeners.set(event, callback) }, process: { platform: 'darwin' }, createHash, execFileSync: () => {
        if (fail) throw new Error('Native pasteboard unavailable.');
        if (++countReads === 2 && concurrentCopy !== undefined) clipboard.writeText(concurrentCopy);
        return String(count);
    }, setTimeout, clearTimeout, result: null };
    runInNewContext(`${source}\nresult = { copySecret, snapshot: () => pending };`, context);
    return {
        clipboard,
        handler: context.result.copySecret,
        copy(seconds = 30, value = 'secret-value') {
            let response;
            context.result.copySecret({ body: { text: value, clearAfterSeconds: seconds } }, {
                json: body => { response = body; }, sendStatus: status => { response = status; },
            });
            return response;
        },
        snapshot: context.result.snapshot,
        fail: () => { fail = true; },
        copyDuringSnapshot: value => { concurrentCopy = value; },
        quit: () => listeners.get('before-quit')(),
    };
}

for (const seconds of [30, 60]) {
    test(`native clearing removes an unchanged copy after ${seconds} seconds and stores only a digest and count`, t => {
        const state = nativeClipboard(t);
        assert.equal(state.copy(seconds).copied, true);
        assert.deepEqual(Object.keys(state.snapshot()).sort(), ['count', 'digest']);
        assert.doesNotMatch(JSON.stringify(state.snapshot()), /secret-value/);
        t.mock.timers.tick(seconds * 1000 - 1);
        assert.equal(state.clipboard.readText(), 'secret-value');
        t.mock.timers.tick(1);
        assert.equal(state.clipboard.readText(), '');
    });
}

for (const later of ['later-text', 'secret-value']) {
    test(`native clearing preserves a later clipboard copy containing ${later}`, t => {
        const state = nativeClipboard(t);
        state.copy();
        state.clipboard.writeText(later);
        t.mock.timers.tick(30000);
        assert.equal(state.clipboard.readText(), later);
    });
}

test('a concurrent clipboard write during the native snapshot is preserved', t => {
    const state = nativeClipboard(t);
    state.copyDuringSnapshot('concurrent-copy');

    assert.equal(state.copy().copied, true);
    assert.equal(state.snapshot().digest, createHash('sha256').update('secret-value').digest('hex'));
    t.mock.timers.tick(30000);

    assert.equal(state.clipboard.readText(), 'concurrent-copy');
});

test('a newer secret copy receives its own duration and Off cancels previous clearing', t => {
    const state = nativeClipboard(t);
    state.copy();
    t.mock.timers.tick(29000);
    state.copy(60);
    t.mock.timers.tick(30000);
    assert.equal(state.clipboard.readText(), 'secret-value');
    state.copy(0);
    t.mock.timers.tick(60000);
    assert.equal(state.clipboard.readText(), 'secret-value');
    assert.equal(state.snapshot(), undefined);
});

test('Quit clears only the unchanged pending secret copy', t => {
    const state = nativeClipboard(t);
    state.copy();
    state.quit();
    assert.equal(state.clipboard.readText(), '');
    state.copy();
    state.clipboard.writeText('later-text');
    state.quit();
    assert.equal(state.clipboard.readText(), 'later-text');
});

test('unavailable native clipboard tracking fails before replacing the clipboard', t => {
    const state = nativeClipboard(t);
    state.clipboard.writeText('existing-copy');
    state.fail();
    assert.equal(state.copy(), 500);
    assert.equal(state.clipboard.readText(), 'existing-copy');
});

test('the native route rejects invalid clipboard policies or non-text content', t => {
    const state = nativeClipboard(t);
    state.clipboard.writeText('existing-copy');
    assert.equal(state.copy(15), 422);
    assert.equal(state.copy('30'), 422);
    assert.equal(state.copy(30, null), 422);
    assert.equal(state.clipboard.readText(), 'existing-copy');
});

test('the installed native clipboard route delegates secret copying to the timer handler', t => {
    const state = nativeClipboard(t);
    const routes = new Map();
    const router = { get() {}, delete() {}, post: (path, handler) => routes.set(path, handler) };
    const source = readFileSync(new URL('../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/clipboard.js', import.meta.url), 'utf8');
    runInNewContext(source.replace(/^import .*;\n/gm, '').replace('export default router;', ''), {
        copySecret: state.handler, express: { Router: () => router },
    });
    let result;
    routes.get('/secret')({ body: { text: 'native-route-secret', clearAfterSeconds: 30 } }, { json: body => { result = body; } });
    assert.equal(result.copied, true);
    assert.equal(state.clipboard.readText(), 'native-route-secret');
    t.mock.timers.tick(30000);
    assert.equal(state.clipboard.readText(), '');
});
