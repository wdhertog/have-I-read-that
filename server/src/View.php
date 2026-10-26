<?php

declare(strict_types=1);

namespace App;

/**
 * Plain-PHP templates from templates/. Inside a template, $v is this View.
 */
final class View
{
    /** @var array<string, mixed> */
    private array $globals = [];

    /** @var \Closure(array<string, mixed>): ?string */
    private \Closure $coverUrl;

    /** @param callable(array<string, mixed>): ?string $coverUrl */
    public function __construct(private string $dir, private string $basePath, callable $coverUrl)
    {
        $this->coverUrl = \Closure::fromCallable($coverUrl);
    }

    public function share(string $key, mixed $value): void
    {
        $this->globals[$key] = $value;
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = [], ?string $layout = 'layout'): string
    {
        $content = $this->partial($template, $vars);
        return $layout ? $this->partial($layout, $vars + ['content' => $content]) : $content;
    }

    /** @param array<string, mixed> $vars */
    public function partial(string $template, array $vars = []): string
    {
        $v = $this;
        extract($this->globals + $vars, EXTR_SKIP);
        ob_start();
        try {
            include $this->dir . '/' . $template . '.php';
        } finally {
            $output = (string) ob_get_clean();
        }
        return $output;
    }

    public function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /** @param array<string, mixed> $query */
    public function url(string $path, array $query = []): string
    {
        $query = array_filter($query, static fn ($v) => $v !== null && $v !== '');
        return $this->basePath . $path . ($query ? '?' . http_build_query($query) : '');
    }

    public function stars(mixed $rating): string
    {
        return Mastodon::stars($rating);
    }

    public function rating(mixed $rating): string
    {
        return Mastodon::formatRating($rating);
    }

    /** @param array<string, mixed> $book */
    public function cover(array $book): ?string
    {
        return ($this->coverUrl)($book);
    }

    public function csrf(): string
    {
        return '<input type="hidden" name="_csrf" value="' . $this->e($this->globals['csrf'] ?? '') . '">';
    }
}
