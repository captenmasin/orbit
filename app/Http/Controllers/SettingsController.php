<?php

namespace App\Http\Controllers;

use App\Actions\InvalidateRuntimeResults;
use App\Actions\ProbeRuntimes;
use App\Actions\SaveProjectStatuses;
use App\AppUpdates;
use App\Models\BoardColumn;
use App\Models\Project;
use App\Models\ProviderConnection;
use App\ScratchpadAi;
use App\SecretVault;
use App\WorkspacePreferences;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Native\Desktop\Enums\SystemThemesEnum;
use Native\Desktop\Facades\App;
use Native\Desktop\Facades\System;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class SettingsController extends Controller
{
    public function index(Request $request, WorkspacePreferences $preferences, SecretVault $vault, ScratchpadAi $ai): Response
    {
        $native = (bool) config('nativephp-internal.running');
        $version = config('nativephp.version');
        $login = null;
        $nativeError = null;
        if ($native) {
            try {
                $version = App::version();
                $login = App::openAtLogin();
            } catch (Throwable) {
                $nativeError = 'Native app settings are unavailable. Try reopening Settings.';
            }
        }
        try {
            $pinSet = $vault->pinHash() !== null;
        } catch (Throwable) {
            $pinSet = null;
        }
        $response = Inertia::render('Settings', [
            'preferences' => $this->safeSnapshot($preferences),
            'section' => $request->route('section', $request->query('section', 'general')),
            'pinSet' => $pinSet,
            'connections' => ProviderConnection::orderBy('label')->get(),
            'ai' => $ai->status(),
            'columnColors' => BoardColumn::COLORS,
            'defaultColumns' => WorkspacePreferences::defaultBoardColumns(),
            'statusUsage' => Project::get(['status', 'previous_status'])
                ->flatMap(fn (Project $project): array => array_filter([$project->status, $project->previous_status], fn (?string $status): bool => $status !== null))
                ->countBy()->map(fn (int $count, string $name): array => ['name' => $name, 'count' => $count])->values(),
            'launchAtLogin' => $login,
            'nativeError' => $nativeError,
            'about' => ['version' => $version, 'updatesAvailable' => app(AppUpdates::class)->available(), 'releaseNotes' => config('nativephp.release_notes_url')],
            'mcp' => $native ? [
                'command' => PHP_BINARY, 'args' => [base_path('artisan'), 'mcp:start', 'orbit'],
                'env' => ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => DB::connection()->getDatabaseName(), 'LARAVEL_STORAGE_PATH' => storage_path(), 'NATIVEPHP_RUNNING' => 'false'],
            ] : null,
        ])->toResponse($request);
        $response->headers->set('Cache-Control', 'no-store');

        return $response;
    }

    public function update(Request $request, string $section, WorkspacePreferences $preferences): JsonResponse
    {
        if ($section === 'project_statuses') {
            app(SaveProjectStatuses::class)->handle($request->all());

            return response()->json(['preferences' => $this->safeSnapshot($preferences)]);
        }

        $rules = match ($section) {
            'general' => ['startup_destination' => ['required', Rule::in(['dashboard', 'last_project'])]],
            'appearance' => [
                'theme' => ['required', Rule::in(['light', 'dark', 'system'])],
                'reduce_motion' => ['sometimes', 'string', Rule::in(['system', 'on', 'off'])],
                'pointer_cursors' => ['sometimes', 'boolean:strict'],
            ],
            'security' => ['lock_minutes' => ['required', 'integer', Rule::in([5, 15, 60, 480])], 'clipboard_seconds' => ['required', 'integer', Rule::in([0, 30, 60])]],
            'project_defaults' => ['columns' => ['required', 'array']],
            'tools' => ['paths' => ['required', 'array:php,node,composer,npm,pnpm,yarn'], 'paths.*' => ['nullable', 'string', 'max:4096']],
            default => abort(404),
        };
        $data = $request->validate([...$rules, 'revision' => ['required', 'integer', 'min:1']]);
        $revision = (int) $data['revision'];
        unset($data['revision']);
        if ($section === 'security') {
            $data['lock_minutes'] = (int) $data['lock_minutes'];
            $data['clipboard_seconds'] = (int) $data['clipboard_seconds'];
        }
        if ($section === 'project_defaults') {
            $data['columns'] = WorkspacePreferences::validatedBoardColumns($data['columns']);
        }
        if ($section === 'tools') {
            app(ProbeRuntimes::class)->validatePaths($data['paths']);
            $data['paths'] = array_replace(WorkspacePreferences::defaults()['tools']['paths'], $data['paths']);
        }
        DB::transaction(function () use ($preferences, $section, $data, $revision): void {
            $previous = $preferences->get($section);
            $preferences->update($section, $data, $revision);
            if ($section === 'security' && $previous['lock_minutes'] !== (int) $data['lock_minutes']) {
                app(SecretVault::class)->revokeUnlocks();
            }
            if ($section === 'tools') {
                app(InvalidateRuntimeResults::class)->handle($previous['paths'], $data['paths']);
            }
            if ($section === 'appearance' && config('nativephp-internal.running')) {
                try {
                    System::theme(SystemThemesEnum::from($data['theme']));
                } catch (Throwable) {
                    throw ValidationException::withMessages(['theme' => 'The native window appearance could not be changed. Try again.']);
                }
            }
        });

        return response()->json(['preferences' => $this->safeSnapshot($preferences)]);
    }

    public function appearance(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $data = $request->validate(['theme' => ['required', Rule::in(['light', 'dark', 'system'])]]);
        try {
            System::theme(SystemThemesEnum::from($data['theme']));
        } catch (Throwable) {
            throw ValidationException::withMessages(['theme' => 'The native window appearance could not be changed.']);
        }

        return response()->json(['previewed' => true]);
    }

    public function login(Request $request): JsonResponse
    {
        abort_unless(config('nativephp-internal.running'), 403);
        $data = $request->validate(['enabled' => ['required', 'boolean']]);
        try {
            App::openAtLogin((bool) $data['enabled']);
            $actual = App::openAtLogin();
            if ($actual !== (bool) $data['enabled']) {
                throw new \RuntimeException;
            }
        } catch (Throwable) {
            throw ValidationException::withMessages(['enabled' => 'The operating system could not confirm launch at login. Try again in the packaged app.']);
        }

        return response()->json(['enabled' => $actual]);
    }

    public function startup(WorkspacePreferences $preferences): Response
    {
        $project = $preferences->get('general.startup_destination') === 'last_project'
            ? Project::whereKey($preferences->lastProjectId())->whereNull('archived_at')->where('status', '!=', 'Archived')->first() : null;

        return $project ? to_route('projects.show', $project) : to_route('workspace');
    }

    private function safeSnapshot(WorkspacePreferences $preferences): array
    {
        $snapshot = $preferences->snapshot();
        unset($snapshot['values']['ai'], $snapshot['values']['security']['generation'], $snapshot['values']['startup']);

        return $snapshot;
    }
}
