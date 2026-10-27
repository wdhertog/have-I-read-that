<?php

declare(strict_types=1);

namespace App;

/**
 * Ready-made texts to send someone about a book (through Signal, WhatsApp, mail…):
 * what you thought of it, or a friendly request to give it back.
 */
final class Share
{
    /** @param array<string, mixed> $book */
    public static function opinion(array $book, ?string $url = null): string
    {
        $what = "“{$book['title']}”" . ($book['author'] !== '' ? " van {$book['author']}" : '');
        $lines = [match ($book['status']) {
            'reading' => "📖 Ik ben bezig in {$what}.",
            'read' => "📖 Ik las {$what}.",
            'dnf' => "📖 Ik ben gestopt met {$what}.",
            default => "📖 {$what} wil ik nog lezen.",
        }];
        if ($book['rating'] !== null) {
            $lines[] = Mastodon::stars($book['rating']) . ' (' . Mastodon::formatRating($book['rating']) . ')';
        }
        $review = trim((string) ($book['review'] ?? ''));
        if ($review !== '') {
            $lines[] = '';
            $lines[] = mb_strlen($review) > 1500 ? mb_substr($review, 0, 1500) . '…' : $review;
        }
        if ($url !== null) {
            $lines[] = '';
            $lines[] = $url;
        }
        return implode("\n", $lines);
    }

    /** @param array<string, mixed> $book */
    public static function askBack(array $book): ?string
    {
        if (empty($book['lent_to'])) {
            return null;
        }
        $what = "“{$book['title']}”" . ($book['author'] !== '' ? " van {$book['author']}" : '');
        $since = !empty($book['lent_since']) ? ' (sinds ' . date('j-n-Y', (int) strtotime((string) $book['lent_since'])) . ')' : '';
        return "Hoi {$book['lent_to']}! Heb je {$what} nog, dat je van me leende{$since}? "
            . 'Zou ik het terug mogen als je het uit hebt? 😊';
    }
}
