<?php

declare(strict_types=1);

namespace App;

/**
 * Minimal Mastodon client: compose and send a status.
 */
final class Mastodon
{
    private string $instance;

    public function __construct(string $instance, private string $token, private string $visibility = 'public')
    {
        $instance = rtrim(trim($instance), '/');
        if ($instance !== '' && !preg_match('#^https?://#', $instance)) {
            $instance = 'https://' . $instance;
        }
        $this->instance = $instance;
        $this->token = trim($token);
    }

    public static function fromConfig(Config $config): self
    {
        return new self(
            $config->get('MASTODON_INSTANCE'),
            $config->get('MASTODON_ACCESS_TOKEN'),
            $config->get('MASTODON_VISIBILITY', 'public'),
        );
    }

    public function configured(): bool
    {
        return $this->instance !== '' && $this->token !== '';
    }

    public function instance(): string
    {
        return $this->instance;
    }

    public function visibility(): string
    {
        return $this->visibility;
    }

    /** @return array<string, mixed> The account the token belongs to. */
    public function verify(): array
    {
        return $this->request('GET', '/api/v1/accounts/verify_credentials');
    }

    /** Post a status and return its public URL. */
    /** @param list<string> $mediaIds From uploadMedia(). */
    public function post(string $text, ?string $idempotencyKey = null, array $mediaIds = []): ?string
    {
        $headers = $idempotencyKey ? ['Idempotency-Key: ' . $idempotencyKey] : [];
        $body = http_build_query(['status' => $text, 'visibility' => $this->visibility]);
        // Rails wants media_ids[]=…, not the media_ids[0]=… http_build_query would make.
        foreach ($mediaIds as $id) {
            $body .= '&media_ids%5B%5D=' . rawurlencode($id);
        }
        $status = $this->request('POST', '/api/v1/statuses', $body, $headers);
        return $status['url'] ?? $status['uri'] ?? null;
    }

    /**
     * Upload an image with its alt text and return the media id. Waits a few seconds
     * when Mastodon processes it in the background.
     */
    public function uploadMedia(string $bytes, string $mime, string $description): string
    {
        $file = new \CURLStringFile($bytes, $mime === 'image/png' ? 'image.png' : 'image.jpg', $mime);
        $media = $this->request('POST', '/api/v2/media', [
            'file' => $file,
            'description' => mb_substr($description, 0, 1500),
        ], [], 60);
        $id = (string) ($media['id'] ?? '');
        if ($id === '') {
            throw new MastodonException('Mastodon gaf geen media-id terug.');
        }
        for ($i = 0; ($media['url'] ?? null) === null && $i < 10; $i++) {
            usleep(500_000);
            $media = $this->request('GET', '/api/v1/media/' . rawurlencode($id));
        }
        return $id;
    }

    /**
     * @param array<string, mixed>|string|null $data A form body, or an array for multipart.
     * @param list<string> $headers
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array|string|null $data = null, array $headers = [], int $timeout = 15): array
    {
        if (!$this->configured()) {
            throw new MastodonException('Mastodon is niet geconfigureerd.');
        }
        $ch = curl_init($this->instance . $path);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => 'have-i-read-that',
            CURLOPT_HTTPHEADER => array_merge(['Authorization: Bearer ' . $this->token], $headers),
        ]);
        if ($data !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        }
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);
        if ($body === false) {
            throw new MastodonException("Kon {$this->instance} niet bereiken: {$error}");
        }
        $json = json_decode((string) $body, true);
        if ($code >= 400) {
            $detail = is_array($json) ? ($json['error'] ?? '') : '';
            throw new MastodonException("Mastodon gaf HTTP {$code}" . ($detail ? ": {$detail}" : ''));
        }
        return is_array($json) ? $json : [];
    }

    public static function stars(float|string|null $rating): string
    {
        if (!$rating) {
            return '';
        }
        $halves = (int) floor((float) $rating * 2 + 0.5);
        $full = intdiv($halves, 2);
        $half = $halves % 2;
        return str_repeat('★', $full) . ($half ? '½' : '') . str_repeat('☆', 5 - $full - $half);
    }

    public static function formatRating(float|string|null $rating): string
    {
        return $rating ? rtrim(rtrim(number_format((float) $rating, 2, '.', ''), '0'), '.') : '';
    }

    /**
     * Fill a template for a book, shortening the review if the toot gets too long.
     *
     * @param array<string, mixed> $book
     */
    public static function compose(string $template, array $book, int $maxChars = 500): string
    {
        $review = trim((string) ($book['review'] ?? ''));
        $fields = [
            '{title}' => (string) ($book['title'] ?? ''),
            '{author}' => ($book['author'] ?? '') !== '' ? (string) $book['author'] : 'onbekend',
            '{stars}' => self::stars($book['rating'] ?? null),
            '{rating}' => ($book['rating'] ?? null) ? self::formatRating($book['rating']) . '/5' : '',
            '{isbn}' => (string) ($book['isbn'] ?? ''),
            '{percent}' => isset($book['progress_percent']) ? (string) $book['progress_percent'] : '',
            '{page}' => isset($book['progress_page']) ? (string) $book['progress_page'] : '',
            '{pages}' => isset($book['pages']) ? (string) $book['pages'] : '',
            '{url}' => (string) ($book['url'] ?? ''),
        ];
        $render = static function (string $reviewText) use ($template, $fields): string {
            $text = strtr($template, $fields + ['{review}' => $reviewText]);
            $text = implode("\n", array_map('rtrim', explode("\n", $text)));
            return trim((string) preg_replace("/\n{3,}/", "\n\n", $text));
        };

        $text = $render($review);
        $length = mb_strlen($text);
        if ($length > $maxChars && $review !== '') {
            $keep = mb_strlen($review) - ($length - $maxChars) - 1;
            $text = $render($keep > 0 ? rtrim(mb_substr($review, 0, $keep)) . '…' : '');
        }
        return mb_substr($text, 0, $maxChars);
    }
}
