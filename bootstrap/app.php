<?php

use App\Enums\AuditAction;
use App\Http\Controllers\HealthController;
use App\Http\Middleware\EnsureOfficeNetwork;
use App\Http\Middleware\EnsurePermission;
use App\Http\Middleware\EnsureUserIsActive;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\SecurityHeaders;
use App\Services\AuditService;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        then: function () {
            // Stateless (no session/cookies) for uptime monitors.
            Route::get('health', HealthController::class)->middleware('throttle:health')->name('health');

            Route::middleware(['web', 'auth', 'active', 'office'])
                ->prefix('admin')
                ->name('admin.')
                ->group(base_path('routes/admin.php'));

            Route::middleware('throttle:meta-webhook')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
            SecurityHeaders::class,
        ]);

        $middleware->alias([
            'permission' => EnsurePermission::class,
            'active' => EnsureUserIsActive::class,
            'office' => EnsureOfficeNetwork::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // Every authenticated 403 is recorded; export URLs are flagged explicitly.
        $exceptions->render(function (HttpExceptionInterface $e, Request $request) {
            if ($e->getStatusCode() === Response::HTTP_FORBIDDEN && $request->user()) {
                $isExport = str_contains($request->path(), 'export');
                $isFollowup = ! $isExport && $request->routeIs('followups.*');
                $isMeeting = ! $isExport && $request->routeIs('meetings.*', 'calendar.*');

                app(AuditService::class)->log(
                    match (true) {
                        $isExport => AuditAction::ExportAttempted,
                        $isFollowup => AuditAction::FollowupAccessDenied,
                        $isMeeting => AuditAction::MeetingAccessDenied,
                        default => AuditAction::AccessDenied,
                    },
                    match (true) {
                        $isFollowup => 'followups',
                        $isMeeting => 'meetings',
                        default => 'security',
                    },
                    null,
                    ($isExport ? 'Unauthorized export attempt: ' : 'Access denied: ').$request->method().' /'.$request->path(),
                );
            }

            return null;
        });

        $exceptions->respond(function (Response $response, Throwable $e, Request $request) {
            $status = $response->getStatusCode();

            // Machine endpoints: never render HTML error pages or debug output to Meta.
            if ($request->is('webhooks/*')) {
                return response(Response::$statusTexts[$status] ?? 'Error', $status, ['Content-Type' => 'text/plain; charset=UTF-8']);
            }

            $alwaysPretty = in_array($status, [403, 404, 419, 429], true);
            $prettyInProduction = in_array($status, [500, 503], true) && ! app()->hasDebugModeEnabled();

            if ($request->expectsJson() && ! $request->header('X-Inertia')) {
                return $response;
            }

            if ($status === 419) {
                return back()->with('error', 'Your session expired. Please try again.');
            }

            if (! $alwaysPretty && ! $prettyInProduction) {
                return $response;
            }

            // Maintenance runs before sessions/database; the static page needs neither.
            if ($status === 503) {
                return response()->view('errors.503', [], 503, $response->headers->all());
            }

            try {
                $context = $status === 403 && $request->route()?->hasParameter('lead') ? 'lead' : null;

                return Inertia::render('Error', ['status' => $status, 'context' => $context])
                    ->toResponse($request)
                    ->setStatusCode($status);
            } catch (Throwable) {
                return response()->view("errors.{$status}", [], $status);
            }
        });
    })->create();
