<?php

namespace Tests\Feature;

use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\AutoUpdater\DownloadProgress;
use Native\Desktop\Events\AutoUpdater\Error;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;
use Native\Desktop\Facades\AutoUpdater;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Tests\TestCase;

class AppUpdateTest extends TestCase
{
    use RefreshDatabase;

    #[TestWith(['testing', true, true])]
    #[TestWith(['production', false, true])]
    #[TestWith(['production', true, false])]
    public function test_updates_are_unavailable_without_a_production_desktop_feed(string $environment, bool $native, bool $feed): void
    {
        $this->productionFeed();
        $this->app->instance('env', $environment);
        config(['nativephp-internal.running' => $native, 'nativephp.updater.providers.github.repo' => $feed ? 'orbit' : null]);
        AutoUpdater::shouldReceive('checkForUpdates')->never();

        $this->getJson('/settings/updates')->assertOk()->assertJsonPath('status', 'unavailable');
        $this->postJson('/settings/updates/check')->assertConflict();
    }

    public function test_update_checks_wait_for_a_native_result_and_report_a_timeout(): void
    {
        $this->freezeTime();
        $this->productionFeed();
        AutoUpdater::shouldReceive('checkForUpdates')->once()->andReturnSelf();

        $this->postJson('/settings/updates/check')->assertOk()->assertJsonPath('status', 'checking');

        $this->travel(61)->seconds();
        $this->getJson('/settings/updates')->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('message', 'The update service did not respond. Check your connection and retry.');
        $this->get('/')->assertOk();
    }

    public function test_native_update_events_report_available_download_progress_and_downloaded_state(): void
    {
        $this->productionFeed();
        config(['broadcasting.default' => 'null']);

        event(new UpdateAvailable('0.7.0', [], '2026-09-27'));
        $this->getJson('/settings/updates')->assertOk()->assertExactJson(['status' => 'available', 'version' => '0.7.0']);
        event(new DownloadProgress(100, 25, 25, 25.0, 100));
        $this->getJson('/settings/updates')->assertOk()->assertJsonPath('status', 'downloading')->assertJsonPath('percent', 25)->assertJsonPath('version', '0.7.0');
        event(new UpdateDownloaded('/private/update.dmg', '0.7.0', [], '2026-09-27'));
        $this->getJson('/settings/updates')->assertOk()->assertExactJson(['status' => 'downloaded', 'version' => '0.7.0']);
        event(new UpdateNotAvailable('0.7.0', [], '2026-09-27'));
        $this->getJson('/settings/updates')->assertOk()->assertExactJson(['status' => 'current', 'version' => '0.7.0']);
    }

    public function test_failed_native_checks_report_a_safe_error_and_allow_retry(): void
    {
        $this->productionFeed();
        AutoUpdater::shouldReceive('checkForUpdates')->once()->andThrow(new RuntimeException('Private feed credentials'));
        AutoUpdater::shouldReceive('checkForUpdates')->once()->andReturnSelf();

        $this->postJson('/settings/updates/check')->assertOk()->assertJsonPath('status', 'error')
            ->assertJsonPath('message', 'The update service failed. Check your connection and retry.');
        $this->postJson('/settings/updates/check')->assertOk()->assertJsonPath('status', 'checking');
    }

    public function test_download_requires_an_available_update(): void
    {
        $this->productionFeed();
        AutoUpdater::shouldReceive('downloadUpdate')->never();

        $this->postJson('/settings/updates/download')->assertConflict();
    }

    public function test_a_failed_download_preserves_the_available_version_for_retry(): void
    {
        $this->productionFeed();
        Cache::put('orbit-update-state', ['status' => 'available', 'version' => '0.7.0'], 3600);
        AutoUpdater::shouldReceive('downloadUpdate')->once()->andThrow(new RuntimeException('Private feed credentials'));
        AutoUpdater::shouldReceive('downloadUpdate')->once()->andReturnSelf();

        $this->postJson('/settings/updates/download')->assertOk()->assertJsonPath('status', 'error')->assertJsonPath('version', '0.7.0');
        $this->postJson('/settings/updates/download')->assertOk();
    }

    public function test_installation_requires_confirmation_a_download_and_an_idle_workspace(): void
    {
        $this->productionFeed();
        AutoUpdater::shouldReceive('quitAndInstall')->never();

        $this->postJson('/settings/updates/install')->assertUnprocessable()->assertJsonValidationErrors('confirmed');
        $this->postJson('/settings/updates/install', ['confirmed' => true])->assertConflict();
        Cache::put('orbit-update-state', ['status' => 'downloaded', 'version' => '0.7.0'], 3600);
        $operation = Cache::lock('orbit-workspace-write', 3600);
        $this->assertTrue($operation->get());
        try {
            $this->postJson('/settings/updates/install', ['confirmed' => true])->assertConflict();
        } finally {
            $operation->release();
        }
    }

    public function test_installation_invokes_native_restart_and_prevents_subsequent_writes(): void
    {
        $this->productionFeed();
        Cache::put('orbit-update-state', ['status' => 'downloaded', 'version' => '0.7.0'], 3600);
        AutoUpdater::shouldReceive('quitAndInstall')->once()->andReturnSelf();

        $this->postJson('/settings/updates/install', ['confirmed' => true])->assertOk();
        $this->postJson('/projects', ['name' => 'Too late to save', 'status' => 'Idea'])->assertConflict();

        $this->assertDatabaseCount('projects', 0);
    }

    public function test_failed_native_restart_releases_the_workspace_for_future_writes(): void
    {
        $this->productionFeed();
        Cache::put('orbit-update-state', ['status' => 'downloaded', 'version' => '0.7.0'], 3600);
        AutoUpdater::shouldReceive('quitAndInstall')->once()->andThrow(new RuntimeException('Private native error'));

        $this->postJson('/settings/updates/install', ['confirmed' => true])->assertOk()->assertJsonPath('status', 'error');
        $this->postJson('/projects', ['name' => 'Retry after failed restart', 'status' => 'Idea'])->assertRedirect();

        $this->assertDatabaseHas('projects', ['name' => 'Retry after failed restart']);
    }

    public function test_a_write_operation_holds_the_restart_lock_and_releases_it_after_completion(): void
    {
        $this->productionFeed();
        $restartBlocked = false;
        Project::creating(function () use (&$restartBlocked): void {
            $restart = Cache::lock('orbit-workspace-write', 60);
            $restartBlocked = ! $restart->get();
            if (! $restartBlocked) {
                $restart->release();
            }
        });

        $this->postJson('/projects', ['name' => 'Saved before restart', 'status' => 'Idea'])->assertRedirect();

        $this->assertTrue($restartBlocked);
        $this->assertDatabaseHas('projects', ['name' => 'Saved before restart']);
        $restart = Cache::lock('orbit-workspace-write', 60);
        $this->assertTrue($restart->get());
        $restart->release();
    }

    public function test_delayed_native_install_failure_releases_the_restart_lease(): void
    {
        $this->productionFeed();
        config(['broadcasting.default' => 'null']);
        Cache::put('orbit-update-state', ['status' => 'downloaded', 'version' => '0.7.0'], 3600);
        AutoUpdater::shouldReceive('quitAndInstall')->once()->andReturnSelf();
        $this->postJson('/settings/updates/install', ['confirmed' => true])->assertOk()->assertJsonPath('status', 'installing');
        $this->postJson('/settings/updates/install', ['confirmed' => true])->assertConflict();
        $this->travel(61)->seconds();
        $this->postJson('/projects', ['name' => 'Still restarting', 'status' => 'Idea'])->assertConflict();
        event(new Error('installation', 'Private native details'));
        $this->getJson('/settings/updates')->assertJsonPath('status', 'error')->assertDontSee('Private native details');
        $this->postJson('/projects', ['name' => 'Save after failed restart', 'status' => 'Idea'])->assertRedirect();
        $this->assertDatabaseHas('projects', ['name' => 'Save after failed restart']);
    }

    private function productionFeed(): void
    {
        $this->app->instance('env', 'production');
        $this->withSession(['_token' => 'app-update-test-token'])->withHeader('X-CSRF-TOKEN', 'app-update-test-token');
        config([
            'nativephp-internal.running' => true,
            'nativephp.updater.enabled' => true,
            'nativephp.updater.default' => 'github',
            'nativephp.updater.providers.github.owner' => 'orbit',
            'nativephp.updater.providers.github.repo' => 'orbit',
        ]);
    }
}
