<?php

namespace App\Http\Middleware;

use App\Services\Security\OfficeNetworkGuard;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** Signed-in CRM pages are reachable only from the Medawk office network when that restriction is on. */
class EnsureOfficeNetwork
{
    public function __construct(private readonly OfficeNetworkGuard $network) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->network->allows($request->ip())) {
            return $next($request);
        }

        if ($request->user()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return redirect()->route('login')->withErrors([
            'email' => OfficeNetworkGuard::MESSAGE,
        ]);
    }
}
