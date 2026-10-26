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
        // bcrypt ($2y$ from PHP, $2a$/$2b$ from other tools) or argon2.
        if (preg_match('/^\$(2[aby]|argon2id?)\$/', $expected)) {
            return password_verify($password, $expected);
        }
        return hash_equals($expected, $password);
    }

    /**
     * Log a login attempt and say whether this address may try at all. The attempt is
     * written before the password is checked, so parallel requests can't slip past
     * the limit; a successful login removes it again with clearAttempts().
     */
    public function registerAttempt(string $ip): bool
    {
        $key = self::attemptKey($ip);
        $count = function () use ($key): int {
            $stmt = $this->db->prepare(
                'SELECT COUNT(*) FROM login_attempts WHERE ip = ? AND attempted_at > NOW() - INTERVAL ' . self::LOCKOUT_MINUTES . ' MINUTE',
            );
            $stmt->execute([$key]);
            return (int) $stmt->fetchColumn();
        };
        // Already locked out: don't keep adding rows for someone hammering the form.
        if ($count() >= self::MAX_FAILED_LOGINS) {
            return false;
        }
        $this->db->prepare('INSERT INTO login_attempts (ip) VALUES (?)')->execute([$key]);
        if (random_int(1, 100) === 1) {
            $this->db->exec('DELETE FROM login_attempts WHERE attempted_at < NOW() - INTERVAL 1 DAY');
        }
        return $count() <= self::MAX_FAILED_LOGINS;
    }

    public function clearAttempts(string $ip): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE ip = ?')->execute([self::attemptKey($ip)]);
    }

    /** IPv6 users typically own a whole /64, so count those together. */
    private static function attemptKey(string $ip): string
    {
        $bin = @inet_pton($ip);
        if ($bin !== false && strlen($bin) === 16) {
            return inet_ntop(substr($bin, 0, 8) . str_repeat("\0", 8)) . '/64';
        }
        return $ip;
    }

    /** Only same-site paths like "/books/12", so "next" can't send you to another site. */
    public static function safeRedirectPath(string $next): ?string
    {
        return preg_match('#^/[A-Za-z0-9/_-]*$#', $next) && !str_starts_with($next, '//') ? $next : null;
    }

    public function login(): void
    {
        self::regenerate();
        $_SESSION = ['logged_in' => $this->passwordFingerprint(), 'csrf' => bin2hex(random_bytes(32))];
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
        // Sessions are tied to the current password: changing APP_PASSWORD logs everyone out.
        $session = $_SESSION['logged_in'] ?? null;
        return is_string($session) && $this->passwordConfigured() && hash_equals($this->passwordFingerprint(), $session);
    }

    public function csrfToken(): string
    {
        return $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
    }

    private function passwordFingerprint(): string
    {
        return hash_hmac('sha256', 'session', $this->config->get('APP_PASSWORD'));
    }

    public function checkCsrf(mixed $token): bool
    {
        return is_string($token) && isset($_SESSION['csrf']) && hash_equals($_SESSION['csrf'], $token);
    }

    // --- API tokens -----------------------------------------------------------

    /**
     * Create a token and return the plain value (shown once). A "read" token can only
     * look things up; a "full" one can also change books and post to Mastodon.
     */
    public function createToken(string $name, string $scope = 'full'): string
    {
        $token = 'hirt_' . bin2hex(random_bytes(24));
        $this->db->prepare('INSERT INTO api_tokens (name, token_hash, scope) VALUES (?, ?, ?)')
            ->execute([mb_substr(trim($name) ?: 'Android', 0, 100), hash('sha256', $token), $scope === 'read' ? 'read' : 'full']);
        return $token;
    }

    /** The token's scope ("full" or "read"), or null when it isn't valid. */
    public function checkToken(?string $header): ?string
    {
        if (!$header || !preg_match('/^Bearer\s+(\S+)$/i', $header, $m)) {
            return null;
        }
        $stmt = $this->db->prepare('SELECT id, scope FROM api_tokens WHERE token_hash = ?');
        $stmt->execute([hash('sha256', $m[1])]);
        $token = $stmt->fetch();
        if (!$token) {
            return null;
        }
        $this->db->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = ?')->execute([$token['id']]);
        return $token['scope'] === 'read' ? 'read' : 'full';
    }

    /** @return list<array<string, mixed>> */
    public function tokens(): array
    {
        return $this->db->query('SELECT id, name, scope, created_at, last_used_at FROM api_tokens ORDER BY id DESC')->fetchAll();
    }

    // --- two-step verification ------------------------------------------------

    private const PENDING_SECONDS = 300;

    public function twoFactorEnabled(): bool
    {
        return $this->setting('totp_secret') !== null;
    }

    /** Password was right; the code is still to come. Nothing is unlocked yet. */
    public function beginSecondFactor(): void
    {
        self::regenerate();
        $_SESSION = [
            'pending_2fa' => $this->passwordFingerprint(),
            'pending_until' => time() + self::PENDING_SECONDS,
            'csrf' => bin2hex(random_bytes(32)),
        ];
    }

    public function secondFactorPending(): bool
    {
        $pending = $_SESSION['pending_2fa'] ?? null;
        return is_string($pending) && hash_equals($this->passwordFingerprint(), $pending)
            && ($_SESSION['pending_until'] ?? 0) >= time();
    }

    /** Check a code from the authenticator app, or a recovery code (used up once used). */
    public function verifySecondFactor(string $code): bool
    {
        $secret = $this->setting('totp_secret');
        if ($secret === null) {
            return false;
        }
        $step = Totp::verify($secret, $code, (int) $this->setting('totp_last_step'));
        if ($step !== null) {
            // Only one request can move the step forward, so a code can't be used twice
            // by sending it twice at the same moment.
            $this->db->prepare("INSERT IGNORE INTO settings (name, value) VALUES ('totp_last_step', '0')")->execute();
            $stmt = $this->db->prepare(
                "UPDATE settings SET value = ? WHERE name = 'totp_last_step' AND CAST(value AS SIGNED) < ?",
            );
            $stmt->execute([(string) $step, $step]);
            return $stmt->rowCount() === 1;
        }
        $old = (string) $this->setting('recovery_codes');
        $hashes = json_decode($old, true) ?: [];
        $hash = hash('sha256', strtolower(preg_replace('/[^A-Za-z0-9]/', '', $code)));
        $match = array_search($hash, $hashes, true);
        if ($match === false) {
            return false;
        }
        unset($hashes[$match]);
        // Likewise: only counts if nobody used a code in between.
        $stmt = $this->db->prepare("UPDATE settings SET value = ? WHERE name = 'recovery_codes' AND value = ?");
        $stmt->execute([json_encode(array_values($hashes)), $old]);
        return $stmt->rowCount() === 1;
    }

    public function recoveryCodesLeft(): int
    {
        return count(json_decode((string) $this->setting('recovery_codes'), true) ?: []);
    }

    /**
     * Turn two-step verification on and return 8 recovery codes to show once.
     *
     * @return list<string>
     */
    public function enableTwoFactor(string $secret): array
    {
        $codes = [];
        for ($i = 0; $i < 8; $i++) {
            $raw = strtolower(Totp::base32Encode(random_bytes(7))); // 56 bits, 12 characters
            $codes[] = substr($raw, 0, 4) . '-' . substr($raw, 4, 4) . '-' . substr($raw, 8, 4);
        }
        $hashes = array_map(static fn (string $c) => hash('sha256', str_replace('-', '', $c)), $codes);
        $this->saveSetting('totp_secret', $secret);
        $this->saveSetting('totp_last_step', '0');
        $this->saveSetting('recovery_codes', (string) json_encode($hashes));
        return $codes;
    }

    public function disableTwoFactor(): void
    {
        $this->db->exec("DELETE FROM settings WHERE name IN ('totp_secret', 'totp_last_step', 'recovery_codes')");
    }

    private function setting(string $name): ?string
    {
        $stmt = $this->db->prepare('SELECT value FROM settings WHERE name = ?');
        $stmt->execute([$name]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string) $value;
    }

    private function saveSetting(string $name, string $value): void
    {
        $this->db->prepare('INSERT INTO settings (name, value) VALUES (?, ?) ON DUPLICATE KEY UPDATE value = VALUES(value)')
            ->execute([$name, $value]);
    }

    public function deleteToken(int $id): void
    {
        $this->db->prepare('DELETE FROM api_tokens WHERE id = ?')->execute([$id]);
    }
}
