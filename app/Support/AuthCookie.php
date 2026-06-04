<?php

namespace App\Support;

use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Response;

final class AuthCookie
{
    public const NAME = 'auth_token';

    public static function make(string $token, int $ttlMinutes): Cookie
    {
        return cookie(
            self::NAME,
            $token,
            $ttlMinutes,
            '/',
            config('session.domain'),
            (bool) config('session.secure'),
            true,
            false,
            config('session.same_site', 'lax') ?: 'lax',
        );
    }

    /**
     * Delete both the configured-domain cookie and the legacy host-only cookie.
     */
    public static function forgetCookies(): array
    {
        return array_values(array_filter([
            cookie()->forget(self::NAME, '/', config('session.domain')),
            config('session.domain') ? cookie()->forget(self::NAME, '/', null) : null,
        ]));
    }

    public static function attachAuthCookie(JsonResponse $response, string $token, int $ttlMinutes): JsonResponse
    {
        if (config('session.domain')) {
            $response->headers->setCookie(cookie()->forget(self::NAME, '/', null));
        }

        $response->headers->setCookie(self::make($token, $ttlMinutes));

        return $response;
    }

    public static function attachForgetCookies(Response $response): Response
    {
        foreach (self::forgetCookies() as $cookie) {
            $response->headers->setCookie($cookie);
        }

        return $response;
    }
}
