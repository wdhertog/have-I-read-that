<?php

declare(strict_types=1);

namespace App;

/**
 * Turns reading events into Mastodon posts.
 */
final class Tooter
{
    /** @var \Closure(?string): ?array{bytes: string, mime: string} */
    private \Closure $coverFetcher;

    /** @param (callable(?string): ?array{bytes: string, mime: string})|null $coverFetcher For tests; defaults to Open Library. */
    public function __construct(
        private Books $books,
        private Mastodon $mastodon,
        private Config $config,
        ?callable $coverFetcher = null,
    ) {
        $this->coverFetcher = \Closure::fromCallable($coverFetcher ?? [OpenLibrary::class, 'fetchCover']);
    }

    public function configured(): bool
    {
        return $this->mastodon->configured();
    }

    /** @param array<string, mixed> $book */
    public function compose(array $book, string $kind): string
    {
        $template = match ($kind) {
            'started' => $this->config->templateStarted(),
            'progress' => $this->config->templateProgress(),
            default => $this->config->templateFinished(),
        };
        $book['url'] = $this->publicUrl($book);
        return Mastodon::compose($template, $book, $this->config->int('MASTODON_MAX_CHARS', 500));
    }

    /**
     * Post about a book and log it. Returns the toot URL.
     *
     * @throws MastodonException
     */
    public function post(int $bookId, string $kind, ?string $text = null): ?string
    {
        $book = $this->books->find($bookId) ?? throw new MastodonException('Boek niet gevonden.');
        $text = trim((string) $text);
        $url = $this->mastodon->post(
            $text !== '' ? $text : $this->compose($book, $kind),
            bin2hex(random_bytes(16)),
            $this->coverMedia($book),
        );
        // The link is shown on the site and opened by the app: only accept web URLs.
        if ($url !== null && !preg_match('#^https?://#i', $url)) {
            $url = null;
        }
        $this->books->logPost($bookId, $kind, $url);
        return $url;
    }

    /**
     * Upload the book's cover, with alt text, when there is one. A missing cover or a
     * failed upload never stops the toot itself.
     *
     * @param array<string, mixed> $book
     * @return list<string>
     */
    private function coverMedia(array $book): array
    {
        if (!$this->config->bool('MASTODON_ATTACH_COVER', true) || empty($book['isbn'])) {
            return [];
        }
        $cover = ($this->coverFetcher)($book['isbn']);
        if ($cover === null) {
            return [];
        }
        $alt = 'Omslag van ' . $book['title'] . ($book['author'] !== '' ? ' door ' . $book['author'] : '');
        try {
            return [$this->mastodon->uploadMedia($cover['bytes'], $cover['mime'], $alt)];
        } catch (MastodonException $e) {
            error_log('Cover upload failed: ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Link to the book's public page for the {url} placeholder; empty without APP_URL,
     * without a public page, or for books the public page doesn't show.
     *
     * @param array<string, mixed> $book
     */
    private function publicUrl(array $book): string
    {
        $base = rtrim($this->config->get('APP_URL'), '/');
        if ($base === '' || !$this->config->bool('PUBLIC_PAGE', true) || !in_array($book['status'] ?? '', Books::PUBLIC_STATUSES, true)) {
            return '';
        }
        return $base . '/boek/' . $book['id'];
    }
}
