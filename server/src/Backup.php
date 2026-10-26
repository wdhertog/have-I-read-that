<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * A complete SQL dump of the app's tables, made with PHP alone (shared hosting often
 * has no mysqldump). Restore it with phpMyAdmin's import or `mysql < file.sql`.
 */
final class Backup
{
    /** Everything except login_attempts; foreign keys are off while restoring, so order is free. */
    private const TABLES = ['books', 'reads', 'posts', 'notes', 'loans', 'api_tokens', 'goals', 'settings', 'schema_migrations'];

    public function __construct(private PDO $db)
    {
    }

    /** Write the dump to a stream (a file, php://output, …). */
    public function dump($out): void
    {
        fwrite($out, "-- Have I read that? back-up, " . date('c') . "\n");
        fwrite($out, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS = 0;\n\n");
        foreach (self::TABLES as $table) {
            $create = $this->db->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM)[1];
            fwrite($out, "DROP TABLE IF EXISTS `{$table}`;\n{$create};\n");
            $rows = $this->db->query("SELECT * FROM `{$table}`", PDO::FETCH_ASSOC);
            foreach ($rows as $row) {
                $columns = implode(', ', array_map(static fn ($c) => "`{$c}`", array_keys($row)));
                $values = implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : $this->db->quote((string) $v), $row));
                fwrite($out, "INSERT INTO `{$table}` ({$columns}) VALUES ({$values});\n");
            }
            fwrite($out, "\n");
        }
        fwrite($out, "SET FOREIGN_KEY_CHECKS = 1;\n");
    }

    public function toString(): string
    {
        $out = fopen('php://memory', 'r+');
        $this->dump($out);
        rewind($out);
        $sql = (string) stream_get_contents($out);
        fclose($out);
        return $sql;
    }

    /**
     * Write a gzipped dump into $dir and keep only the newest $keep files.
     *
     * @return string The new file's path.
     */
    public function rotate(string $dir, int $keep = 14): string
    {
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Kan map {$dir} niet maken.");
        }
        $file = rtrim($dir, '/') . '/boeken-' . date('Y-m-d-His') . '.sql.gz';
        $gz = gzopen($file, 'wb9');
        if ($gz === false) {
            throw new \RuntimeException("Kan {$file} niet schrijven.");
        }
        gzwrite($gz, $this->toString());
        gzclose($gz);
        chmod($file, 0600);
        $files = glob(rtrim($dir, '/') . '/boeken-*.sql.gz') ?: [];
        rsort($files);
        foreach (array_slice($files, max(1, $keep)) as $old) {
            unlink($old);
        }
        return $file;
    }
}
