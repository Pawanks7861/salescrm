<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

/**
 * Uptime-monitor endpoint. Reports only ok/fail per component; never
 * versions, paths, hostnames, database names, configuration or error text.
 */
class HealthController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $checks = [
            'app' => 'ok',
            'database' => $this->check(fn () => DB::select('select 1') !== null),
            'cache' => $this->check(function () {
                $key = 'health:'.Str::random(8);
                Cache::put($key, 1, 10);
                $ok = Cache::get($key) === 1;
                Cache::forget($key);

                return $ok;
            }),
        ];

        $healthy = ! in_array('fail', $checks, true);

        return response()
            ->json(['status' => $healthy ? 'ok' : 'fail', 'checks' => $checks], $healthy ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }

    private function check(callable $probe): string
    {
        try {
            return $probe() ? 'ok' : 'fail';
        } catch (Throwable) {
            return 'fail';
        }
    }
}
