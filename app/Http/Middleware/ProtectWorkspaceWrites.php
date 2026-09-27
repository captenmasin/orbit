<?php

namespace App\Http\Middleware;

use App\AppUpdates;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class ProtectWorkspaceWrites
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('GET') || $request->is('settings/updates/*') || ! app(AppUpdates::class)->available()) {
            return $next($request);
        }
        // ponytail: serialize UI writes during updates; use per-operation leases if write throughput matters.
        $lock = Cache::lock('orbit-workspace-write', 3600);
        abort_unless($lock->get(), 409, 'A workspace operation is still running. Try again after it finishes.');
        try {
            return $next($request);
        } finally {
            $lock->release();
        }
    }
}
