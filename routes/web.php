<?php

use App\Http\Controllers\BoardController;
use App\Http\Controllers\CatalogController;
use App\Http\Controllers\InspectionController;
use App\Http\Controllers\WorkspaceController;
use Illuminate\Support\Facades\Route;

Route::get('/', [WorkspaceController::class, 'index'])->name('workspace');
Route::get('/projects/create', [WorkspaceController::class, 'index'])->name('projects.create');
Route::get('/projects/{project}', [WorkspaceController::class, 'index'])->name('projects.show');
Route::post('/projects', [WorkspaceController::class, 'store'])->name('projects.store');
Route::put('/projects/{project}', [WorkspaceController::class, 'update'])->name('projects.update');
Route::delete('/projects/{project}', [WorkspaceController::class, 'destroy'])->name('projects.destroy');
Route::put('/projects/{project}/board', [BoardController::class, 'update'])->name('projects.board.update');
Route::post('/projects/{project}/inspection', [InspectionController::class, 'refresh'])->name('projects.inspection.refresh');
Route::post('/projects/{project}/roots', [InspectionController::class, 'roots'])->name('projects.roots.update');
Route::post('/projects/{project}/board/preview', [BoardController::class, 'preview'])->name('projects.board.preview');
Route::get('/projects/{project}/tasks/{task}/attachments/{attachment}', [BoardController::class, 'download'])->name('projects.tasks.attachments.download');
Route::get('/projects/{project}/icon', [CatalogController::class, 'icon'])->name('projects.icon');
Route::post('/folders/inspect', [CatalogController::class, 'inspect'])->name('folders.inspect');
Route::post('/projects/{project}/open/{kind}/{id}', [CatalogController::class, 'open'])
    ->whereIn('kind', ['folders', 'repositories', 'links'])->name('projects.open');
