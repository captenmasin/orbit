<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class DebugTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith([false])]
    #[TestWith([true])]
    public function test_debug_page_exposes_desktop_availability(bool $native): void
    {
        config(['nativephp-internal.running' => $native]);

        $this->get('/debug')->assertOk()->assertInertia(fn (Assert $page): Assert => $page
            ->component('Debug')->where('native', $native));
    }

    public function test_notification_is_sent_through_the_native_bridge(): void
    {
        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:60001/api']);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:60001/api/notification' => Http::response(['reference' => 'debug-notification'])]);

        $this->postJson('/debug/notification')->assertNoContent();

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === 'http://127.0.0.1:60001/api/notification'
            && $request['title'] === 'Orbit debug notification'
            && $request['body'] === 'This is a test notification from the Debug page.');
    }

    public function test_browser_notification_requests_return_422_without_calling_the_bridge(): void
    {
        config(['nativephp-internal.running' => false]);
        Http::preventStrayRequests();
        Http::fake();

        $this->postJson('/debug/notification')->assertUnprocessable()
            ->assertJsonPath('message', 'Open Orbit desktop to test native notifications.');

        Http::assertNothingSent();
    }

    public function test_failed_native_notification_requests_return_502(): void
    {
        config(['nativephp-internal.running' => true, 'nativephp-internal.api_url' => 'http://127.0.0.1:60001/api']);
        Http::preventStrayRequests();
        Http::fake(['http://127.0.0.1:60001/api/notification' => Http::response([], 503)]);

        $this->postJson('/debug/notification')->assertStatus(502)
            ->assertJsonPath('message', 'The native notification could not be sent.');
    }
}
