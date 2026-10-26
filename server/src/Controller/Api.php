<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Books;
use App\MastodonException;
use App\OpenLibrary;
use App\Request;
use App\Response;
use InvalidArgumentException;

/**
 * JSON API for the Android app. Authenticated with a bearer token.
 */
final class Api
{
    public function __construct(private App $app)
    {
    }

    public function me(Request $request): Response
    {
        return Response::json([
            'ok' => true,
            'mastodon' => [
                'configured' => $this->app->mastodon->configured(),
                'instance' => $this->app->mastodon->instance(),
                'post_by_default' => $this->app->config->bool('MASTODON_POST_BY_DEFAULT', true),
            ],
            'statuses' => Books::STATUSES,
        ]);
    }

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $books = $this->app->books->list($status ?: null, $request->query('q'));
        return Response::json(['books' => array_map([$this, 'present'], $books)]);
    }

    public function show(Request $request, int $id): Response
    {
        $book = $this->app->books->find($id);
        return $book ? Response::json(['book' => $this->detail($book)]) : $this->notFound();
    }

    public function create(Request $request): Response
    {
        $input = $request->json();
        try {
            $data = Books::validate($input);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $id = $this->app->books->create($data, Books::validDate($input['started'] ?? null), Books::validDate($input['finished'] ?? null));
        $toot = null;
        if (!empty($input['toot']) && in_array($data['status'], ['reading', 'read'], true)) {
            $toot = $this->postToot($id, $data['status'] === 'reading' ? 'started' : 'finished');
        }
        return Response::json(['book' => $this->detail($this->app->books->find($id)), 'toot' => $toot], 201);
    }

    public function start(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        $input = $request->json();
        $this->app->books->start($id, Books::validDate($input['date'] ?? null));
        $toot = !empty($input['toot']) ? $this->postToot($id, 'started') : null;
        return Response::json(['book' => $this->detail($this->app->books->find($id)), 'toot' => $toot]);
    }

    public function finish(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        $input = $request->json();
        try {
            $rating = Books::validateRating($input['rating'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $dnf = !empty($input['dnf']);
        try {
            $review = Books::text($input['review'] ?? null, Books::MAX_REVIEW);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $this->app->books->finish($id, Books::validDate($input['date'] ?? null), $rating, $review, $dnf);
        $toot = !$dnf && !empty($input['toot']) ? $this->postToot($id, 'finished') : null;
        return Response::json(['book' => $this->detail($this->app->books->find($id)), 'toot' => $toot]);
    }

    public function toot(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        $input = $request->json();
        $kind = ($input['kind'] ?? '') === 'started' ? 'started' : 'finished';
        try {
            $text = Books::text($input['text'] ?? null, 5000);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $toot = $this->postToot($id, $kind, $text);
        return Response::json(['toot' => $toot], $toot['posted'] ? 200 : 502);
    }

    /** Look up a scanned ISBN: is it already in the library, and what does Open Library know? */
    public function lookup(Request $request): Response
    {
        $isbn = \App\StoryGraph::cleanIsbn($request->query('isbn'));
        if (!$isbn) {
            return Response::json(['error' => 'Ongeldig ISBN.'], 422);
        }
        $existing = $this->app->books->findByIsbn($isbn);
        try {
            $result = OpenLibrary::byIsbn($isbn);
            $error = null;
        } catch (\RuntimeException $e) {
            $result = null;
            $error = $e->getMessage();
        }
        return Response::json([
            'isbn' => $isbn,
            'existing' => $existing ? $this->present($existing) : null,
            'result' => $result,
            'error' => $error,
        ]);
    }

    // --- helpers --------------------------------------------------------------

    /** @return array{posted: bool, url: ?string, error: ?string} */
    private function postToot(int $id, string $kind, ?string $text = null): array
    {
        if (!$this->app->tooter->configured()) {
            return ['posted' => false, 'url' => null, 'error' => 'Mastodon is nog niet ingesteld.'];
        }
        try {
            return ['posted' => true, 'url' => $this->app->tooter->post($id, $kind, $text), 'error' => null];
        } catch (MastodonException $e) {
            return ['posted' => false, 'url' => null, 'error' => $e->getMessage()];
        }
    }

    /**
     * @param array<string, mixed> $book
     * @return array<string, mixed>
     */
    private function present(array $book): array
    {
        $out = [];
        foreach (['id', 'title', 'author', 'isbn', 'format', 'pages', 'status', 'rating', 'review', 'tags',
                  'moods', 'owned', 'date_added'] as $key) {
            $out[$key] = $book[$key] ?? null;
        }
        $out['last_finished'] = $book['last_finished'] ?? null;
        $out['cover_url'] = OpenLibrary::coverUrl($book['isbn'] ?? null);
        return $out;
    }

    /**
     * @param array<string, mixed> $book
     * @return array<string, mixed>
     */
    private function detail(array $book): array
    {
        $reads = $this->app->books->reads($book['id']);
        $finished = array_filter(array_column($reads, 'finished'));
        return $this->present($book + ['last_finished' => $finished ? max($finished) : null]) + [
            'reads' => $reads,
            'posts' => $this->app->books->posts($book['id']),
            'toot_preview' => [
                'started' => $this->app->tooter->compose($book, 'started'),
                'finished' => $this->app->tooter->compose($book, 'finished'),
            ],
        ];
    }

    private function notFound(): Response
    {
        return Response::json(['error' => 'Boek niet gevonden.'], 404);
    }
}
