<?php

declare(strict_types=1);

namespace App;

/**
 * Covers stored on this server, so visitors' browsers never talk to Open Library.
 *
 * In the covers folder:
 * - "book-{id}.jpg"   a cover you uploaded yourself (wins over everything else)
 * - "isbn-{isbn}.jpg" a copy of Open Library's cover
 * - "isbn-{isbn}.none" no source had one; asked again after a week, or right away
 *   when the list of sources changed
 */
final class Covers
{
    private const RETRY_MISSING_AFTER = 7 * 24 * 3600;
    private const MAX_UPLOAD_BYTES = 10 * 1024 * 1024;
    private const MAX_WIDTH = 600;
    private const MAX_HEIGHT = 900;

    /** @var \Closure(?string): ?array{bytes: string, mime: string} */
    private \Closure $fetcher;

    /**
     * @param (callable(?string): ?array{bytes: string, mime: string})|null $fetcher Usually Catalog::cover; Open Library alone by default.
     * @param string $sourcesKey Which sources the fetcher asks, so "none found" is retried when that changes.
     */
    public function __construct(private string $dir, ?callable $fetcher = null, private string $sourcesKey = '')
    {
        $this->fetcher = \Closure::fromCallable($fetcher ?? [OpenLibrary::class, 'fetchCover']);
    }

    /** Whether a cover can exist at all (without fetching anything). */
    public function mayHave(array $book): bool
    {
        return is_file($this->customPath((int) $book['id'])) || !empty($book['isbn']);
    }

    /** Changes when the cover changes, for cache-busting URLs. */
    public function version(array $book): string
    {
        $custom = $this->customPath((int) $book['id']);
        return is_file($custom) ? 'u' . filemtime($custom) : (string) $book['isbn'];
    }

    /**
     * The cover image, fetching and keeping Open Library's on first use.
     *
     * @return array{bytes: string, mime: string}|null
     */
    public function image(array $book): ?array
    {
        $custom = $this->customPath((int) $book['id']);
        if (is_file($custom)) {
            return ['bytes' => (string) file_get_contents($custom), 'mime' => 'image/jpeg'];
        }
        $isbn = StoryGraph::cleanIsbn($book['isbn'] ?? null);
        if ($isbn === null) {
            return null;
        }
        $cached = "{$this->dir}/isbn-{$isbn}.jpg";
        $missing = "{$this->dir}/isbn-{$isbn}.none";
        if (is_file($cached)) {
            return ['bytes' => (string) file_get_contents($cached), 'mime' => 'image/jpeg'];
        }
        if (is_file($missing) && filemtime($missing) > time() - self::RETRY_MISSING_AFTER
            && file_get_contents($missing) === $this->sourcesKey) {
            return null;
        }
        $this->ensureDir();
        $cover = ($this->fetcher)($isbn);
        $jpeg = $cover ? self::toJpeg($cover['bytes']) : null;
        if ($jpeg === null) {
            @file_put_contents($missing, $this->sourcesKey);
            return null;
        }
        file_put_contents($cached, $jpeg, LOCK_EX);
        return ['bytes' => $jpeg, 'mime' => 'image/jpeg'];
    }

    /** Store an uploaded image as the book's own cover. */
    public function saveUpload(int $bookId, string $tmpPath): void
    {
        $size = @filesize($tmpPath);
        if ($size === false || $size > self::MAX_UPLOAD_BYTES) {
            throw new \InvalidArgumentException('De afbeelding is te groot (maximaal 10 MB).');
        }
        $jpeg = self::toJpeg((string) file_get_contents($tmpPath));
        if ($jpeg === null) {
            throw new \InvalidArgumentException('Dat is geen JPEG-, PNG- of WebP-afbeelding.');
        }
        $this->ensureDir();
        file_put_contents($this->customPath($bookId), $jpeg, LOCK_EX);
    }

    /** After merging: the merged book's own cover goes along, unless the kept book has one. */
    public function moveUpload(int $fromId, int $toId): void
    {
        $from = $this->customPath($fromId);
        if (is_file($from)) {
            is_file($this->customPath($toId)) ? @unlink($from) : @rename($from, $this->customPath($toId));
        }
    }

    public function deleteUpload(int $bookId): void
    {
        @unlink($this->customPath($bookId));
    }

    /**
     * Re-encode as a plain JPEG of at most 600×900: drops metadata (like GPS from a
     * phone photo) and anything hidden in the file besides the picture.
     */
    public static function toJpeg(string $bytes): ?string
    {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info['mime'], ['image/jpeg', 'image/png', 'image/webp'], true)
            || $info[0] < 10 || $info[1] < 10 || $info[0] * $info[1] > 16_000_000) {
            return null;
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $scale = min(1, self::MAX_WIDTH / $info[0], self::MAX_HEIGHT / $info[1]);
        $width = max(1, (int) round($info[0] * $scale));
        $height = max(1, (int) round($info[1] * $scale));
        $out = imagecreatetruecolor($width, $height);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255)); // transparent PNGs get white
        imagecopyresampled($out, $image, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);
        ob_start();
        imagejpeg($out, null, 85);
        return (string) ob_get_clean();
    }

    private function customPath(int $bookId): string
    {
        return "{$this->dir}/book-{$bookId}.jpg";
    }

    private function ensureDir(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new \RuntimeException("Kan de map {$this->dir} niet maken.");
        }
    }
}
