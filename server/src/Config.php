<?php

declare(strict_types=1);

namespace App;

/**
 * Settings come from environment variables, optionally loaded from a .env file.
 */
final class Config
{
    public const DEFAULT_TEMPLATE_FINISHED = "📖 Uitgelezen: {title} – {author}\n{stars}\n\n{review}\n\n#boeken #bookstodon";
    public const DEFAULT_TEMPLATE_STARTED = "📚 Begonnen aan: {title} – {author}\n\n#boeken #bookstodon";
    public const DEFAULT_TEMPLATE_PROGRESS = "📖 {percent}% gelezen van {title} – {author}\n\n#boeken #bookstodon";

    /** @param array<string, string> $values */
    public function __construct(private array $values)
    {
    }

    public static function load(string $envFile): self
    {
        $values = [];
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = array_map('trim', explode('=', $line, 2));
                if (strlen($value) >= 2 && ($value[0] === '"' || $value[0] === "'") && $value[-1] === $value[0]) {
                    $value = substr($value, 1, -1);
                }
                $values[$key] = $value;
            }
        }
        // Real environment variables win over the .env file.
        foreach (getenv() as $key => $value) {
            $values[$key] = $value;
        }
        return new self($values);
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->values[$key] ?? '';
        return $value === '' ? $default : $value;
    }

    public function bool(string $key, bool $default = false): bool
    {
        $value = strtolower($this->get($key));
        return $value === '' ? $default : in_array($value, ['1', 'true', 'yes', 'on'], true);
    }

    public function int(string $key, int $default): int
    {
        $value = $this->get($key);
        return is_numeric($value) ? (int) $value : $default;
    }

    public function templateFinished(): string
    {
        return str_replace('\n', "\n", $this->get('MASTODON_TEMPLATE_FINISHED', self::DEFAULT_TEMPLATE_FINISHED));
    }

    public function templateProgress(): string
    {
        return str_replace('\n', "\n", $this->get('MASTODON_TEMPLATE_PROGRESS', self::DEFAULT_TEMPLATE_PROGRESS));
    }

    public function templateStarted(): string
    {
        return str_replace('\n', "\n", $this->get('MASTODON_TEMPLATE_STARTED', self::DEFAULT_TEMPLATE_STARTED));
    }
}
