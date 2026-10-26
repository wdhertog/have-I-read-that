<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Books;
use App\MastodonException;
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
            'formats' => Books::FORMATS,
            'goal' => $this->app->books->goalProgress((int) date('Y')),
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
        $duplicate = $this->app->books->findDuplicate($data['title'], $data['author'], $data['isbn']);
        if ($duplicate && empty($input['allow_duplicate'])) {
            return Response::json([
                'error' => "“{$duplicate['title']}” staat al in je bibliotheek.",
                'existing' => $this->present($duplicate),
            ], 409);
        }
        $id = $this->app->books->create($data, Books::validDate($input['started'] ?? null), Books::validDate($input['finished'] ?? null));
        $toot = null;
        if (!empty($input['toot']) && in_array($data['status'], ['reading', 'read'], true)) {
            $toot = $this->postToot($id, $data['status'] === 'reading' ? 'started' : 'finished');
        }
        return Response::json(['book' => $this->detail($this->app->books->find($id)), 'toot' => $toot], 201);
    }

    /** Edit a book's details (not its reads or status history). */
    public function update(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        try {
            $this->app->books->update($id, Books::validate($request->json()));
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        return Response::json(['book' => $this->detail($this->app->books->find($id))]);
    }

    public function delete(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        $this->app->books->delete($id);
        $this->app->covers->deleteUpload($id);
        return Response::json(['deleted' => true]);
    }

    public function progress(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        $input = $request->json();
        try {
            $this->app->books->setProgress($id, self::optionalInt($input['page'] ?? null), self::optionalInt($input['percent'] ?? null));
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $toot = !empty($input['toot']) ? $this->postToot($id, 'progress') : null;
        return Response::json(['book' => $this->detail($this->app->books->find($id)), 'toot' => $toot]);
    }

    public function addRead(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        [$started, $finished] = $this->readDates($request->json());
        if (!$started && !$finished) {
            return Response::json(['error' => 'Vul minstens één datum in (JJJJ-MM-DD).'], 422);
        }
        $this->app->books->addRead($id, $started, $finished);
        return Response::json(['book' => $this->detail($this->app->books->find($id))], 201);
    }

    public function updateRead(Request $request, int $id): Response
    {
        [$started, $finished] = $this->readDates($request->json());
        if (!$started && !$finished) {
            return Response::json(['error' => 'Vul minstens één datum in (JJJJ-MM-DD).'], 422);
        }
        $bookId = $this->app->books->updateRead($id, $started, $finished);
        return $bookId ? Response::json(['book' => $this->detail($this->app->books->find($bookId))]) : $this->notFound();
    }

    public function deleteRead(Request $request, int $id): Response
    {
        $bookId = $this->app->books->deleteRead($id);
        return $bookId ? Response::json(['book' => $this->detail($this->app->books->find($bookId))]) : $this->notFound();
    }

    /**
     * @param array<string, mixed> $input
     * @return array{?string, ?string}
     */
    private function readDates(array $input): array
    {
        return [Books::validDate($input['started'] ?? null), Books::validDate($input['finished'] ?? null)];
    }

    /** A whole number from JSON (number or numeric string), or null. */
    private static function optionalInt(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }
        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }
        if (is_string($value) && preg_match('/^\s*-?\d{1,9}\s*$/', $value)) {
            return (int) $value;
        }
        if ($value === null || $value === '') {
            return null;
        }
        throw new InvalidArgumentException('Vul een heel getal in.');
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
        $kind = in_array($input['kind'] ?? null, ['started', 'progress'], true) ? $input['kind'] : 'finished';
        try {
            $text = Books::text($input['text'] ?? null, 5000);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        $toot = $this->postToot($id, $kind, $text);
        return Response::json(['toot' => $toot], $toot['posted'] ? 200 : 502);
    }

    public function loans(Request $request): Response
    {
        return Response::json(['people' => $this->app->loans->byPerson(), 'borrowers' => $this->app->loans->borrowers()]);
    }

    public function lend(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        $input = $request->json();
        try {
            $this->app->loans->lend($id, $input['borrower'] ?? null, Books::validDate($input['date'] ?? null), $input['note'] ?? null);
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        return Response::json(['book' => $this->detail($this->app->books->find($id))]);
    }

    public function returnBook(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        $this->app->loans->returnBook($id, Books::validDate($request->json()['date'] ?? null));
        return Response::json(['book' => $this->detail($this->app->books->find($id))]);
    }

    public function collections(Request $request): Response
    {
        return Response::json(['shelves' => $this->app->books->shelves(), 'series' => $this->app->books->seriesList()]);
    }

    public function addNote(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->notFound();
        }
        try {
            $this->app->books->addNote($id, $request->json());
        } catch (InvalidArgumentException $e) {
            return Response::json(['error' => $e->getMessage()], 422);
        }
        return Response::json(['book' => $this->detail($this->app->books->find($id))], 201);
    }

    public function deleteNote(Request $request, int $id): Response
    {
        $bookId = $this->app->books->deleteNote($id);
        return $bookId ? Response::json(['book' => $this->detail($this->app->books->find($bookId))]) : $this->notFound();
    }

    /** Search Open Library by title, author or ISBN (for books shared from another app). */
    public function search(Request $request): Response
    {
        $q = mb_substr($request->query('q'), 0, 200);
        if ($q === '') {
            return Response::json(['error' => 'Geef een zoekterm.'], 422);
        }
        try {
            return Response::json(['results' => $this->app->catalog->search($q, 10), 'error' => null]);
        } catch (\RuntimeException $e) {
            return Response::json(['results' => [], 'error' => $e->getMessage()]);
        }
    }

    /** Look up a scanned ISBN: is it already in the library, and what does Open Library know? */
    public function lookup(Request $request): Response
    {
        $isbn = \App\StoryGraph::cleanIsbn($request->query('isbn'));
        if (!$isbn) {
            return Response::json(['error' => 'Ongeldig ISBN.'], 422);
        }
        $existing = $this->app->books->findByIsbn($isbn);
        $result = $this->app->catalog->lookup($isbn);
        $error = $result === null ? 'Niet gevonden bij ' . implode(', ', $this->app->catalog->metadataSources()) . '.' : null;
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
        foreach (['contributors', 'content_warnings', 'content_warning_description', 'series', 'series_number'] as $key) {
            $out[$key] = $book[$key] ?? null;
        }
        $out['details'] = (object) ($book['details'] ?? []);
        $out['lent_to'] = $book['lent_to'] ?? null;
        $out['lent_since'] = $book['lent_since'] ?? null;
        $out['publisher'] = $book['publisher'] ?? null;
        $out['published_year'] = $book['published_year'] ?? null;
        $out['progress_page'] = $book['progress_page'] ?? null;
        $out['progress_percent'] = $book['progress_percent'] ?? null;
        $out['cover_url'] = $this->app->coverUrl($book, absolute: true);
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
            'notes' => $this->app->books->notes($book['id']),
            'loans' => $this->app->loans->forBook($book['id']),
            'toot_preview' => [
                'started' => $this->app->tooter->compose($book, 'started'),
                'finished' => $this->app->tooter->compose($book, 'finished'),
                'progress' => $this->app->tooter->compose($book, 'progress'),
            ],
        ];
    }

    private function notFound(): Response
    {
        return Response::json(['error' => 'Boek niet gevonden.'], 404);
    }
}
