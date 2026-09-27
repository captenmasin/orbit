<?php

namespace App\Http\Middleware;

use App\SecretVault;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireSecretUnlock
{
    public function __construct(private SecretVault $vault) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($this->vault->unlockedUntil($request) !== null, 423, 'Unlock secrets with your PIN.');

        return $next($request);
    }
}
