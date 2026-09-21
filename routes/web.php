<?php

use App\Http\Controllers\BackupController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\ProjectAssetController;
use App\Http\Controllers\ProviderController;
use App\Http\Controllers\SecretController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('workspace');
Route::get('/projects/create', [WorkspaceController::class, 'create'])->name('projects.create');
Route::put('/projects/order', [WorkspaceController::class, 'reorder'])->name('projects.reorder');
Route::get('/projects/{project}', [WorkspaceController::class, 'show'])->name('projects.show');
Route::get('/projects/{project}/edit', [WorkspaceController::class, 'edit'])->name('projects.edit');
Route::post('/projects', [WorkspaceController::class, 'store'])->name('projects.store');
Route::put('/projects/{project}', [WorkspaceController::class, 'update'])->name('projects.update');
Route::delete('/projects/{project}', [WorkspaceController::class, 'destroy'])->name('projects.destroy');
Route::put('/projects/{project}/board', [BoardController::class, 'update'])->name('projects.board.update');
Route::post('/projects/{project}/inspection', [InspectionController::class, 'refresh'])->name('projects.inspection.refresh');
Route::post('/projects/{project}/roots', [InspectionController::class, 'roots'])->name('projects.roots.update');
Route::post('/projects/{project}/roots/{root}/outdated', [InspectionController::class, 'outdated'])->name('projects.roots.outdated');
Route::post('/projects/{project}/assets', [ProjectAssetController::class, 'store'])->name('projects.assets.store');
Route::get('/projects/{project}/assets/{asset}', [ProjectAssetController::class, 'download'])->name('projects.assets.download');
Route::get('/projects/{project}/assets/{asset}/preview', [ProjectAssetController::class, 'download'])->name('projects.assets.preview');
Route::delete('/projects/{project}/assets/{asset}', [ProjectAssetController::class, 'destroy'])->name('projects.assets.destroy');
Route::post('/projects/{project}/board/preview', [BoardController::class, 'preview'])->name('projects.board.preview');
Route::post('/projects/{project}/secrets', [SecretController::class, 'store'])->name('projects.secrets.store');
Route::post('/projects/{project}/secrets/paste', [SecretController::class, 'paste'])->name('projects.secrets.paste');
Route::post('/projects/{project}/secrets/import/preview', [SecretController::class, 'previewImport'])->name('projects.secrets.import.preview');
Route::post('/projects/{project}/secrets/import', [SecretController::class, 'import'])->name('projects.secrets.import');
Route::post('/projects/{project}/secrets/export/preview', [SecretController::class, 'previewExport'])->name('projects.secrets.export.preview');
Route::post('/projects/{project}/secrets/export', [SecretController::class, 'export'])->name('projects.secrets.export');
Route::put('/projects/{project}/secrets/{secret}', [SecretController::class, 'replace'])->name('projects.secrets.replace');
Route::delete('/projects/{project}/secrets/{secret}', [SecretController::class, 'destroy'])->name('projects.secrets.destroy');
Route::get('/projects/{project}/secrets/{secret}/value', [SecretController::class, 'reveal'])->name('projects.secrets.reveal');
Route::post('/projects/{project}/secrets/{secret}/copy', [SecretController::class, 'copy'])->name('projects.secrets.copy');
Route::delete('/projects/{project}/secrets/{secret}/copy', [SecretController::class, 'clearClipboard'])->name('projects.secrets.copy.clear');
Route::get('/projects/{project}/tasks/{task}/attachments/{attachment}', [BoardController::class, 'download'])->name('projects.tasks.attachments.download');
Route::get('/projects/{project}/icon', [CatalogController::class, 'icon'])->name('projects.icon');
Route::post('/folders/inspect', [CatalogController::class, 'inspect'])->name('folders.inspect');
Route::post('/projects/{project}/open/{kind}/{id}', [CatalogController::class, 'open'])
    ->whereIn('kind', ['folders', 'repositories', 'links'])->name('projects.open');

Route::get('/settings/connections', [WorkspaceController::class, 'connections'])->name('connections.index');
Route::get('/settings/backups', [WorkspaceController::class, 'backups'])->name('backups.index');
Route::post('/backups/export/preview', [BackupController::class, 'previewExport'])->name('backups.export.preview');
Route::post('/backups/export', [BackupController::class, 'export'])->name('backups.export');
Route::post('/backups/restore/preview', [BackupController::class, 'previewRestore'])->name('backups.restore.preview');
Route::post('/backups/restore', [BackupController::class, 'applyRestore'])->name('backups.restore');
Route::post('/connections', [ProviderController::class, 'save'])->name('connections.store');
Route::put('/connections/{connection}', [ProviderController::class, 'save'])->name('connections.update');
Route::delete('/connections/{connection}', [ProviderController::class, 'destroy'])->name('connections.destroy');
Route::post('/projects/{project}/repositories/{repository}/connection', [ProviderController::class, 'associate'])->name('repositories.connection');
Route::post('/projects/{project}/repositories/{repository}/refresh', [ProviderController::class, 'refresh'])->name('repositories.refresh');
