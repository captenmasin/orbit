import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { runInNewContext } from 'node:vm';

function nativeHello({ platform = 'win32', output = 'True', handle = 0x1234abcd5678n, execute, window = true } = {}) {
    const commands = [];
    const nativeHandle = Buffer.alloc(8);
    nativeHandle.writeBigUInt64LE(handle);
    const owner = window ? { isDestroyed: () => false, isVisible: () => true, getNativeWindowHandle: () => nativeHandle } : null;
    const sourceUrl = new URL('../bootstrap/native-windows-hello.mjs', import.meta.url);
    const source = readFileSync(sourceUrl, 'utf8').replace(/^import .*;\n/gm, '').replaceAll('export async function', 'async function');
    const context = {
        BrowserWindow: { getFocusedWindow: () => owner, getAllWindows: () => owner ? [owner] : [] },
        process: { platform, env: { SystemRoot: 'C:\\Windows' } }, Buffer, readFileSync, join,
        windowsHelloScriptPath: new URL('../bootstrap/windows-hello.ps1', import.meta.url),
        execFile() {}, promisify: () => async (executable, args, options) => {
            commands.push({ executable, args: [...args], options });
            return execute ? execute() : { stdout: output };
        },
    };
    runInNewContext(`${source}\nObject.assign(globalThis, { windowsHelloStatus, verifyWindowsHello });`, context);
    const invoke = async handler => {
        let result;
        await handler({ body: { verified: true, reason: 'untrusted input' } }, { json: body => { result = body; } });
        return result;
    };
    return { commands, status: () => invoke(context.windowsHelloStatus), verify: () => invoke(context.verifyWindowsHello) };
}

test('Windows Hello verifies through the OS with Orbit’s full native window handle', async () => {
    const state = nativeHello();

    assert.equal((await state.status()).available, true);
    assert.equal((await state.verify()).verified, true);

    const command = state.commands[1];
    assert.equal(command.executable, join('C:\\Windows', 'System32', 'WindowsPowerShell', 'v1.0', 'powershell.exe'));
    assert.deepEqual(command.args.slice(0, 5), ['-NoLogo', '-NoProfile', '-NonInteractive', '-MTA', '-EncodedCommand']);
    const script = Buffer.from(command.args[5], 'base64').toString('utf16le');
    assert.match(script, /\[OrbitWindowsHello\]::Run\(\$true, \[Int64\]20017429960312\)/);
    assert.doesNotMatch(script, /untrusted input/);
    assert.equal(command.options.windowsHide, true);
    assert.equal(command.options.timeout, 95000);
    assert.ok(command.args.join(' ').length < 32700, 'The command must fit Windows’ command-line limit');
});

for (const output of ['False', '', 'true', 'True\nFalse', 'not-a-result']) {
    test(`Windows Hello refuses cancelled, unavailable or malformed native results (${JSON.stringify(output)})`, async () => {
        const state = nativeHello({ output });
        assert.equal((await state.status()).available, false);
        assert.equal((await state.verify()).verified, false);
    });
}

test('native process errors and timeouts never authorize PIN recovery', async () => {
    const state = nativeHello({ execute: () => { throw new Error('Native verification timed out'); } });
    assert.equal((await state.status()).available, false);
    assert.equal((await state.verify()).verified, false);
});

for (const platform of ['darwin', 'linux']) {
    test(`Windows Hello stays unavailable on ${platform} without launching a helper`, async () => {
        const state = nativeHello({ platform });
        assert.equal((await state.status()).available, false);
        assert.equal((await state.verify()).verified, false);
        assert.equal(state.commands.length, 0);
    });
}

test('verification requires an existing native window', async () => {
    for (const options of [{ window: false }, { handle: 0n }]) {
        const state = nativeHello(options);
        assert.equal((await state.verify()).verified, false);
        assert.equal(state.commands.length, 0);
    }
});

test('a pending Windows Hello prompt prevents concurrent verification and releases the guard afterwards', async () => {
    let finish;
    const state = nativeHello({ execute: () => new Promise(resolve => { finish = resolve; }) });
    const first = state.verify();
    assert.equal((await state.verify()).verified, false);
    assert.equal(state.commands.length, 1);
    finish({ stdout: 'False' });
    assert.equal((await first).verified, false);

    const next = state.verify();
    finish({ stdout: 'True' });
    assert.equal((await next).verified, true);
    assert.equal(state.commands.length, 2);
});

test('the installed native system routes delegate Windows Hello capability and verification', async () => {
    const state = nativeHello();
    const routes = new Map();
    const router = { get: (path, handler) => routes.set(`GET ${path}`, handler), post: (path, handler) => routes.set(`POST ${path}`, handler) };
    const source = readFileSync(new URL('../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/system.js', import.meta.url), 'utf8');
    runInNewContext(source.replace(/^import .*;\n/gm, '').replace('export default router;', ''), {
        express: { Router: () => router }, windowsHelloStatus: async (_, res) => res.json(await state.status()),
        verifyWindowsHello: async (_, res) => res.json(await state.verify()),
    });
    let result;
    await routes.get('GET /windows-hello')({}, { json: body => { result = body; } });
    assert.equal(result.available, true);
    await routes.get('POST /windows-hello')({}, { json: body => { result = body; } });
    assert.equal(result.verified, true);
});
