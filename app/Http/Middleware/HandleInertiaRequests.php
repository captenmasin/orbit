<?php

namespace App\Http\Middleware;

use App\Models\Project;
use App\WorkspacePreferences;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /** @return array<string, mixed> */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'message' => fn () => $request->session()->get('message'),
            'sidebarProjects' => fn () => Project::orderBy('position')->orderBy('id')
                ->get(['id', 'name', 'description', 'status', 'revision', 'icon_type', 'icon_emoji', 'icon_path']),
            'statuses' => Project::statuses(),
            'statusColors' => fn () => app(WorkspacePreferences::class)->get('project_statuses.colors'),
            'appearance' => fn () => app(WorkspacePreferences::class)->get('appearance.theme'),
            'reduceMotion' => fn () => app(WorkspacePreferences::class)->get('appearance.reduce_motion'),
            'native' => (bool) config('nativephp-internal.running'),
        ];
    }
}
