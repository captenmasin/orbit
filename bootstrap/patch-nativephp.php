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
