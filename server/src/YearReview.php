<?php

declare(strict_types=1);

namespace App;

/**
 * The year-in-review toot: how many books, the favourite, and a collage of covers.
 */
final class YearReview
{
    private const MAX_COVERS = 16;
    private const CELL_WIDTH = 200;
    private const CELL_HEIGHT = 300;
    /** Stop fetching covers after this many seconds; the collage uses what it has. */
    private const FETCH_BUDGET = 25;

    /** @var \Closure(array<string, mixed>): ?array{bytes: string, mime: string} */
    private \Closure $coverFetcher;

    /** @param callable(array<string, mixed>): ?array{bytes: string, mime: string} $coverFetcher Usually Covers::image. */
    public function __construct(private Books $books, private Mastodon $mastodon, callable $coverFetcher)
    {
        $this->coverFetcher = \Closure::fromCallable($coverFetcher);
    }

    /**
     * The books read in a year, each once (a reread counts once), latest first.
     *
     * @return list<array<string, mixed>>
     */
    public function books(int $year): array
    {
        $seen = [];
        foreach ($this->books->readInYear($year) as $book) {
            $seen[$book['id']] ??= $book;
        }
        return array_values($seen);
    }

    public function compose(int $year): string
    {
        $books = $this->books($year);
        $count = count($books);
        if ($count === 0) {
            return "📚 Mijn leesjaar {$year}: nog geen boeken uitgelezen.";
        }
        $pages = array_sum(array_map(static fn (array $b) => (int) $b['pages'], $books));
        $lines = ["📚 Mijn leesjaar {$year}: {$count} " . ($count === 1 ? 'boek' : 'boeken')
            . ($pages > 0 ? ', ' . number_format($pages, 0, ',', '.') . " pagina's" : '') . '.'];
        $rated = array_filter($books, static fn (array $b) => $b['rating'] !== null);
        if ($rated) {
            // Highest rating; on a tie the one finished last (the list is latest first).
            usort($rated, static fn (array $a, array $b) => $b['rating'] <=> $a['rating']);
            $favourite = $rated[0];
            $lines[] = 'Favoriet: ' . $favourite['title'] . ($favourite['author'] !== '' ? ' – ' . $favourite['author'] : '')
                . ' ' . Mastodon::stars($favourite['rating']);
        }
        $lines[] = '';
        $lines[] = "#boeken #bookstodon #leesjaar{$year}";
        return implode("\n", $lines);
    }

    /**
     * A JPEG grid of the covers of the year's books, with alt text. Null when GD is
     * missing or none of the books has a cover.
     *
     * @return array{bytes: string, alt: string}|null
     */
    public function collage(int $year): ?array
    {
        if (!function_exists('imagecreatetruecolor')) {
            return null;
        }
        $covers = [];
        $deadline = microtime(true) + self::FETCH_BUDGET;
        foreach ($this->books($year) as $book) {
            if (count($covers) >= self::MAX_COVERS || microtime(true) > $deadline) {
                break;
            }
            try {
                $cover = ($this->coverFetcher)($book);
            } catch (\RuntimeException) {
                $cover = null;
            }
            $image = $cover ? @imagecreatefromstring($cover['bytes']) : false;
            if ($image !== false) {
                $covers[] = ['image' => $image, 'book' => $book];
            }
        }
        if (!$covers) {
            return null;
        }

        $columns = min(4, (int) ceil(sqrt(count($covers))));
        $rows = (int) ceil(count($covers) / $columns);
        $canvas = imagecreatetruecolor($columns * self::CELL_WIDTH, $rows * self::CELL_HEIGHT);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 246, 243, 238));
        foreach ($covers as $i => ['image' => $image]) {
            $this->drawCover($canvas, $image, ($i % $columns) * self::CELL_WIDTH, intdiv($i, $columns) * self::CELL_HEIGHT);
            imagedestroy($image);
        }
        ob_start();
        imagejpeg($canvas, null, 85);
        $bytes = (string) ob_get_clean();
        imagedestroy($canvas);

        $titles = array_map(
            static fn (array $c) => $c['book']['title'] . ($c['book']['author'] !== '' ? ' (' . $c['book']['author'] . ')' : ''),
            $covers,
        );
        $alt = 'Collage van de omslagen van ' . count($covers) . " boeken die ik in {$year} las: " . implode(', ', $titles) . '.';
        return ['bytes' => $bytes, 'alt' => mb_strimwidth($alt, 0, 1500, '…')];
    }

    /** Post the review (with the collage when there is one) and return the toot URL. */
    public function post(int $year, ?string $text = null, bool $withCollage = true): ?string
    {
        $text = trim((string) $text) ?: $this->compose($year);
        $media = [];
        if ($withCollage && ($collage = $this->collage($year)) !== null) {
            try {
                $media[] = $this->mastodon->uploadMedia($collage['bytes'], 'image/jpeg', $collage['alt']);
            } catch (MastodonException $e) {
                error_log('Collage upload failed: ' . $e->getMessage());
            }
        }
        return $this->mastodon->post($text, bin2hex(random_bytes(16)), $media);
    }

    /** Scale a cover to fill its cell, cropping what sticks out (covers vary in shape). */
    private function drawCover(\GdImage $canvas, \GdImage $cover, int $x, int $y): void
    {
        $width = imagesx($cover);
        $height = imagesy($cover);
        $scale = max(self::CELL_WIDTH / $width, self::CELL_HEIGHT / $height);
        $cropWidth = (int) round(self::CELL_WIDTH / $scale);
        $cropHeight = (int) round(self::CELL_HEIGHT / $scale);
        imagecopyresampled(
            $canvas, $cover,
            $x, $y, intdiv($width - $cropWidth, 2), intdiv($height - $cropHeight, 2),
            self::CELL_WIDTH, self::CELL_HEIGHT, $cropWidth, $cropHeight,
        );
    }
}
