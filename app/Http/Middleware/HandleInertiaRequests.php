<?php

namespace App\Http\Middleware;

use App\Models\Project;
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
            'sidebarProjects' => fn () => Project::with('folders:id,project_id,path')->orderBy('position')->orderBy('id')
                ->get(['id', 'name', 'status', 'icon_type', 'icon_emoji', 'icon_path'])
                ->each(fn (Project $project) => $project->setAttribute('has_local_copy', $project->folders->contains(fn ($folder): bool => is_dir($folder->path))))
                ->makeHidden('folders'),
            'native' => (bool) config('nativephp-internal.running'),
        ];
    }
}
