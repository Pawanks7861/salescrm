<?php

namespace App\Services\Meta;

use App\Enums\MetaErrorCategory;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * The only class that talks HTTP to the Meta Graph API.
 *
 * - Version from config (META_GRAPH_VERSION), never hard-coded by callers.
 * - Tokens travel in the Authorization header (not the URL) together with an
 *   appsecret_proof; any exception is converted into a sanitized
 *   MetaApiException so request URLs/tokens can never leak into logs.
 * - Connect/request timeouts bound every call; only idempotent GETs are
 *   retried in-process (once, on network errors). Everything else is left to
 *   queue backoff.
 */
class MetaGraphClient
{
    private const RATE_LIMIT_CODES = [4, 17, 32, 613];

    private const PERMISSION_CODES = [10, 200, 294, 368];

    public function version(): string
    {
        return (string) config('meta.graph_version');
    }

    public function isConfigured(): bool
    {
        return filled(config('meta.app_id')) && filled(config('meta.app_secret'));
    }

    /** @throws MetaApiException */
    public function get(string $path, array $query = [], ?string $token = null, bool $proof = true): array
    {
        return $this->send('GET', $path, $query, $token, $proof);
    }

    /** @throws MetaApiException */
    public function post(string $path, array $params = [], ?string $token = null): array
    {
        return $this->send('POST', $path, $params, $token);
    }

    /** @throws MetaApiException */
    public function delete(string $path, array $params = [], ?string $token = null): array
    {
        return $this->send('DELETE', $path, $params, $token);
    }

    /** "{app-id}|{app-secret}" app access token, used only for debug_token. */
    public function appAccessToken(): string
    {
        return config('meta.app_id').'|'.config('meta.app_secret');
    }

    /** @throws MetaApiException */
    private function send(string $method, string $path, array $params, ?string $token, bool $proof = true): array
    {
        if (! $this->isConfigured()) {
            throw MetaApiException::of(MetaErrorCategory::Configuration, 'META_APP_ID / META_APP_SECRET are not configured.');
        }

        $url = rtrim((string) config('meta.graph_url'), '/').'/'.$this->version().'/'.ltrim($path, '/');

        if ($proof && $token !== null && $token !== '') {
            $params['appsecret_proof'] = hash_hmac('sha256', $token, (string) config('meta.app_secret'));
        }

        try {
            $response = match ($method) {
                'GET' => $this->request($token, retry: true)->get($url, $params),
                'POST' => $this->request($token)->asForm()->post($url, $params),
                'DELETE' => $this->request($token)->asForm()->delete($url, $params),
            };
        } catch (ConnectionException) {
            $this->logFailure($path, MetaErrorCategory::Network, null, null);
            throw new MetaApiException(MetaErrorCategory::Network, 'Could not reach Meta (connection failed or timed out).', 'network');
        } catch (\Throwable $e) {
            $this->logFailure($path, MetaErrorCategory::Network, null, null);
            throw new MetaApiException(MetaErrorCategory::Network, 'Unexpected transport error ('.class_basename($e).').', 'network');
        }

        return $this->parse($response, $path);
    }

    private function request(?string $token, bool $retry = false): PendingRequest
    {
        $request = Http::acceptJson()
            ->connectTimeout((int) config('meta.http.connect_timeout', 5))
            ->timeout((int) config('meta.http.timeout', 15));

        if ($token !== null && $token !== '') {
            $request = $request->withToken($token);
        }

        return $retry ? $request->retry(2, 250, fn ($e) => $e instanceof ConnectionException, throw: false) : $request;
    }

    /** @throws MetaApiException */
    private function parse(Response $response, string $path): array
    {
        $json = $response->json();

        if ($response->successful()) {
            if (! is_array($json)) {
                $this->logFailure($path, MetaErrorCategory::Malformed, null, $response->status());
                throw new MetaApiException(MetaErrorCategory::Malformed, 'Meta returned a response that is not JSON.', 'malformed', $response->status());
            }

            return $json;
        }

        $error = is_array($json['error'] ?? null) ? $json['error'] : [];
        $code = isset($error['code']) ? (int) $error['code'] : null;
        $subcode = isset($error['error_subcode']) ? (int) $error['error_subcode'] : null;
        $category = $this->classify($response->status(), $code, $subcode, (bool) ($error['is_transient'] ?? false));
        $metaCode = $code !== null ? 'meta_'.$code.($subcode ? ':'.$subcode : '') : 'http_'.$response->status();

        $this->logFailure($path, $category, $metaCode, $response->status());

        $detail = is_string($error['message'] ?? null) ? $error['message'] : '';

        throw new MetaApiException(
            $category,
            trim($category->message().($detail !== '' ? ' ('.$detail.')' : '')),
            $metaCode,
            $response->status(),
            $category === MetaErrorCategory::RateLimit ? $this->retryAfter($response) : null,
        );
    }

    public function classify(int $status, ?int $code, ?int $subcode, bool $transient): MetaErrorCategory
    {
        return match (true) {
            $code === 190 || $code === 102 => MetaErrorCategory::Authentication,
            $code !== null && (in_array($code, self::PERMISSION_CODES, true) || ($code > 200 && $code < 300)) => MetaErrorCategory::Permission,
            $code !== null && (in_array($code, self::RATE_LIMIT_CODES, true) || ($code >= 80000 && $code <= 80014)) => MetaErrorCategory::RateLimit,
            $status === 429 => MetaErrorCategory::RateLimit,
            $code === 100 && $subcode === 33 => MetaErrorCategory::NotFound,
            $status === 404 => MetaErrorCategory::NotFound,
            $transient || $code === 1 || $code === 2 || $status >= 500 => MetaErrorCategory::Temporary,
            $status === 401 => MetaErrorCategory::Authentication,
            $status === 403 => MetaErrorCategory::Permission,
            default => MetaErrorCategory::Malformed,
        };
    }

    /** Seconds until Meta says access is regained (Business Use Case header), capped at 1h. */
    private function retryAfter(Response $response): ?int
    {
        foreach (['X-Business-Use-Case-Usage', 'X-Ad-Account-Usage'] as $header) {
            $usage = json_decode((string) $response->header($header), true);
            if (! is_array($usage)) {
                continue;
            }

            $minutes = collect($usage)->flatten(1)->pluck('estimated_time_to_regain_access')->filter()->max();
            if ($minutes) {
                return min(3600, (int) $minutes * 60);
            }
        }

        return null;
    }

    private function logFailure(string $path, MetaErrorCategory $category, ?string $code, ?int $status): void
    {
        Log::warning('Meta Graph API request failed', [
            'endpoint' => preg_replace('/[?#].*$/', '', $path),
            'category' => $category->value,
            'meta_code' => $code,
            'http_status' => $status,
        ]);
    }
}
