<?php

namespace Oweb\SearchInsights;

/**
 * Signed tokens for result links, so that the click route
 * only accepts searches and pages from a real results page
 */
class Token
{
    public const MAX_LENGTH = 2048;

    public static function sign(array $data, string $secret): string
    {
        $payload = static::encode(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));

        return $payload . '.' . static::signature($payload, $secret);
    }

    public static function verify(string $token, string $secret): array|null
    {
        if (strlen($token) > static::MAX_LENGTH || substr_count($token, '.') !== 1) {
            return null;
        }

        [$payload, $signature] = explode('.', $token);

        if (hash_equals(static::signature($payload, $secret), $signature) === false) {
            return null;
        }

        $data = json_decode(static::decode($payload) ?? '', true);

        return is_array($data) === true ? $data : null;
    }

    protected static function decode(string $value): string|null
    {
        $decoded = base64_decode(strtr($value, '-_', '+/'), true);

        return $decoded === false ? null : $decoded;
    }

    protected static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    protected static function signature(string $payload, string $secret): string
    {
        return static::encode(hash_hmac('sha256', $payload, $secret, true));
    }
}
