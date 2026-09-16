import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { setImmediate as nextTurn } from 'node:timers/promises';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

test('NativePHP finishes the cancelled quit event before retrying without child jobs', async () => {
    // Exercise the installed handler without launching Electron or killing real processes.
    const source = readFileSync(new URL('../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/index.js', import.meta.url), 'utf8');
    const context = {
        electronUpdater: { autoUpdater: {} },
        process: { platform: 'darwin' },
        state: { processes: {} },
        stopAllProcesses() {},
        killScheduler() {},
        setTimeout,
        setImmediate,
    };
    runInNewContext(source.replace(/^import .*;$/gm, '').replace('export default new NativePHP();', 'globalThis.native = new NativePHP();'), context);

    const handlers = new Map();
    let quitCalls = 0;
    let cancelled = false;
    context.native.addEventListeners({
        on: (event, handler) => handlers.set(event, handler),
        quit: () => quitCalls++,
    });

    await handlers.get('before-quit')({ preventDefault: () => { cancelled = true; } });
    assert.equal(cancelled, true);
    assert.equal(quitCalls, 0, 'A nested quit is cancelled by the original Electron event');
    await nextTurn();
    assert.equal(quitCalls, 1);

    // The second quit must be allowed through, without another cancellation or retry.
    await handlers.get('before-quit')({ preventDefault: () => assert.fail('Quit was cancelled twice') });
    await nextTurn();
    assert.equal(quitCalls, 1);
});
