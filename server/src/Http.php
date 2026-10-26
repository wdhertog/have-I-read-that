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
        if (!str_starts_with($url, 'https://')) {
            return null;
        }
        $body = '';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'have-i-read-that',
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
