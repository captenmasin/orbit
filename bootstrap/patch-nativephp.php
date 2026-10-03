<?php

// NativePHP 2.3.1 retries Quit inside its cancelled event when there are no child jobs.
// Defer the retry until Electron has finished handling that event. Remove when fixed upstream.
$directory = __DIR__.'/../vendor/nativephp/desktop/resources/electron/electron-plugin';

// Electron's build sources are deliberately excluded from the packaged Laravel app.
if (! is_file($directory.'/dist/index.js')) {
    return;
}

$original = "            app.quit();\n        }";
$replacement = "            setImmediate(() => app.quit());\n        }";

foreach (['src/index.ts', 'dist/index.js'] as $file) {
    $path = $directory.'/'.$file;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read NativePHP quit handler: '.$path);
    }
    if (str_contains($source, $replacement)) {
        continue;
    }

    $patched = str_replace($original, $replacement, $source, $count);
    if ($count !== 1) {
        throw new RuntimeException('NativePHP quit handler changed; review bootstrap/patch-nativephp.php.');
    }
    if (file_put_contents($path, $patched) === false) {
        throw new RuntimeException('Unable to patch NativePHP quit handler: '.$path);
    }
}

// NativePHP's clipboard API has no conditional clearing timer. Keep it in Electron
// so a closed renderer cannot cancel it and no secret enters a persisted job.
foreach (['src/server/api/clipboard.ts', 'dist/server/api/clipboard.js'] as $file) {
    $path = $directory.'/'.$file;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read NativePHP clipboard handler: '.$path);
    }
    $replacement = "import { copySecret } from './orbit-secret-clipboard.mjs';\n";
    if (! str_contains($source, $replacement)) {
        $source = $replacement.str_replace('export default router;', "router.post('/secret', copySecret);\n\nexport default router;", $source, $count);
        if ($count !== 1 || file_put_contents($path, $source) === false) {
            throw new RuntimeException('NativePHP clipboard handler changed; review bootstrap/patch-nativephp.php.');
        }
    }
    if (! copy(__DIR__.'/native-secret-clipboard.mjs', dirname($path).'/orbit-secret-clipboard.mjs')) {
        throw new RuntimeException('Unable to install the native secret clipboard handler.');
    }
}

// Attach Windows Hello verification to Orbit's native window, through the authenticated native API.
foreach (['src/server/api/system.ts', 'dist/server/api/system.js'] as $file) {
    $path = $directory.'/'.$file;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read NativePHP system handler: '.$path);
    }
    $import = "import { windowsHelloStatus, verifyWindowsHello } from './orbit-windows-hello.mjs';\n";
    if (! str_contains($source, $import)) {
        $source = $import.str_replace('export default router;', "router.get('/windows-hello', windowsHelloStatus);\nrouter.post('/windows-hello', verifyWindowsHello);\n\nexport default router;", $source, $count);
        if ($count !== 1 || file_put_contents($path, $source) === false) {
            throw new RuntimeException('NativePHP system handler changed; review bootstrap/patch-nativephp.php.');
        }
    }
    if (! copy(__DIR__.'/native-windows-hello.mjs', dirname($path).'/orbit-windows-hello.mjs')
        || ! copy(__DIR__.'/windows-hello.ps1', dirname($path).'/windows-hello.ps1')) {
        throw new RuntimeException('Unable to install the Windows Hello handler.');
    }
}

// Orbit requires an explicit download and restart, and offline checks must not
// become unhandled Electron promise rejections.
foreach (['src/index.ts', 'dist/index.js', 'src/server/api/autoUpdater.ts', 'dist/server/api/autoUpdater.js'] as $file) {
    $path = $directory.'/'.$file;
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read NativePHP updater: '.$path);
    }
    if (! str_contains($source, 'const { autoUpdater } = electronUpdater;')) {
        throw new RuntimeException('NativePHP updater changed; review bootstrap/patch-nativephp.php.');
    }
    $source = str_replace('const { autoUpdater } = electronUpdater;', "const { autoUpdater } = electronUpdater;\nautoUpdater.autoDownload = false;\nautoUpdater.autoInstallOnAppQuit = false;", $source);
    // Repeated composer/build hooks must leave exactly one settings block.
    $source = preg_replace('/(autoUpdater\.autoDownload = false;\nautoUpdater\.autoInstallOnAppQuit = false;\n?)+/', "autoUpdater.autoDownload = false;\nautoUpdater.autoInstallOnAppQuit = false;\n", $source);
    $source = str_replace('autoUpdater.checkForUpdatesAndNotify();', 'autoUpdater.checkForUpdates().catch(() => {});', $source);
    $source = str_replace('autoUpdater.checkForUpdates();', 'autoUpdater.checkForUpdates().catch(() => {});', $source);
    $source = str_replace('autoUpdater.downloadUpdate();', 'autoUpdater.downloadUpdate().catch(() => {});', $source);
    if (file_put_contents($path, $source) === false) {
        throw new RuntimeException('Unable to patch the NativePHP updater: '.$path);
    }
}

// NativePHP's updater facade otherwise ignores failed native HTTP responses.
$path = __DIR__.'/../vendor/nativephp/desktop/src/AutoUpdater.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read the native updater facade.');
}
foreach (['check-for-updates', 'download-update', 'quit-and-install'] as $endpoint) {
    $call = "\$this->client->post('auto-updater/".$endpoint."')";
    if (! str_contains($source, $call)) {
        throw new RuntimeException('NativePHP updater facade changed; review bootstrap/patch-nativephp.php.');
    }
    $source = str_replace($call.';', $call.'->throw();', $source);
}
if (file_put_contents($path, $source) === false) {
    throw new RuntimeException('Unable to patch the native updater facade.');
}

// Confirm login-item changes against an actual native response, including errors.
$path = __DIR__.'/../vendor/nativephp/desktop/src/App.php';
$source = file_get_contents($path);
if ($source === false) {
    throw new RuntimeException('Unable to read the native app facade.');
}
$source = str_replace("\$this->client->get('app/open-at-login')->json('open')", "\$this->client->get('app/open-at-login')->throw()->json('open')", $source);
$source = str_replace("\$this->client->post('app/open-at-login', [\n            'open' => \$open,\n        ]);", "\$this->client->post('app/open-at-login', [\n            'open' => \$open,\n        ])->throw();", $source);
if (! str_contains($source, "get('app/open-at-login')->throw()") || ! str_contains($source, "'open' => \$open,\n        ])->throw();")) {
    throw new RuntimeException('NativePHP login-item facade changed; review bootstrap/patch-nativephp.php.');
}
if (file_put_contents($path, $source) === false) {
    throw new RuntimeException('Unable to patch the native login-item facade.');
}

// NativePHP 2.3.1 only copies flat icons and overlays icon.png in development.
// Let macOS render the Icon Composer catalog, as electron-builder does in releases.
$iconCopy = "@copy(public_path('icon.icns'), ElectronServiceProvider::electronPath('build/icon.icns'));";
$macosIcon = <<<'PHP'

        if (! (new \Illuminate\Filesystem\Filesystem)->copyDirectory(public_path('icon.icon'), ElectronServiceProvider::electronPath('build/icon.icon'))) {
            throw new \RuntimeException('Unable to copy the Icon Composer asset.');
        }

        if (PHP_OS_FAMILY === 'Darwin') {
            $electronApp = ElectronServiceProvider::electronPath('node_modules/electron/dist/Electron.app');
            if (is_dir($electronApp)) {
                $resources = $electronApp.'/Contents/Resources';
                if (! copy(public_path('icon.icns'), $resources.'/electron.icns')
                    || ! copy(resource_path('macos/Assets.car'), $resources.'/Assets.car')) {
                    throw new \RuntimeException('Unable to install the macOS app icon.');
                }
                foreach (['CFBundleIconFile' => 'electron.icns', 'CFBundleIconName' => 'Icon'] as $key => $value) {
                    exec('plutil -replace '.$key.' -string '.escapeshellarg($value).' '.escapeshellarg($electronApp.'/Contents/Info.plist'), $output, $status);
                    if ($status !== 0) {
                        throw new \RuntimeException('Unable to configure the macOS app icon.');
                    }
                }
                touch($electronApp);
            }
        }
PHP;

foreach ([
    $directory.'/src/index.ts' => ['app.dock.setIcon(state.icon);', '// The app bundle supplies the Icon Composer catalog.'],
    $directory.'/dist/index.js' => ['app.dock.setIcon(state.icon);', '// The app bundle supplies the Icon Composer catalog.'],
    dirname($directory).'/electron-builder.mjs' => ['mac: {', "mac: {\n        icon: 'icon.icon',"],
    __DIR__.'/../vendor/nativephp/desktop/src/Drivers/Electron/Traits/InstallsAppIcon.php' => [$iconCopy, $iconCopy."\n".$macosIcon],
] as $path => [$original, $replacement]) {
    $source = file_get_contents($path);
    if ($source === false) {
        throw new RuntimeException('Unable to read NativePHP icon configuration: '.$path);
    }
    if (str_contains($source, $replacement)) {
        continue;
    }
    $patched = str_replace($original, $replacement, $source, $count);
    if ($count !== 1 || file_put_contents($path, $patched) === false) {
        throw new RuntimeException('NativePHP icon configuration changed; review bootstrap/patch-nativephp.php.');
    }
}
