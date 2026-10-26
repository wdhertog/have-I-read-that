-- Failed logins per client IP, for rate limiting.
CREATE TABLE login_attempts (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    ip            VARCHAR(45) NOT NULL,
    attempted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY login_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
