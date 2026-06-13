<?php

namespace App\Http\Middleware;

use App\Domains\Platform\Models\ApiRequestLog;
use BackedEnum;
use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Routing\UrlRoutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class LogApiRequest
{
    private const REDACTED = '[REDACTED]';

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->shouldLog($request)) {
            return $next($request);
        }

        $requestId = $this->requestId($request);

        $request->headers->set('X-Request-Id', $requestId);
        $request->attributes->set('api_request_log', [
            'started_at' => microtime(true),
            'request_id' => $requestId,
            'request_body' => $this->requestBody($request),
            'uploaded_files' => $this->uploadedFiles($request->allFiles()),
        ]);

        try {
            $response = $next($request);
        } catch (Throwable $e) {
            $request->attributes->set('api_request_log_exception', $e);
            throw $e;
        }

        $response->headers->set('X-Request-Id', $requestId);

        return $response;
    }

    public function terminate(Request $request, Response $response): void
    {
        $context = $request->attributes->get('api_request_log');

        if (! is_array($context)) {
            return;
        }

        if (! (bool) config('api_logging.log_successful', true)
            && $response->getStatusCode() < 400
            && ! $request->attributes->has('api_request_log_exception')) {
            return;
        }

        try {
            [$queryParams, $queryTruncated] = $this->fitPayload(
                $this->sanitizePayload($request->query->all()),
                (int) config('api_logging.max_body_bytes', 32768)
            );

            [$routeParams, $routeTruncated] = $this->fitPayload(
                $this->sanitizePayload($this->routeParameters($request)),
                (int) config('api_logging.max_body_bytes', 32768)
            );

            [$requestHeaders, $headersTruncated] = $this->fitPayload(
                $this->sanitizePayload($this->headers($request)),
                (int) config('api_logging.max_header_bytes', 8192)
            );

            [$responseHeaders, $responseHeadersTruncated] = $this->fitPayload(
                $this->sanitizePayload($this->headers($response)),
                (int) config('api_logging.max_header_bytes', 8192)
            );

            [$responseBody, $responseTruncated] = $this->responseBody($response);

            $exception = $request->attributes->get('api_request_log_exception');
            $route = $request->route();
            $durationMs = isset($context['started_at'])
                ? max(0, (int) round((microtime(true) - (float) $context['started_at']) * 1000))
                : null;

            ApiRequestLog::create([
                'request_id' => $context['request_id'],
                'user_id' => $request->user()?->id,
                'method' => $request->method(),
                'path' => mb_substr($request->path(), 0, 2048),
                'path_hash' => hash('sha256', $request->path()),
                'full_url' => $this->fullUrl($request, $queryParams),
                'route_name' => $route?->getName(),
                'route_action' => $route ? mb_substr($route->getActionName(), 0, 255) : null,
                'status_code' => $response->getStatusCode(),
                'successful' => $response->getStatusCode() < 400,
                'duration_ms' => $durationMs,
                'ip_address' => $request->ip(),
                'ip_chain' => $request->ips(),
                'user_agent' => mb_substr((string) $request->userAgent(), 0, 2000),
                'host' => mb_substr((string) $request->getHost(), 0, 255),
                'origin' => mb_substr((string) $request->headers->get('Origin'), 0, 255),
                'referer' => mb_substr((string) $request->headers->get('Referer'), 0, 2000),
                'content_type' => mb_substr((string) $request->headers->get('Content-Type'), 0, 255),
                'accept' => mb_substr((string) $request->headers->get('Accept'), 0, 255),
                'request_headers' => $requestHeaders,
                'query_params' => $queryParams,
                'route_params' => $routeParams,
                'request_body' => $context['request_body']['payload'] ?? null,
                'uploaded_files' => $context['uploaded_files']['payload'] ?? null,
                'response_headers' => $responseHeaders,
                'response_body' => $responseBody,
                'request_truncated' => (bool) (
                    $queryTruncated
                    || $routeTruncated
                    || $headersTruncated
                    || ($context['request_body']['truncated'] ?? false)
                    || ($context['uploaded_files']['truncated'] ?? false)
                ),
                'response_truncated' => (bool) (
                    $responseTruncated
                    || $responseHeadersTruncated
                ),
                'error_class' => $exception ? $exception::class : null,
                'error_message' => $exception ? mb_substr($exception->getMessage(), 0, 4000) : null,
                'error_trace' => $this->errorTrace($exception),
            ]);
        } catch (Throwable $e) {
            Log::warning('No se pudo registrar api_request_log', [
                'error' => $e->getMessage(),
                'path' => $request->path(),
            ]);
        }
    }

    private function shouldLog(Request $request): bool
    {
        if (! (bool) config('api_logging.enabled', true)) {
            return false;
        }

        foreach ((array) config('api_logging.except', []) as $pattern) {
            if ($pattern !== '' && $request->is($pattern)) {
                return false;
            }
        }

        $sampleRate = max(0.0, min(1.0, (float) config('api_logging.sample_rate', 1.0)));

        if ($sampleRate >= 1.0) {
            return true;
        }

        return mt_rand() / mt_getrandmax() <= $sampleRate;
    }

    private function requestId(Request $request): string
    {
        $incoming = (string) $request->headers->get('X-Request-Id', '');

        return Str::isUuid($incoming) ? $incoming : (string) Str::uuid();
    }

    private function requestBody(Request $request): array
    {
        if (! (bool) config('api_logging.log_request_body', true)) {
            return ['payload' => null, 'truncated' => false];
        }

        $payload = $request->isJson()
            ? $request->json()->all()
            : $request->request->all();

        if ($payload === [] && $request->getContent() !== '') {
            $payload = $this->decodeOrWrapRaw($request->getContent(), (string) $request->headers->get('Content-Type'));
        }

        [$payload, $truncated] = $this->fitPayload(
            $this->sanitizePayload($payload),
            (int) config('api_logging.max_body_bytes', 32768)
        );

        return ['payload' => $payload, 'truncated' => $truncated];
    }

    private function responseBody(Response $response): array
    {
        if (! (bool) config('api_logging.log_response_body', true)) {
            return [null, false];
        }

        if ($response instanceof BinaryFileResponse || $response instanceof StreamedResponse) {
            return [[
                '_omitted' => 'binary_or_streamed_response',
                'content_type' => $response->headers->get('Content-Type'),
            ], false];
        }

        $contentType = (string) $response->headers->get('Content-Type');
        $content = $response->getContent();

        if ($content === false || $content === '') {
            return [null, false];
        }

        if ($response instanceof JsonResponse) {
            $payload = $response->getData(true);
        } else {
            $payload = $this->decodeOrWrapRaw($content, $contentType);
        }

        [$payload, $truncated] = $this->fitPayload(
            $this->sanitizePayload($payload),
            (int) config('api_logging.max_body_bytes', 32768)
        );

        return [$payload, $truncated];
    }

    private function decodeOrWrapRaw(string $content, string $contentType): mixed
    {
        if (str_contains(strtolower($contentType), 'json')) {
            $decoded = json_decode($content, true);

            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        if (str_starts_with(strtolower($contentType), 'text/')
            || str_contains(strtolower($contentType), 'xml')
            || str_contains(strtolower($contentType), 'html')) {
            return ['_raw' => $content];
        }

        return [
            '_omitted' => 'non_text_body',
            'content_type' => $contentType,
            'bytes' => strlen($content),
        ];
    }

    private function headers(Request|Response $message): array
    {
        $headers = [];

        foreach ($message->headers->all() as $key => $values) {
            $headers[$key] = count($values) === 1 ? $values[0] : $values;
        }

        return $headers;
    }

    private function routeParameters(Request $request): array
    {
        $route = $request->route();

        if (! $route) {
            return [];
        }

        return $this->normalizeValue($route->parameters());
    }

    private function uploadedFiles(array $files): array
    {
        [$payload, $truncated] = $this->fitPayload(
            $this->normalizeValue($files),
            (int) config('api_logging.max_body_bytes', 32768)
        );

        return ['payload' => $payload, 'truncated' => $truncated];
    }

    private function normalizeValue(mixed $value): mixed
    {
        if ($value instanceof UploadedFile) {
            return [
                'original_name' => $value->getClientOriginalName(),
                'mime_type' => $value->getClientMimeType(),
                'size' => $value->getSize(),
                'error' => $value->getError(),
            ];
        }

        if ($value instanceof Model) {
            return [
                'model' => $value::class,
                'key' => $value->getKey(),
                'route_key' => $value->getRouteKey(),
            ];
        }

        if ($value instanceof UrlRoutable) {
            return $value->getRouteKey();
        }

        if ($value instanceof BackedEnum) {
            return $value->value;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format(DateTimeInterface::ATOM);
        }

        if (is_array($value)) {
            $normalized = [];

            foreach ($value as $key => $item) {
                $normalized[$key] = $this->normalizeValue($item);
            }

            return $normalized;
        }

        if (is_object($value)) {
            return ['object' => $value::class];
        }

        return $value;
    }

    private function sanitizePayload(mixed $value, string|int|null $key = null, ?array $parent = null): mixed
    {
        if ($key !== null && $this->isSensitiveKey((string) $key)) {
            return self::REDACTED;
        }

        if ($this->isSensitiveEnvValue($key, $parent)) {
            return self::REDACTED;
        }

        $value = $this->normalizeValue($value);

        if (is_array($value)) {
            $sanitized = [];

            foreach ($value as $childKey => $childValue) {
                $sanitized[$childKey] = $this->sanitizePayload($childValue, $childKey, $value);
            }

            return $sanitized;
        }

        return $value;
    }

    private function isSensitiveKey(string $key): bool
    {
        $key = str_replace([' ', '.'], ['_', '_'], strtolower($key));

        foreach ((array) config('api_logging.sensitive_keys', []) as $sensitive) {
            $sensitive = strtolower((string) $sensitive);

            if ($sensitive !== '' && str_contains($key, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    private function isSensitiveEnvValue(string|int|null $key, ?array $parent): bool
    {
        if ($key !== 'value' || ! $parent) {
            return false;
        }

        if (($parent['is_secret'] ?? false) === true) {
            return true;
        }

        $envKey = strtoupper((string) ($parent['key'] ?? ''));

        return $envKey !== '' && preg_match('/(SECRET|TOKEN|PASSWORD|PRIVATE|APP_KEY|API_KEY|WEBHOOK|CLIENT_SECRET)/', $envKey) === 1;
    }

    private function fitPayload(mixed $payload, int $maxBytes): array
    {
        if ($payload === null) {
            return [null, false];
        }

        $maxBytes = max(1024, $maxBytes);
        $encoded = $this->json($payload);

        if (strlen($encoded) <= $maxBytes) {
            return [$payload, false];
        }

        return [[
            '_truncated' => true,
            '_preview' => mb_substr($encoded, 0, $maxBytes),
            '_bytes' => strlen($encoded),
        ], true];
    }

    private function fullUrl(Request $request, mixed $queryParams): string
    {
        if (! is_array($queryParams) || $queryParams === []) {
            return $request->url();
        }

        return $request->url() . '?' . http_build_query($queryParams);
    }

    private function errorTrace(?Throwable $exception): ?string
    {
        if (! $exception || ! (bool) config('api_logging.log_error_trace', false)) {
            return null;
        }

        return mb_substr($exception->getTraceAsString(), 0, 8000);
    }

    private function json(mixed $payload): string
    {
        return json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        ) ?: '';
    }
}
