<?php

declare(strict_types=1);

namespace App;

/**
 * Turns reading events into Mastodon posts.
 */
final class Tooter
{
    public function __construct(private Books $books, private Mastodon $mastodon, private Config $config)
    {
    }

    public function configured(): bool
    {
        return $this->mastodon->configured();
    }

    /** @param array<string, mixed> $book */
    public function compose(array $book, string $kind): string
    {
        $template = $kind === 'started' ? $this->config->templateStarted() : $this->config->templateFinished();
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
        $url = $this->mastodon->post($text !== '' ? $text : $this->compose($book, $kind), bin2hex(random_bytes(16)));
        // The link is shown on the site and opened by the app: only accept web URLs.
        if ($url !== null && !preg_match('#^https?://#i', $url)) {
            $url = null;
        }
        $this->books->logPost($bookId, $kind, $url);
        return $url;
    }
}
