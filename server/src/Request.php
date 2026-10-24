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
    ) {
    }

    public static function fromGlobals(string $basePath): self
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
        );
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
