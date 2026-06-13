<?php

namespace App\Domains\Platform\Services\Coolify;

final class CoolifyHealthCheckPayload
{
    public static function forPort(int|string|null $defaultPort = null, array $overrides = []): array
    {
        $config = array_merge(config('coolify.health_check', []), $overrides);

        if (! self::enabled($config['enabled'] ?? true)) {
            return ['health_check_enabled' => false];
        }

        $port = self::nullableString($config['port'] ?? null) ?? self::nullableString($defaultPort);

        return array_filter([
            'health_check_enabled' => true,
            'health_check_path' => self::path($config['path'] ?? '/'),
            'health_check_port' => $port,
            'health_check_host' => self::nullableString($config['host'] ?? null),
            'health_check_method' => strtoupper((string) ($config['method'] ?? 'GET')),
            'health_check_return_code' => self::positiveInt($config['return_code'] ?? 200, 200),
            'health_check_scheme' => strtolower((string) ($config['scheme'] ?? 'http')),
            'health_check_response_text' => self::nullableString($config['response_text'] ?? null),
            'health_check_interval' => self::positiveInt($config['interval'] ?? 30, 30),
            'health_check_timeout' => self::positiveInt($config['timeout'] ?? 10, 10),
            'health_check_retries' => self::positiveInt($config['retries'] ?? 3, 3),
            'health_check_start_period' => self::positiveInt($config['start_period'] ?? 30, 30),
        ], fn ($value) => $value !== null);
    }

    private static function enabled(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return ! in_array(strtolower(trim((string) $value)), ['0', 'false', 'no', 'off'], true);
    }

    private static function path(mixed $path): string
    {
        $path = trim((string) $path);

        if ($path === '') {
            return '/';
        }

        return str_starts_with($path, '/') ? $path : "/{$path}";
    }

    private static function positiveInt(mixed $value, int $fallback): int
    {
        $value = filter_var($value, FILTER_VALIDATE_INT);

        return $value !== false && $value > 0 ? $value : $fallback;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value !== '' ? $value : null;
    }
}
