<?php

use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

// NativePHP's unpacked build otherwise writes caches into the signed .app.
if (getenv('NATIVEPHP_RUNNING') === 'true' && ($nativeData = getenv('NATIVEPHP_USER_DATA_PATH'))) {
    $cache = $nativeData.'/bootstrap/cache';
    if (! is_dir($cache) && ! mkdir($cache, 0700, true) && ! is_dir($cache)) {
        throw new RuntimeException('Unable to create the Orbit cache directory.');
    }
    if (! chmod($cache, 0700)) {
        throw new RuntimeException('Unable to protect the Orbit cache directory.');
    }
    // ponytail: one config file per launch; prune old files when adding cache maintenance.
    $launch = hash('sha256', getenv('NATIVEPHP_SECRET') ?: 'startup');
    $dependencies = hash_file('sha256', __DIR__.'/../composer.lock');
    foreach (['SERVICES' => 'services-'.$dependencies, 'PACKAGES' => 'packages-'.$dependencies, 'CONFIG' => 'config-'.$launch, 'ROUTES' => 'routes-v7', 'EVENTS' => 'events'] as $key => $file) {
        $_ENV['APP_'.$key.'_CACHE'] = $_SERVER['APP_'.$key.'_CACHE'] = $cache.'/'.$file.'.php';
        putenv('APP_'.$key.'_CACHE='.$cache.'/'.$file.'.php');
    }
}

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trimStrings(except: ['description', 'body', 'notes', 'token', 'value', 'entries', 'password', 'password_confirmation', 'pin', 'pin_confirmation', 'paths.*', 'directories.*']);
        $middleware->convertEmptyStringsToNull(except: [
            fn (Request $request): bool => $request->is('projects/*/secrets') || $request->is('projects/*/secrets/*'),
        ]);
        $middleware->web(append: [HandleInertiaRequests::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['token', 'value', 'entries', 'password', 'password_confirmation', 'pin', 'pin_confirmation']);
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
