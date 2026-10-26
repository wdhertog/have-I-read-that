<?php

declare(strict_types=1);

namespace App;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, mixed> $post
     * @param array<string, mixed> $files
     * @param array<string, string> $headers Lower-cased names.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $post = [],
        public readonly array $files = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $ip = '',
        public readonly bool $https = false,
    ) {
    }

    /** @param list<string> $trustedProxies IPs or CIDR ranges of reverse proxies in front of the app. */
    public static function fromGlobals(string $basePath, array $trustedProxies = []): self
    {
        $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        if ($basePath !== '' && str_starts_with($path, $basePath)) {
            $path = substr($path, strlen($basePath));
        }
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with($key, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($key, 5)))] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        // Some Apache setups hide the Authorization header from PHP.
        $headers['authorization'] ??= (string) ($_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            '/' . ltrim($path, '/'),
            $_GET,
            $_POST,
            $_FILES,
            $headers,
            (string) file_get_contents('php://input'),
            self::clientIp((string) ($_SERVER['REMOTE_ADDR'] ?? ''), (string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''), $trustedProxies),
            ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https',
        );
    }

    /**
     * The visitor's address. X-Forwarded-For can be forged by anyone, so it is only
     * read when the request comes from a trusted proxy, and then from the right:
     * the first address that isn't one of our proxies is the client.
     *
     * @param list<string> $trustedProxies
     */
    public static function clientIp(string $remoteAddr, string $forwardedFor, array $trustedProxies): string
    {
        if (!$trustedProxies || !self::inRanges($remoteAddr, $trustedProxies)) {
            return $remoteAddr;
        }
        $chain = array_reverse(array_filter(array_map('trim', explode(',', $forwardedFor))));
        foreach ($chain as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
                break;
            }
            if (!self::inRanges($ip, $trustedProxies)) {
                return $ip;
            }
        }
        return $remoteAddr;
    }

    /** @param list<string> $ranges */
    public static function inRanges(string $ip, array $ranges): bool
    {
        $bin = @inet_pton($ip);
        if ($bin === false) {
            return false;
        }
        foreach ($ranges as $range) {
            [$net, $bits] = array_pad(explode('/', trim($range), 2), 2, null);
            $netBin = @inet_pton($net);
            if ($netBin === false || strlen($netBin) !== strlen($bin)) {
                continue;
            }
            $bits = $bits === null ? strlen($bin) * 8 : (int) $bits;
            $bytes = intdiv($bits, 8);
            $mask = $bits % 8 ? chr((0xFF << (8 - $bits % 8)) & 0xFF) : '';
            if (substr($bin, 0, $bytes) === substr($netBin, 0, $bytes)
                && ($mask === '' || (($bin[$bytes] & $mask) === ($netBin[$bytes] & $mask)))) {
                return true;
            }
        }
        return false;
    }

    public function header(string $name): ?string
    {
        $value = $this->headers[strtolower($name)] ?? '';
        return $value === '' ? null : $value;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->post[$key] ?? $default;
    }

    public function query(string $key, string $default = ''): string
    {
        $value = $this->query[$key] ?? $default;
        return is_string($value) ? trim($value) : $default;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        $data = json_decode($this->body, true);
        return is_array($data) ? $data : [];
    }
}
