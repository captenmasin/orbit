<?php

namespace Tests\Feature;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\AutoUpdater;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class NativeSettingsBridgeTest extends TestCase
{
    public function test_failed_login_item_reads_cannot_report_disabled_as_a_successful_read(): void
    {
        $this->failedNativeResponse();
        $this->expectException(RequestException::class);

        App::openAtLogin();
    }

    public function test_failed_login_item_changes_cannot_report_success(): void
    {
        $this->failedNativeResponse();
        $this->expectException(RequestException::class);

        App::openAtLogin(true);
    }

    #[TestWith(['checkForUpdates'])]
    #[TestWith(['downloadUpdate'])]
    #[TestWith(['quitAndInstall'])]
    public function test_native_updater_transport_errors_reach_the_application(string $operation): void
    {
        $this->failedNativeResponse();
        $this->expectException(RequestException::class);

        AutoUpdater::{$operation}();
    }

    private function failedNativeResponse(): void
    {
        config(['nativephp-internal.api_url' => 'http://127.0.0.1:60001/api']);
        Http::preventStrayRequests();
        Http::fake(fn () => Http::response([], 503));
    }
}
