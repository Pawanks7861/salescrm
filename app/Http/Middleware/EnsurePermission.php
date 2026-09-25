<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Usage: ->middleware('permission:lead.export') or 'permission:report.view|report.view_all' (any-of).
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $permissions): Response
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User && $user->hasAnyPermission(...explode('|', $permissions)),
            Response::HTTP_FORBIDDEN,
            'You do not have permission to perform this action.',
        );

        return $next($request);
    }
}
