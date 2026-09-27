import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';

function nativeUpdater(overrides = {}) {
    const handlers = {};
    const updater = { autoDownload: true, autoInstallOnAppQuit: true, addListener() {}, checkForUpdates: () => Promise.resolve(), downloadUpdate: () => Promise.resolve(), quitAndInstall() {}, ...overrides };
    const source = readFileSync(new URL('../vendor/nativephp/desktop/resources/electron/electron-plugin/dist/server/api/autoUpdater.js', import.meta.url), 'utf8')
        .replace(/^import .*;\n/gm, '').replace('export default router;', '');
    runInNewContext(source, { electronUpdater: { autoUpdater: updater }, express: { Router: () => ({ post: (path, handler) => { handlers[path] = handler; } }) }, notifyLaravel() {} });
    return { updater, handlers };
}

test('native updater waits for explicit download and install and handles failed requests', async () => {
    const { updater, handlers } = nativeUpdater({ checkForUpdates: () => Promise.reject(new Error('offline')), downloadUpdate: () => Promise.reject(new Error('offline')) });
    assert.equal(updater.autoDownload, false);
    assert.equal(updater.autoInstallOnAppQuit, false);
    const statuses = [];
    for (const path of ['/check-for-updates', '/download-update']) handlers[path]({}, { sendStatus: status => statuses.push(status) });
    await new Promise(resolve => setImmediate(resolve));
    assert.deepEqual(statuses, [200, 200]);
});

test('native updater installation is invoked only by the install endpoint', () => {
    let installs = 0;
    const { handlers } = nativeUpdater({ quitAndInstall: () => { installs++; } });
    handlers['/check-for-updates']({}, { sendStatus() {} });
    handlers['/download-update']({}, { sendStatus() {} });
    assert.equal(installs, 0);
    handlers['/quit-and-install']({}, { sendStatus() {} });
    assert.equal(installs, 1);
});
