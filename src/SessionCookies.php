<?php
declare(strict_types=1);

namespace Panic;

/**
 * One source of truth for the browser session-cookie policy.
 *
 * Both normal authentication and invite acceptance mint sessions. Keeping
 * the headers here prevents either path from accidentally falling back to
 * JavaScript-readable persistent credentials.
 */
final class SessionCookies
{
    /** @return list<string> */
    public static function issue(string $accessToken, string $refreshToken): array
    {
        $attributes = self::attributes();
        return [
            Auth::ACCESS_COOKIE . '=' . rawurlencode($accessToken)
                . '; Max-Age=' . Auth::accessTtlSeconds() . $attributes,
            Auth::REFRESH_COOKIE . '=' . rawurlencode($refreshToken)
                . '; Max-Age=' . (180 * 24 * 3600) . $attributes,
        ];
    }

    /** @return list<string> */
    public static function clear(): array
    {
        $attributes = self::attributes();
        return [
            Auth::ACCESS_COOKIE . '=; Max-Age=0' . $attributes,
            Auth::REFRESH_COOKIE . '=; Max-Age=0' . $attributes,
        ];
    }

    private static function attributes(): string
    {
        $appUrl = (string) (getenv('APP_URL') ?: '');
        $path = (string) (parse_url($appUrl, PHP_URL_PATH) ?: '/');
        $path = '/' . trim($path, '/');
        if ($path !== '/') {
            $path .= '/';
        }
        $secure = strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)) === 'https'
            || (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        return '; Path=' . $path
            . '; HttpOnly; SameSite=Lax'
            . ($secure ? '; Secure' : '');
    }
}
