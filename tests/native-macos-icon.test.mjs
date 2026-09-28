import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { cpSync, mkdirSync, mkdtempSync, readFileSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';

const root = fileURLToPath(new URL('../', import.meta.url));
const electron = join(root, 'vendor/nativephp/desktop/resources/electron');

test('macOS packaging selects the Icon Composer source', () => {
    const source = readFileSync(join(electron, 'electron-builder.mjs'), 'utf8');
    const context = { join, process: { env: { APP_PATH: root }, argv: [] } };

    runInNewContext(source.replace(/^import .*;$/gm, '').replace('export default {', 'globalThis.config = {'), context);

    assert.equal(context.config.mac.icon, 'icon.icon');
});

test('development preserves the bundle icon instead of overlaying a flat Dock image', () => {
    const source = readFileSync(join(electron, 'electron-plugin/dist/index.js'), 'utf8');
    let overlays = 0;
    const context = {
        electronUpdater: { autoUpdater: {} },
        process: { platform: 'darwin', env: { NODE_ENV: 'development' } },
        state: { icon: 'icon.png' },
        app: { dock: { setIcon: () => overlays++ } },
    };
    runInNewContext(source.replace(/^import .*;$/gm, '').replace('export default new NativePHP();', 'globalThis.native = new NativePHP();'), context);

    context.native.setDockIcon();

    assert.equal(overlays, 0);
});

test('NativePHP stages the Composer source and configures the development bundle', { skip: process.platform !== 'darwin' }, () => {
    const directory = mkdtempSync(join(tmpdir(), 'orbit-icon-test-'));
    try {
        mkdirSync(join(directory, 'public'));
        mkdirSync(join(directory, 'resources/macos'), { recursive: true });
        mkdirSync(join(directory, 'electron/build'), { recursive: true });
        mkdirSync(join(directory, 'build'));
        cpSync(join(root, 'public/icon.icon'), join(directory, 'public/icon.icon'), { recursive: true });
        cpSync(join(root, 'public/icon.icns'), join(directory, 'public/icon.icns'));
        cpSync(join(root, 'resources/macos/Assets.car'), join(directory, 'resources/macos/Assets.car'));
        const bundle = join(directory, 'electron/node_modules/electron/dist/Electron.app');
        mkdirSync(join(bundle, 'Contents/Resources'), { recursive: true });
        const plist = join(bundle, 'Contents/Info.plist');
        writeFileSync(plist, '<?xml version="1.0"?><plist version="1.0"><dict><key>CFBundleName</key><string>Orbit</string><key>CFBundleIconFile</key><string>electron.icns</string></dict></plist>');
        // Exercise the installed trait in isolation, without touching the real Electron bundle.
        const php = String.raw`
            namespace Native\Desktop\Drivers\Electron {
                class ElectronServiceProvider {
                    public static function electronPath(string $path = ''): string { return $GLOBALS['iconTestDirectory'].'/electron/'.$path; }
                    public static function buildPath(string $path = ''): string { return $GLOBALS['iconTestDirectory'].'/build/'.$path; }
                }
            }
            namespace {
                $iconTestDirectory = $argv[1];
                function public_path(string $path = ''): string { return $GLOBALS['iconTestDirectory'].'/public/'.$path; }
                function resource_path(string $path = ''): string { return $GLOBALS['iconTestDirectory'].'/resources/'.$path; }
                require $argv[2].'/vendor/autoload.php';
                require $argv[2].'/vendor/nativephp/desktop/src/Drivers/Electron/Traits/InstallsAppIcon.php';
                (new class { use \Native\Desktop\Drivers\Electron\Traits\InstallsAppIcon; })->installIcon();
            }
        `;

        execFileSync('php', ['-r', php, directory, root]);

        assert.deepEqual(readFileSync(join(directory, 'electron/build/icon.icon/icon.json')), readFileSync(join(root, 'public/icon.icon/icon.json')));
        assert.deepEqual(readFileSync(join(directory, 'electron/build/icon.icon/Assets/Group 1.svg')), readFileSync(join(root, 'public/icon.icon/Assets/Group 1.svg')));
        assert.deepEqual(readFileSync(join(directory, 'electron/build/icon.icns')), readFileSync(join(root, 'public/icon.icns')));
        assert.deepEqual(readFileSync(join(bundle, 'Contents/Resources/electron.icns')), readFileSync(join(root, 'public/icon.icns')));
        assert.deepEqual(readFileSync(join(bundle, 'Contents/Resources/Assets.car')), readFileSync(join(root, 'resources/macos/Assets.car')));
        const metadata = JSON.parse(execFileSync('plutil', ['-convert', 'json', '-o', '-', plist], { encoding: 'utf8' }));
        assert.equal(metadata.CFBundleIconName, 'Icon');
        assert.equal(metadata.CFBundleIconFile, 'electron.icns');
        assert.equal(metadata.CFBundleName, 'Orbit');

        rmSync(join(directory, 'resources/macos/Assets.car'));
        assert.throws(() => execFileSync('php', ['-r', php, directory, root], { stdio: 'pipe' }), error => {
            assert.match(String(error.stdout) + String(error.stderr), /Unable to install the macOS app icon/);
            return true;
        });
    } finally {
        rmSync(directory, { recursive: true, force: true });
    }
});
