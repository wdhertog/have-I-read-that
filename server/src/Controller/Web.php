<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Books;
use App\MastodonException;
use App\OpenLibrary;
use App\Request;
use App\Response;
use App\StoryGraph;
use InvalidArgumentException;

/**
 * The logged-in web interface.
 */
final class Web
{
    public function __construct(private App $app)
    {
    }

    // --- auth -----------------------------------------------------------------

    public function loginForm(Request $request): Response
    {
        if ($this->app->auth->loggedIn()) {
            return Response::redirect($this->app->url('/books'));
        }
        return $this->app->render('login', ['configured' => $this->app->auth->passwordConfigured()]);
    }

    public function login(Request $request): Response
    {
        $auth = $this->app->auth;
        $next = Auth::safeRedirectPath($request->query('next'));
        if (!$auth->registerAttempt($request->ip)) {
            $this->app->flash('error', 'Te veel mislukte pogingen. Probeer het over een kwartier opnieuw.');
            return Response::redirect($this->app->url('/login', ['next' => $next]));
        }
        $password = $request->input('password', '');
        if (is_string($password) && $auth->checkPassword($password)) {
            $auth->clearAttempts($request->ip);
            $auth->login();
            return Response::redirect($this->app->url($next ?? '/books'));
        }
        sleep(1);
        $this->app->flash('error', 'Onjuist wachtwoord.');
        return Response::redirect($this->app->url('/login', ['next' => $next]));
    }

    public function logout(Request $request): Response
    {
        $this->app->auth->logout();
        return Response::redirect($this->app->url('/'));
    }

    // --- books ----------------------------------------------------------------

    public function index(Request $request): Response
    {
        $status = $request->query('status');
        $q = $request->query('q');
        return $this->app->render('books', [
            'books' => $this->app->books->list($status ?: null, $q),
            'counts' => $this->app->books->counts(),
            'status' => $status,
            'q' => $q,
        ]);
    }

    public function addForm(Request $request): Response
    {
        $q = $request->query('q');
        $results = null;
        if ($q !== '') {
            try {
                $results = OpenLibrary::search($q);
            } catch (\RuntimeException $e) {
                $this->app->flash('error', 'Zoeken bij Open Library mislukt: ' . $e->getMessage());
                $results = [];
            }
        }
        $book = [];
        foreach (['title', 'author', 'isbn', 'pages'] as $key) {
            $book[$key] = $request->query($key);
        }
        $book['status'] = $request->query('status', 'read');
        return $this->app->render('book_form', ['book' => $book, 'results' => $results, 'q' => $q, 'edit' => false]);
    }

    public function add(Request $request): Response
    {
        try {
            $data = Books::validate($request->post);
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
            return $this->app->render('book_form', ['book' => $request->post, 'results' => null, 'q' => '', 'edit' => false], 422);
        }
        $id = $this->app->books->create(
            $data,
            Books::validDate($request->input('started')),
            Books::validDate($request->input('finished')),
        );
        $this->app->flash('ok', 'Boek toegevoegd.');
        if ($this->wantsToot($request) && in_array($data['status'], ['reading', 'read'], true)) {
            $this->postToot($id, $data['status'] === 'reading' ? 'started' : 'finished');
        }
        return $this->toBook($id);
    }

    public function show(Request $request, int $id): Response
    {
        $book = $this->app->books->find($id);
        if (!$book) {
            return $this->app->render('404', [], 404);
        }
        return $this->app->render('book', [
            'book' => $book,
            'reads' => $this->app->books->reads($id),
            'posts' => $this->app->books->posts($id),
            'tootKind' => $book['status'] === 'reading' ? 'started' : 'finished',
            'tootText' => $this->app->tooter->compose($book, $book['status'] === 'reading' ? 'started' : 'finished'),
        ]);
    }

    public function editForm(Request $request, int $id): Response
    {
        $book = $this->app->books->find($id);
        if (!$book) {
            return $this->app->render('404', [], 404);
        }
        return $this->app->render('book_form', ['book' => $book, 'edit' => true, 'id' => $id]);
    }

    public function edit(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->app->render('404', [], 404);
        }
        try {
            $this->app->books->update($id, Books::validate($request->post));
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
            return $this->app->render('book_form', ['book' => $request->post, 'edit' => true, 'id' => $id], 422);
        }
        $this->app->flash('ok', 'Opgeslagen.');
        return $this->toBook($id);
    }

    public function delete(Request $request, int $id): Response
    {
        $this->app->books->delete($id);
        $this->app->flash('ok', 'Boek verwijderd.');
        return Response::redirect($this->app->url('/books'));
    }

    public function start(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->app->render('404', [], 404);
        }
        $this->app->books->start($id, Books::validDate($request->input('started')));
        $this->app->flash('ok', 'Veel leesplezier!');
        if ($this->wantsToot($request)) {
            $this->postToot($id, 'started');
        }
        return $this->toBook($id);
    }

    public function finish(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->app->render('404', [], 404);
        }
        try {
            $rating = Books::validateRating($request->input('rating'));
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
            return $this->toBook($id);
        }
        try {
            $review = Books::text($request->input('review'), Books::MAX_REVIEW);
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
            return $this->toBook($id);
        }
        $dnf = $request->input('dnf') === 'on';
        $this->app->books->finish($id, Books::validDate($request->input('finished')), $rating, $review, $dnf);
        $this->app->flash('ok', $dnf ? 'Gemarkeerd als niet uitgelezen.' : 'Uitgelezen! 🎉');
        if (!$dnf && $this->wantsToot($request)) {
            $this->postToot($id, 'finished');
        }
        return $this->toBook($id);
    }

    public function toot(Request $request, int $id): Response
    {
        $kind = $request->input('kind') === 'started' ? 'started' : 'finished';
        try {
            $this->postToot($id, $kind, Books::text($request->input('text'), 5000));
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
        }
        return $this->toBook($id);
    }

    public function addRead(Request $request, int $id): Response
    {
        $started = Books::validDate($request->input('started'));
        $finished = Books::validDate($request->input('finished'));
        if (!$started && !$finished) {
            $this->app->flash('error', 'Vul minstens één datum in.');
        } elseif ($this->app->books->find($id)) {
            $this->app->books->addRead($id, $started, $finished);
        }
        return $this->toBook($id);
    }

    public function deleteRead(Request $request, int $id): Response
    {
        $bookId = $this->app->books->deleteRead($id);
        return $bookId ? $this->toBook($bookId) : Response::redirect($this->app->url('/books'));
    }

    // --- stats, import, settings ----------------------------------------------

    public function stats(Request $request): Response
    {
        $years = $this->app->books->years();
        $year = (int) ($request->query('year') ?: ($years[0]['year'] ?? date('Y')));
        return $this->app->render('stats', [
            'years' => $years,
            'year' => $year,
            'books' => $this->app->books->readInYear($year),
            'topAuthors' => $this->app->books->topAuthors(),
            'maxBooks' => max(array_column($years, 'books') ?: [0]),
        ]);
    }

    public function importForm(Request $request): Response
    {
        return $this->app->render('import', ['result' => null]);
    }

    public function import(Request $request): Response
    {
        $file = $request->files['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->app->flash('error', 'Kies eerst een CSV-bestand.');
            return Response::redirect($this->app->url('/import'));
        }
        $result = null;
        try {
            $csv = (string) file_get_contents($file['tmp_name']);
            $result = $this->app->books->import(StoryGraph::parse($csv));
            $this->app->flash('ok', sprintf('%d boeken geïmporteerd, %d overgeslagen (bestonden al).',
                count($result['imported']), count($result['skipped'])));
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', 'Import mislukt: ' . $e->getMessage());
        }
        return $this->app->render('import', ['result' => $result]);
    }

    public function settings(Request $request): Response
    {
        return $this->renderSettings($request);
    }

    /** @param array<string, mixed>|null $account */
    private function renderSettings(Request $request, ?array $account = null, ?string $newToken = null): Response
    {
        $sample = ['title' => 'De avonden', 'author' => 'Gerard Reve', 'rating' => 4.5,
                   'review' => 'Een prachtig boek over verveling.', 'isbn' => '9789023417069'];
        return $this->app->render('settings', [
            'mastodon' => $this->app->mastodon,
            'account' => $account,
            'previewStarted' => $this->app->tooter->compose($sample, 'started'),
            'previewFinished' => $this->app->tooter->compose($sample, 'finished'),
            'tokens' => $this->app->auth->tokens(),
            'newToken' => $newToken,
            'serverUrl' => $this->serverUrl($request),
        ]);
    }

    public function testMastodon(Request $request): Response
    {
        $account = null;
        try {
            $account = $this->app->mastodon->verify();
            $this->app->flash('ok', 'Verbonden als @' . ($account['acct'] ?? '?') . '.');
        } catch (MastodonException $e) {
            $this->app->flash('error', $e->getMessage());
        }
        return $this->renderSettings($request, $account);
    }

    public function createToken(Request $request): Response
    {
        $name = $request->input('name');
        $token = $this->app->auth->createToken(is_string($name) ? $name : '');
        $this->app->flash('ok', 'Token aangemaakt. Kopieer het nu: het wordt maar één keer getoond.');
        return $this->renderSettings($request, null, $token);
    }

    public function deleteToken(Request $request, int $id): Response
    {
        $this->app->auth->deleteToken($id);
        $this->app->flash('ok', 'Token ingetrokken.');
        return Response::redirect($this->app->url('/settings'));
    }

    // --- helpers --------------------------------------------------------------

    private function toBook(int $id): Response
    {
        return Response::redirect($this->app->url("/books/{$id}"));
    }

    private function wantsToot(Request $request): bool
    {
        return $request->input('toot') === 'on';
    }

    private function postToot(int $id, string $kind, ?string $text = null): void
    {
        if (!$this->app->tooter->configured()) {
            $this->app->flash('error', 'Niet gepost: Mastodon is nog niet ingesteld.');
            return;
        }
        try {
            $this->app->tooter->post($id, $kind, $text);
            $this->app->flash('ok', 'Gepost naar Mastodon.');
        } catch (MastodonException $e) {
            $this->app->flash('error', 'Posten naar Mastodon mislukt: ' . $e->getMessage());
        }
    }

    private function serverUrl(Request $request): string
    {
        $host = $request->header('host') ?? 'localhost';
        $https = ($_SERVER['HTTPS'] ?? '') === 'on' || $request->header('x-forwarded-proto') === 'https';
        return ($https ? 'https' : 'http') . '://' . $host . $this->app->basePath;
    }
}
