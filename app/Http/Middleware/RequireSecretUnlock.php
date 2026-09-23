<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecretUnlock
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless((int) $request->session()->get('secret_pin_unlocked_until', 0) > now()->timestamp, 423, 'Unlock secrets with your PIN.');

        return $next($request);
    }
}
