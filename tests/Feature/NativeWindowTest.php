<?php

namespace Tests\Feature;

use App\Providers\NativeAppServiceProvider;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NativeWindowTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_main_window_hides_macos_chrome_and_keeps_native_window_controls(): void
    {
        config(['nativephp-internal.api_url' => 'http://native.test/api']);
        Http::preventStrayRequests();
        Http::fake([
            'http://native.test/api/system/theme' => Http::response(['result' => 'system']),
            'http://native.test/api/window/open' => Http::response(),
        ]);

        app(NativeAppServiceProvider::class)->boot();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://native.test/api/window/open'
            && $request['titleBarStyle'] === (PHP_OS_FAMILY === 'Darwin' ? 'hiddenInset' : 'default')
            && $request['frame'] === true
            && $request['windowButtonVisibility'] === true
            && $request['movable'] === true
            && $request['resizable'] === true
            && $request['minimizable'] === true
            && $request['maximizable'] === true
            && $request['closable'] === true
            && $request['fullscreenable'] === true);
    }
}
