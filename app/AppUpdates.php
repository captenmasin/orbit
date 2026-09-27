<?php

namespace App;

use Illuminate\Support\Facades\Cache;
use Native\Desktop\Facades\AutoUpdater;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class AppUpdates
{
    public function available(): bool
    {
        if (! config('nativephp-internal.running') || ! config('nativephp.updater.enabled') || app()->environment('local', 'testing')) {
            return false;
        }
        $provider = config('nativephp.updater.default');
        $config = config('nativephp.updater.providers.'.$provider, []);

        return match ($provider) {
            'github' => filled($config['owner'] ?? null) && filled($config['repo'] ?? null),
            's3' => filled($config['bucket'] ?? null) && (filled($config['region'] ?? null) || filled($config['public_url'] ?? null)),
            'spaces' => filled($config['name'] ?? null) && filled($config['region'] ?? null),
            default => false,
        };
    }

    public function state(): array
    {
        if (! $this->available()) {
            return ['status' => 'unavailable', 'message' => 'Update checks are unavailable in development or until a release feed is configured.'];
        }
        $state = Cache::get('orbit-update-state', ['status' => 'idle']);
        if ($state['status'] === 'checking' && ($state['at'] ?? 0) < now()->subMinute()->timestamp) {
            return ['status' => 'error', 'message' => 'The update service did not respond. Check your connection and retry.'];
        }

        return $state;
    }

    public function perform(string $action): array
    {
        abort_unless($this->available(), 409, 'Update checks are unavailable until a release feed is configured in a production app.');
        $state = $this->state();
        abort_if($state['status'] === 'installing', 409, 'Orbit is restarting to install the update.');
        $lock = null;
        if ($action === 'install') {
            abort_unless($state['status'] === 'downloaded', 409, 'Download an update before installing it.');
            $lock = Cache::lock('orbit-workspace-write', 3600);
            abort_unless($lock->get(), 409, 'A workspace operation is still running. Wait for it to finish before restarting.');
            Cache::put('orbit-update-restart-owner', $lock->owner(), 3600);
            Cache::put('orbit-update-state', [...$state, 'status' => 'installing'], 3600);
        }
        try {
            match ($action) {
                'check' => $this->check(),
                'download' => $this->download($state),
                'install' => AutoUpdater::quitAndInstall(),
                default => abort(404),
            };
        } catch (HttpException $exception) {
            $lock?->release();
            if ($lock) {
                Cache::forget('orbit-update-restart-owner');
            }
            throw $exception;
        } catch (Throwable) {
            $lock?->release();
            if ($lock) {
                Cache::forget('orbit-update-restart-owner');
            }
            Cache::put('orbit-update-state', [...$state, 'status' => 'error', 'message' => 'The update service failed. Check your connection and retry.'], 3600);
        }

        return $this->state();
    }

    private function check(): void
    {
        Cache::put('orbit-update-state', ['status' => 'checking', 'at' => now()->timestamp], 3600);
        AutoUpdater::checkForUpdates();
    }

    private function download(array $state): void
    {
        abort_unless(in_array($state['status'], ['available', 'error'], true) && isset($state['version']), 409, 'Check for an available update first.');
        AutoUpdater::downloadUpdate();
    }
}
