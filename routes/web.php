<?php

use App\Http\Controllers\AppUpdateController;
use App\Http\Controllers\BackupController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DebugController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\ProjectAssetController;
use App\Http\Controllers\ProjectDocumentController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\ScratchpadAiController;
use App\Http\Controllers\SearchController;
use App\Http\Controllers\SecretController;
use App\Http\Controllers\SecretVaultController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\ToolsController;
use App\Http\Controllers\WorkspaceController;
use App\Http\Middleware\RequireSecretUnlock;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('workspace');
Route::get('/search', [SearchController::class, 'index'])->name('search');

Route::prefix('debug')->name('debug.')->controller(DebugController::class)->group(function (): void {
    Route::get('/', 'index')->name('index');
    Route::post('/notification', 'notification')->name('notification');
});

Route::prefix('projects')->name('projects.')->group(function (): void {
    Route::controller(WorkspaceController::class)->group(function (): void {
        Route::get('/create', 'create')->name('create');
        Route::put('/order', 'reorder')->name('reorder');
        Route::post('/', 'store')->name('store');
    });

    Route::prefix('{project}')->group(function (): void {
        Route::controller(WorkspaceController::class)->group(function (): void {
            Route::get('/', 'show')->name('show');
            Route::get('/edit', 'edit')->name('edit');
            Route::post('/duplicate', 'duplicate')->name('duplicate');
            Route::put('/', 'update')->name('update');
            Route::delete('/', 'destroy')->name('destroy');
            Route::put('/scratchpad', 'updateScratchpad')->name('scratchpad.update');
            Route::post('/scratchpad/actions/preview', 'previewScratchpadActions')->middleware('throttle:6,1')->name('scratchpad.actions.preview');
            Route::post('/scratchpad/actions', 'applyScratchpadActions')->name('scratchpad.actions.apply');
        });

        Route::prefix('documents')->name('documents.')->controller(ProjectDocumentController::class)->group(function (): void {
            Route::put('/', 'update')->name('update');
            Route::post('/preview', 'preview')->name('preview');
        });

        Route::prefix('board')->name('board.')->controller(BoardController::class)->group(function (): void {
            Route::put('/', 'update')->name('update');
            Route::post('/preview', 'preview')->name('preview');
        });

        Route::controller(InspectionController::class)->group(function (): void {
            Route::post('/inspection', 'refresh')->name('inspection.refresh');
            Route::post('/roots', 'roots')->name('roots.update');
            Route::post('/roots/{root}/outdated', 'outdated')->name('roots.outdated');
            Route::post('/roots/{root}/check', 'check')->name('roots.check');
        });

        Route::prefix('assets')->name('assets.')->controller(ProjectAssetController::class)->group(function (): void {
            Route::post('/', 'store')->name('store');
            Route::put('/move', 'moveMany')->name('move-many');
            Route::delete('/selection', 'destroyMany')->name('remove-many');
            Route::put('/{asset}', 'move')->name('move');
            Route::get('/{asset}', 'download')->name('download');
            Route::get('/{asset}/preview', 'download')->name('preview');
            Route::delete('/{asset}', 'destroy')->name('destroy');
        });

        Route::prefix('asset-folders')->name('asset-folders.')->controller(ProjectAssetController::class)->group(function (): void {
            Route::post('/', 'saveFolder')->name('store');
            Route::put('/{folder}', 'saveFolder')->name('update');
            Route::delete('/{folder}', 'destroyFolder')->name('destroy');
        });

        Route::prefix('secrets')->name('secrets.')->controller(SecretController::class)->group(function (): void {
            Route::middleware(RequireSecretUnlock::class)->group(function (): void {
                Route::post('/', 'store')->name('store');
                Route::get('/values', [SecretVaultController::class, 'values'])->name('values');
                Route::post('/paste', 'paste')->name('paste');
                Route::put('/bulk', 'bulkMetadata')->name('bulk');
                Route::delete('/bulk', 'destroyMany')->name('remove-many');
                Route::post('/import/preview', 'previewImport')->name('import.preview');
                Route::post('/import', 'import')->name('import');
                Route::post('/export/preview', 'previewExport')->name('export.preview');
                Route::post('/export', 'export')->name('export');
                Route::put('/{secret}', 'update')->name('update');
                Route::put('/{secret}/metadata', 'updateMetadata')->name('metadata');
                Route::put('/{secret}/description', 'updateDescription')->name('description');
                Route::delete('/{secret}', 'destroy')->name('destroy');
                Route::get('/{secret}/value', 'reveal')->name('reveal');
                Route::post('/{secret}/copy', 'copy')->name('copy');
            });

        });

        Route::get('/tasks/{task}/attachments/{attachment}', [BoardController::class, 'download'])->name('tasks.attachments.download');
        Route::get('/icon', [CatalogController::class, 'icon'])->name('icon');
        Route::post('/open/{kind}/{id}', [CatalogController::class, 'open'])
            ->whereIn('kind', ['folders', 'repositories', 'links', 'secrets'])->name('open');
    });
});

Route::prefix('projects/{project}/repositories/{repository}')->name('repositories.')->controller(ProviderController::class)->group(function (): void {
    Route::post('/connection', 'associate')->name('connection');
    Route::post('/refresh', 'refresh')->name('refresh');
});

Route::post('/folders/inspect', [CatalogController::class, 'inspect'])->name('folders.inspect');
Route::post('/repositories/clone', [CatalogController::class, 'clone'])->name('repositories.clone');

Route::prefix('secrets')->name('secrets.')->controller(SecretVaultController::class)->group(function (): void {
    Route::get('/unlock/status', 'status')->name('unlock.status');
    Route::post('/pin', 'setup')->name('pin.store');
    Route::put('/pin', 'update')->name('pin.update');
    Route::get('/recovery/status', 'recoveryStatus')->name('recovery.status');
    Route::post('/recover', 'recover')->name('recover');
    Route::post('/reset', 'reset')->name('reset');
    Route::post('/unlock', 'unlock')->name('unlock');
    Route::post('/lock', 'lock')->name('lock');
});

Route::prefix('settings')->controller(SettingsController::class)->group(function (): void {
    Route::get('/', 'index')->name('settings.index');
    Route::get('/connections', 'index')->defaults('section', 'connections')->name('connections.index');
    Route::get('/backups', 'index')->defaults('section', 'backups')->name('backups.index');
});

Route::prefix('backups')->name('backups.')->controller(BackupController::class)->group(function (): void {
    Route::post('/export/preview', 'previewExport')->name('export.preview');
    Route::post('/export', 'export')->name('export');
    Route::post('/restore/preview', 'previewRestore')->name('restore.preview');
    Route::post('/restore', 'applyRestore')->name('restore');
});

Route::prefix('connections')->name('connections.')->controller(ProviderController::class)->group(function (): void {
    Route::post('/token-page', 'openTokenPage')->name('token-page');
    Route::post('/', 'save')->name('store');
    Route::get('/{connection}/repositories', 'repositories')->name('repositories');
    Route::put('/{connection}', 'save')->name('update');
    Route::put('/{connection}/label', 'rename')->name('rename');
    Route::delete('/{connection}', 'destroy')->name('destroy');
});

Route::get('/startup', [SettingsController::class, 'startup'])->name('startup');
Route::put('/settings/{section}', [SettingsController::class, 'update'])->name('settings.update');
Route::post('/settings/appearance/preview', [SettingsController::class, 'appearance'])->name('settings.appearance.preview');
Route::put('/settings/general/login', [SettingsController::class, 'login'])->name('settings.login');
Route::put('/settings/connections/ai', [ScratchpadAiController::class, 'update'])->middleware('throttle:6,1')->name('settings.ai.update');
Route::delete('/settings/connections/ai', [ScratchpadAiController::class, 'destroy'])->name('settings.ai.destroy');
Route::post('/settings/tools/probe', [ToolsController::class, 'probe'])->name('settings.tools.probe');
Route::post('/settings/tools/pick', [ToolsController::class, 'pick'])->name('settings.tools.pick');
Route::get('/backups/preferences', [BackupController::class, 'preferences'])->name('backups.preferences');
Route::post('/backups/folder', [BackupController::class, 'chooseFolder'])->name('backups.folder');
Route::get('/settings/updates', [AppUpdateController::class, 'show'])->name('settings.updates.show');
Route::post('/settings/updates/{action}', [AppUpdateController::class, 'update'])->name('settings.updates.update');
