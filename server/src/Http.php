<?php

declare(strict_types=1);

namespace App;

/**
 * Small HTTPS GET for catalogue lookups: HTTPS only (also after redirects), a size
 * limit and a timeout. Returns null on any failure; never throws.
 */
final class Http
{
    public static function get(string $url, int $timeout = 10, int $maxBytes = 5 * 1024 * 1024): ?string
    {
        return self::request($url, [], $timeout, $maxBytes);
    }

    /**
     * POST a JSON body (for GraphQL APIs).
     *
     * @param list<string> $headers e.g. ['Authorization: Bearer …']
     */
    public static function postJson(string $url, string $json, array $headers = [], int $timeout = 10): ?string
    {
        return self::request($url, [
            CURLOPT_FOLLOWLOCATION => false, // the token is for this URL only
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $json,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json', ...$headers],
        ], $timeout, 5 * 1024 * 1024);
    }

    /** @param array<int, mixed> $options */
    private static function request(string $url, array $options, int $timeout, int $maxBytes): ?string
    {
        if (!str_starts_with($url, 'https://')) {
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, $options + [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeout,
            // Wikimedia asks for a name and a way to find out who's calling.
            CURLOPT_USERAGENT => 'have-i-read-that/1.0 (+https://github.com/wdhertog/have-I-read-that)',
            CURLOPT_WRITEFUNCTION => static function ($ch, string $chunk) use (&$body, $maxBytes): int {
                $body .= $chunk;
                return strlen($body) > $maxBytes ? 0 : strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $ok !== false && $code === 200 ? $body : null;
    }
}
