<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        // Must exist before the view renders so @routes / @vite can stamp it.
        $nonce = Vite::useCspNonce();

        $response = $next($request);

        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=(), usb=()');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        if ($this->wantsCsp($response)) {
            $header = config('security.csp.report_only') ? 'Content-Security-Policy-Report-Only' : 'Content-Security-Policy';
            $response->headers->set($header, $this->policy($nonce, $request->isSecure()));
        }

        return $response;
    }

    private function wantsCsp(Response $response): bool
    {
        return config('security.csp.enabled', true)
            && ! Vite::isRunningHot()
            && ! $response->headers->has('Content-Security-Policy')
            && str_contains((string) $response->headers->get('Content-Type', 'text/html'), 'text/html');
    }

    private function policy(string $nonce, bool $secure): string
    {
        $directives = [
            'default-src' => ["'self'"],
            'script-src' => ["'self'", "'nonce-{$nonce}'", ...(array) config('security.csp.extra_script_src', [])],
            'style-src' => ["'self'", "'unsafe-inline'", 'https://fonts.bunny.net'],
            'font-src' => ["'self'", 'data:', 'https://fonts.bunny.net'],
            'img-src' => ["'self'", 'data:', 'blob:', 'https://*.fbcdn.net', 'https://*.fbsbx.com'],
            'media-src' => ["'self'", 'blob:'],
            'connect-src' => ["'self'", ...(array) config('security.csp.fcm_hosts', []), ...(array) config('security.csp.extra_connect_src', [])],
            'worker-src' => ["'self'"],
            'manifest-src' => ["'self'"],
            'frame-src' => ["'self'"],
            'frame-ancestors' => ["'self'"],
            'form-action' => ["'self'", 'https://www.facebook.com'],
            'base-uri' => ["'self'"],
            'object-src' => ["'none'"],
        ];

        $parts = [];
        foreach ($directives as $name => $sources) {
            $parts[] = $name.' '.implode(' ', array_unique(array_filter($sources)));
        }
        if ($secure) {
            $parts[] = 'upgrade-insecure-requests';
        }

        return implode('; ', $parts);
    }
}
