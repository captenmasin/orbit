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
            'http://native.test/api/menu' => Http::response(),
            'http://native.test/api/context' => Http::response(),
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

    public function test_workspace_menus_register_navigation_actions_and_standard_native_text_context_menu(): void
    {
        config(['nativephp-internal.api_url' => 'http://native.test/api']);
        Http::preventStrayRequests();
        Http::fake([
            'http://native.test/api/system/theme' => Http::response(['result' => 'system']),
            'http://native.test/api/menu' => Http::response(),
            'http://native.test/api/context' => Http::response(),
            'http://native.test/api/window/open' => Http::response(),
        ]);

        app(NativeAppServiceProvider::class)->boot();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'http://native.test/api/context'
            && $request->method() === 'POST'
            && $request['entries'] === []);

        $items = Http::recorded(fn (Request $request): bool => $request->url() === 'http://native.test/api/menu')->sole()[0]['items'];
        $this->assertSame(['appMenu', 'fileMenu', 'editMenu', 'viewMenu', 'windowMenu'], array_column($items, 'role'));
        $menus = collect($items)->keyBy('label');
        $workspace = $menus['Workspace']['submenu'];
        $this->assertSame(['new-project', 'dashboard', 'search', 'settings', 'backups'], array_column($workspace, 'id'));
        $this->assertSame(['CmdOrCtrl+N', 'CmdOrCtrl+Shift+H', 'CmdOrCtrl+K', 'CmdOrCtrl+,'], array_column($workspace, 'accelerator'));
        $this->assertSame(['connections', 'tools'], array_column($menus['Tools']['submenu'], 'id'));
        $this->assertSame(['about'], array_column($menus['Help']['submenu'], 'id'));
    }
}
