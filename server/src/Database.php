<?php

declare(strict_types=1);

namespace App;

use PDO;

final class Database
{
    public static function connect(Config $config): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            $config->get('DB_HOST', '127.0.0.1'),
            $config->int('DB_PORT', 3306),
            $config->get('DB_NAME', 'books'),
        );
        return new PDO($dsn, $config->get('DB_USER', 'books'), $config->get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
        ]);
    }

    /** Cheap check on every request: only migrate when there are new migration files. */
    public static function migrateIfNeeded(PDO $db, string $dir): void
    {
        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        try {
            $done = (int) $db->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn();
        } catch (\PDOException) {
            $done = 0; // table doesn't exist yet
        }
        if ($done < count($files)) {
            self::migrate($db, $dir);
        }
    }

    /**
     * Apply migrations/*.sql that haven't run yet. Returns the names applied.
     *
     * @return list<string>
     */
    public static function migrate(PDO $db, string $dir): array
    {
        $db->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(100) NOT NULL PRIMARY KEY,
            applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $done = $db->query('SELECT version FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = glob(rtrim($dir, '/') . '/*.sql') ?: [];
        sort($files);
        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if (in_array($version, $done, true)) {
                continue;
            }
            // MySQL commits DDL implicitly, so no transaction around this.
            foreach (self::statements((string) file_get_contents($file)) as $sql) {
                $db->exec($sql);
            }
            $db->prepare('INSERT INTO schema_migrations (version) VALUES (?)')->execute([$version]);
            $applied[] = $version;
        }
        return $applied;
    }

    /** @return list<string> */
    private static function statements(string $sql): array
    {
        $sql = preg_replace('/^\s*--.*$/m', '', $sql);
        return array_values(array_filter(array_map('trim', explode(';', $sql))));
    }
}
