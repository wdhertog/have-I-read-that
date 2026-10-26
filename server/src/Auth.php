<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * Single-user login (session + CSRF) for the web UI, bearer tokens for the app.
 */
final class Auth
{
    private const MAX_FAILED_LOGINS = 10;
    private const LOCKOUT_MINUTES = 15;
    private const EXAMPLE_PASSWORD = 'verander-mij';

    public function __construct(private Config $config, private PDO $db)
    {
    }

    /** False when unset or still the placeholder from .env.example. */
    public function passwordConfigured(): bool
    {
        return !in_array($this->config->get('APP_PASSWORD'), ['', self::EXAMPLE_PASSWORD], true);
    }

    /** APP_PASSWORD may be plain text or a password_hash() result. */
    public function checkPassword(string $password): bool
    {
        $expected = $this->config->get('APP_PASSWORD');
        if (!$this->passwordConfigured()) {
            return false;
        }
        if (str_starts_with($expected, '$2y$') || str_starts_with($expected, '$argon2')) {
            return password_verify($password, $expected);
        }
        return hash_equals($expected, $password);
    }

    public function tooManyAttempts(string $ip): bool
    {
        $stmt = $this->db->prepare(
            'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL ' . self::LOCKOUT_MINUTES . ' MINUTE',
        );
        $stmt->execute([$ip]);
        return (int) $stmt->fetchColumn() >= self::MAX_FAILED_LOGINS;
    }

    public function recordFailedLogin(string $ip): void
    {
        $this->db->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$ip]);
        $this->db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
    }

    public function clearFailedLogins(string $ip): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([$ip]);
    }

    /** Only same-site paths like "/books/12", so "next" can't send you to another site. */
    public static function safeRedirectPath(string $next): ?string
    {
        return preg_match('#^/[A-Za-z0-9/_-]*$#', $next) && !str_starts_with($next, '//') ? $next : null;
    }

    public function login(): void
    {
        self::regenerate();
        $_SESSION = ['logged_in' => true, 'csrf' => bin2hex(random_bytes(32))];
    }

    public function logout(): void
    {
        $_SESSION = [];
        self::regenerate();
    }

    private static function regenerate(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id(true);
        }
    }

    public function loggedIn(): bool
    {
        return !empty($_SESSION['logged_in']);
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    public function checkCsrf(?string $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    // --- API tokens -----------------------------------------------------------

    /** Create a token and return the plain value (shown once). */
    public function createToken(string $name): string
    {
        $token = 'hirt_' . bin2hex(random_bytes(24));
        $this->db->prepare('INSERT INTO api_tokens (name, token_hash) VALUES (?, ?)')
            ->execute([mb_substr(trim($name) ?: 'Android', 0, 100), hash('sha256', $token)]);
        return $token;
    }

    public function checkToken(?string $header): bool
    {
        if (!$header || !preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return false;
        }
        $stmt = $this->db->prepare('SELECT id FROM api_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $m[1])]);
        $id = $stmt->fetchColumn();
        if ($id === false) {
            return false;
        }
        $this->db->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$id]);
        return true;
    }

    /** @return list<array<string, mixed>> */
    public function tokens(): array
    {
        return $this->db->query('SELECT id, name, created_at, last_used_at FROM api_tokens ORDER BY id DESC')->fetchAll();
    }

    public function deleteToken(int $id): void
    {
        $this->db->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$id]);
    }
}
