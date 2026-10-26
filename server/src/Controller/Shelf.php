<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Books;
use App\Mastodon;
use App\OpenLibrary;
use App\Request;
use App\Response;

/**
 * The public, read-only page (PUBLIC_PAGE=true). Never shows the to-read list.
 */
final class Shelf
{
    public function __construct(private App $app)
    {
    }

    public function index(Request $request): Response
    {
        $years = $this->app->books->years();
        $year = (int) ($request->query('year') ?: ($years[0]['year'] ?? date('Y')));
        return $this->app->render('shelf', [
            'reading' => $this->app->books->list('reading'),
            'years' => $years,
            'year' => $year,
            'books' => $this->app->books->readInYear($year),
            'goal' => $this->app->books->goalProgress((int) date('Y')),
            'showReviews' => $this->app->config->bool('PUBLIC_SHOW_REVIEWS', true),
        ]);
    }

    /** A public page for one book, with link previews for Mastodon and other sites. */
    public function book(Request $request, int $id): Response
    {
        $book = $this->app->publicPage() ? $this->app->books->find($id) : null;
        if (!$book || !in_array($book['status'], Books::PUBLIC_STATUSES, true)) {
            return $this->app->render('404', [], 404);
        }
        $showReviews = $this->app->config->bool('PUBLIC_SHOW_REVIEWS', true);
        $finished = array_values(array_filter(array_column($this->app->books->reads($id), 'finished')));
        $description = trim(($book['author'] !== '' ? $book['author'] . '. ' : '') . Mastodon::stars($book['rating'])
            . ($showReviews && $book['review'] ? ' ' . $book['review'] : ''));
        return $this->app->render('public_book', [
            'book' => $book,
            'finished' => $finished,
            'showReviews' => $showReviews,
            'title' => $book['title'],
            'meta' => [
                'title' => $book['title'],
                'description' => mb_strimwidth($description, 0, 300, '…'),
                'image' => OpenLibrary::coverUrl($book['isbn'], 'L'),
                'url' => $this->app->absoluteUrl($request, "/boek/{$id}"),
            ],
        ]);
    }

    /** RSS feed of finished books. */
    public function feed(Request $request): Response
    {
        if (!$this->app->publicPage()) {
            return new Response('Niet gevonden', 404);
        }
        $x = static fn (mixed $s) => htmlspecialchars((string) $s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $showReviews = $this->app->config->bool('PUBLIC_SHOW_REVIEWS', true);
        $items = '';
        foreach ($this->app->books->recentlyFinished() as $book) {
            $link = $this->app->absoluteUrl($request, "/boek/{$book['id']}");
            $description = trim(Mastodon::stars($book['rating']) . ($showReviews && $book['review'] ? "\n\n" . $book['review'] : ''));
            $items .= "<item>"
                . '<title>' . $x($book['title'] . ($book['author'] !== '' ? ' – ' . $book['author'] : '')) . '</title>'
                . '<link>' . $x($link) . '</link>'
                . '<guid isPermaLink="false">' . $x("boek-{$book['id']}-{$book['finished']}") . '</guid>'
                . '<pubDate>' . $x(date(DATE_RSS, (int) strtotime($book['finished'] . ' 12:00:00'))) . '</pubDate>'
                . '<description>' . $x($description) . '</description>'
                . "</item>\n";
        }
        $title = $this->app->config->get('PUBLIC_TITLE', 'Wat ik lees');
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<rss version="2.0"><channel>'
            . '<title>' . $x($title) . '</title>'
            . '<link>' . $x($this->app->absoluteUrl($request, '/')) . '</link>'
            . '<description>' . $x("{$title}: uitgelezen boeken") . '</description>'
            . '<language>nl</language>' . "\n" . $items
            . '</channel></rss>';
        return new Response($xml, 200, ['Content-Type' => 'application/rss+xml; charset=utf-8']);
    }
}
