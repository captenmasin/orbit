<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Cache;
use Native\Desktop\Events\AutoUpdater\CheckingForUpdate;
use Native\Desktop\Events\AutoUpdater\DownloadProgress;
use Native\Desktop\Events\AutoUpdater\Error;
use Native\Desktop\Events\AutoUpdater\UpdateAvailable;
use Native\Desktop\Events\AutoUpdater\UpdateDownloaded;
use Native\Desktop\Events\AutoUpdater\UpdateNotAvailable;

class RememberAppUpdate
{
    public function handle(CheckingForUpdate|DownloadProgress|Error|UpdateAvailable|UpdateDownloaded|UpdateNotAvailable $event): void
    {
        if ($event instanceof Error && ($owner = Cache::pull('orbit-update-restart-owner'))) {
            Cache::restoreLock('orbit-workspace-write', $owner)->release();
        }
        $state = match (true) {
            $event instanceof CheckingForUpdate => ['status' => 'checking', 'at' => now()->timestamp],
            $event instanceof DownloadProgress => [...Cache::get('orbit-update-state', []), 'status' => 'downloading', 'percent' => max(0, min(100, $event->percent))],
            $event instanceof Error => [...Cache::get('orbit-update-state', []), 'status' => 'error', 'message' => 'The update service failed. Check your connection and retry.'],
            $event instanceof UpdateAvailable => ['status' => 'available', 'version' => $event->version],
            $event instanceof UpdateDownloaded => ['status' => 'downloaded', 'version' => $event->version],
            $event instanceof UpdateNotAvailable => ['status' => 'current', 'version' => $event->version],
        };
        Cache::put('orbit-update-state', $state, 3600);
    }
}
