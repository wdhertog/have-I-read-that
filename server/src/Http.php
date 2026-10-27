<?php

declare(strict_types=1);

namespace App;

/**
 * Small HTTPS GET for catalogue lookups: HTTPS only (also after redirects), a size
 * limit and a timeout. Returns null on any failure; never throws.
 */
final class Http
{
    /** Why the last request failed, for bin/lookup.php ("time-out", "HTTP 429"…); null when it worked. */
    public static ?string $lastError = null;

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

    /**
     * The system's own certificate lists, in the usual places. Some hosts' PHP (often
     * the command line) has none configured, or an outdated one that doesn't know the
     * root of a site's certificate ("unable to get local issuer certificate").
     *
     * @return list<string>
     */
    private static function systemCaFiles(): array
    {
        $default = function_exists('openssl_get_cert_locations') ? (string) (openssl_get_cert_locations()['default_cert_file'] ?? '') : '';
        $configured = (string) ini_get('curl.cainfo');
        return array_values(array_filter(
            ['/etc/pki/tls/certs/ca-bundle.crt', '/etc/ssl/certs/ca-certificates.crt', '/etc/pki/ca-trust/extracted/pem/tls-ca-bundle.pem',
             '/etc/ssl/cert.pem', '/usr/local/share/certs/ca-root-nss.crt'],
            static fn (string $file) => $file !== $default && $file !== $configured && is_readable($file),
        ));
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
        if ($ok === false && curl_errno($ch) === CURLE_SSL_CACERT && !isset($options[CURLOPT_CAINFO])) {
            // The certificate couldn't be checked with PHP's list: try the system's. Still checked.
            curl_close($ch);
            foreach (self::systemCaFiles() as $caFile) {
                $result = self::request($url, $options + [CURLOPT_CAINFO => $caFile], $timeout, $maxBytes);
                if ($result !== null || !str_contains((string) self::$lastError, 'certificate')) {
                    return $result;
                }
            }
            self::$lastError = 'certificaat van de site niet te controleren: de certificatenlijst van PHP op deze server is te oud of ontbreekt';
            return null;
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_errno($ch) === CURLE_OPERATION_TIMEDOUT ? "time-out na {$timeout} seconden" : curl_error($ch);
        curl_close($ch);
        self::$lastError = $ok !== false && $code === 200 ? null : ($code > 0 && $code !== 200 ? "HTTP {$code}" : ($error ?: 'geen verbinding'));
        return self::$lastError === null ? $body : null;
    }
}
