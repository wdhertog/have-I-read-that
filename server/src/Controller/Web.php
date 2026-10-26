<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
use App\Auth;
use App\Backup;
use App\Books;
use App\Export;
use App\MastodonException;
use App\Request;
use App\Response;
use App\StoryGraph;
use App\Totp;
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
            if ($auth->twoFactorEnabled()) {
                // The attempt stays counted until the code is right too.
                $auth->beginSecondFactor();
                return Response::redirect($this->app->url('/login/code', ['next' => $next]));
            }
            $auth->clearAttempts($request->ip);
            $auth->login();
            return Response::redirect($this->app->url($next ?? '/books'));
        }
        sleep(1);
        $this->app->flash('error', 'Onjuist wachtwoord.');
        return Response::redirect($this->app->url('/login', ['next' => $next]));
    }

    public function codeForm(Request $request): Response
    {
        if (!$this->app->auth->secondFactorPending()) {
            return Response::redirect($this->app->url('/login'));
        }
        return $this->app->render('login_code');
    }

    public function code(Request $request): Response
    {
        $auth = $this->app->auth;
        $next = Auth::safeRedirectPath($request->query('next'));
        if (!$auth->secondFactorPending()) {
            $this->app->flash('error', 'Log opnieuw in; de vorige poging is verlopen.');
            return Response::redirect($this->app->url('/login', ['next' => $next]));
        }
        if (!$auth->registerAttempt($request->ip)) {
            $this->app->flash('error', 'Te veel mislukte pogingen. Probeer het over een kwartier opnieuw.');
            return Response::redirect($this->app->url('/login/code', ['next' => $next]));
        }
        $code = $request->input('code');
        if (is_string($code) && $auth->verifySecondFactor($code)) {
            $auth->clearAttempts($request->ip);
            $auth->login();
            return Response::redirect($this->app->url($next ?? '/books'));
        }
        sleep(1);
        $this->app->flash('error', 'Onjuiste code.');
        return Response::redirect($this->app->url('/login/code', ['next' => $next]));
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
        $shelf = $request->query('shelf');
        return $this->app->render('books', [
            'books' => $this->app->books->list($status ?: null, $q, null, $shelf ?: null),
            'counts' => $this->app->books->counts(),
            'status' => $status,
            'q' => $q,
            'shelf' => $shelf,
        ]);
    }

    public function addForm(Request $request): Response
    {
        $q = $request->query('q');
        $results = null;
        if ($q !== '') {
            $results = $this->app->catalog->search($q);
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
        $duplicate = $this->app->books->findDuplicate($data['title'], $data['author'], $data['isbn']);
        if ($duplicate && $request->input('allow_duplicate') !== 'on') {
            return $this->app->render('book_form', [
                'book' => $request->post, 'results' => null, 'q' => '', 'edit' => false, 'duplicate' => $duplicate,
            ], 409);
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
            'notes' => $this->app->books->notes($id),
            'loans' => $this->app->loans->forBook($id),
            'borrowers' => $this->app->loans->borrowers(),
            'tootKind' => $book['status'] === 'reading' ? 'started' : 'finished',
            'tootText' => $this->app->tooter->compose($book, $book['status'] === 'reading' ? 'started' : 'finished'),
        ]);
    }

    public function lend(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->app->render('404', [], 404);
        }
        try {
            $this->app->loans->lend($id, $request->input('borrower'), Books::validDate($request->input('date')), $request->input('note'));
            $this->app->flash('ok', 'Uitgeleend.');
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
        }
        return Response::redirect($this->app->url("/books/{$id}") . '#uitlenen');
    }

    public function returnBook(Request $request, int $id): Response
    {
        $this->app->loans->returnBook($id, Books::validDate($request->input('date')));
        $this->app->flash('ok', 'Teruggekregen.');
        return Response::redirect($this->app->url("/books/{$id}") . '#uitlenen');
    }

    public function deleteLoan(Request $request, int $id): Response
    {
        $bookId = $this->app->loans->delete($id);
        return Response::redirect($this->app->url($bookId ? "/books/{$bookId}" : '/loans'));
    }

    public function loans(Request $request): Response
    {
        return $this->app->render('loans', ['people' => $this->app->loans->byPerson()]);
    }

    /** Look the book up in the catalogues and fill in what's empty. */
    public function enrich(Request $request, int $id): Response
    {
        $book = $this->app->books->find($id);
        if (!$book) {
            return $this->app->render('404', [], 404);
        }
        if (!$book['isbn']) {
            $this->app->flash('error', 'Zonder ISBN kan ik dit boek niet opzoeken. Vul eerst het ISBN in.');
            return $this->toBook($id);
        }
        $info = $this->app->catalog->lookup($book['isbn']);
        $filled = $this->app->books->fillFromCatalog($book, $info);
        $this->warmCover($book);
        $this->app->flash($info ? 'ok' : 'error', match (true) {
            $info === null => 'Niet gevonden bij ' . implode(', ', $this->app->catalog->metadataSources()) . '.',
            $filled === [] => 'Gevonden bij ' . implode(', ', $info['sources']) . ', maar er was niets aan te vullen.',
            default => 'Aangevuld uit ' . implode(', ', $info['sources']) . ': ' . implode(', ', $filled) . '.',
        });
        return $this->toBook($id);
    }

    /**
     * Fill in all books in portions: as many as fit in ~20 seconds, so it never runs
     * into the host's time limit. Click again for the next portion.
     */
    public function enrichAll(Request $request): Response
    {
        $deadline = microtime(true) + 20;
        $done = 0;
        $filled = 0;
        foreach ($this->app->books->toEnrich(100) as $book) {
            if (microtime(true) > $deadline) {
                break;
            }
            try {
                if ($this->app->books->fillFromCatalog($book, $this->app->catalog->lookup($book['isbn']))) {
                    $filled++;
                }
                $this->warmCover($book);
            } catch (\Throwable $e) {
                // One odd record mustn't stop the rest: log it and mark the book as done.
                error_log("Aanvullen van boek {$book['id']} mislukt: {$e}");
                $this->app->books->fillFromCatalog($book, null);
            }
            $done++;
        }
        $left = $this->app->books->countToEnrich();
        $this->app->flash('ok', "{$done} boeken opgezocht, {$filled} aangevuld." . ($left ? " Nog {$left} te gaan: klik nog eens." : ' Klaar!'));
        return Response::redirect($this->app->url('/import') . '#aanvullen');
    }

    /** Fetch the cover now, so the first page view doesn't have to. */
    private function warmCover(array $book): void
    {
        try {
            $this->app->covers->image($book);
        } catch (\RuntimeException) {
            // no cover; nothing to warm
        }
    }

    public function addNote(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->app->render('404', [], 404);
        }
        try {
            $this->app->books->addNote($id, $request->post);
            $this->app->flash('ok', $request->input('kind') === 'quote' ? 'Citaat bewaard.' : 'Notitie bewaard.');
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
        }
        return Response::redirect($this->app->url("/books/{$id}") . '#notities');
    }

    public function deleteNote(Request $request, int $id): Response
    {
        $bookId = $this->app->books->deleteNote($id);
        return $bookId ? Response::redirect($this->app->url("/books/{$bookId}") . '#notities') : Response::redirect($this->app->url('/books'));
    }

    /** Shelves (your tags) and series on one page. */
    public function collections(Request $request): Response
    {
        $series = $request->query('series');
        return $this->app->render('collections', [
            'shelves' => $this->app->books->shelves(),
            'series' => $this->app->books->seriesList(),
            'current' => $series,
            'seriesBooks' => $series !== '' ? $this->app->books->seriesBooks($series) : [],
        ]);
    }

    public function renameShelf(Request $request): Response
    {
        try {
            $from = Books::text($request->input('from'), 200);
            $to = Books::text($request->input('to'), 200);
            $n = $this->app->books->renameShelf($from, $to);
            $this->app->flash('ok', $to === '' ? "Plank “{$from}” verwijderd bij {$n} boeken." : "Plank hernoemd bij {$n} boeken.");
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
        }
        return Response::redirect($this->app->url('/collections'));
    }

    public function progress(Request $request, int $id): Response
    {
        if (!$this->app->books->find($id)) {
            return $this->app->render('404', [], 404);
        }
        try {
            $page = self::optionalInt($request->input('page'));
            $percent = $page === null ? self::optionalInt($request->input('percent')) : null;
            $progress = $this->app->books->setProgress($id, $page, $percent);
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
            return $this->toBook($id);
        }
        $this->app->flash('ok', "Bijgewerkt: {$progress['percent']}% gelezen.");
        if ($this->wantsToot($request)) {
            $this->postToot($id, 'progress');
        }
        return $this->toBook($id);
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
            $upload = $request->files['cover'] ?? null;
            if (is_array($upload) && ($upload['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
                $this->app->covers->saveUpload($id, $upload['tmp_name']);
            } elseif ($request->input('remove_cover') === 'on') {
                $this->app->covers->deleteUpload($id);
            }
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
        $this->app->covers->deleteUpload($id);
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
        $year = (int) ($request->query('year') ?: date('Y'));
        return $this->app->render('stats', [
            'years' => $years,
            'year' => $year,
            'goal' => $this->app->books->goalProgress($year),
            'reviewText' => $this->app->yearReview->compose($year),
            'reviewCount' => count($this->app->yearReview->books($year)),
            'books' => $this->app->books->readInYear($year),
            'topAuthors' => $this->app->books->topAuthors(),
            'languages' => $this->app->books->readByLanguage(),
            'genres' => $this->app->books->topGenres(),
            'breakdown' => $this->app->books->pagesBreakdown($year),
            'daily' => $this->app->books->dailyPages($year),
            'maxBooks' => max(array_column($years, 'books') ?: [0]),
        ]);
    }

    public function setGoal(Request $request): Response
    {
        $year = 0;
        try {
            $year = (int) self::optionalInt($request->input('year'));
            $this->app->books->setGoal($year, self::optionalInt($request->input('books')));
            $this->app->flash('ok', 'Leesdoel opgeslagen.');
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
        }
        return Response::redirect($this->app->url('/stats', ['year' => $year ?: null]));
    }

    public function postYearReview(Request $request): Response
    {
        $year = 0;
        try {
            $year = (int) self::optionalInt($request->input('year'));
            $text = Books::text($request->input('text'), 5000);
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', $e->getMessage());
            return Response::redirect($this->app->url('/stats'));
        }
        if (!$this->app->tooter->configured()) {
            $this->app->flash('error', 'Niet gepost: Mastodon is nog niet ingesteld.');
        } else {
            try {
                $this->app->yearReview->post($year, $text, $request->input('collage') === 'on');
                $this->app->flash('ok', "Jaaroverzicht {$year} gepost naar Mastodon.");
            } catch (MastodonException $e) {
                $this->app->flash('error', 'Posten naar Mastodon mislukt: ' . $e->getMessage());
            }
        }
        return Response::redirect($this->app->url('/stats', ['year' => $year ?: null]));
    }

    /** Preview of the year's cover collage. */
    public function collage(Request $request, int $year): Response
    {
        $collage = $this->app->yearReview->collage($year);
        return $collage
            ? new Response($collage['bytes'], 200, ['Content-Type' => 'image/jpeg'])
            : new Response('Geen covers gevonden voor dit jaar.', 404, ['Content-Type' => 'text/plain; charset=utf-8']);
    }

    public function exportCsv(Request $request): Response
    {
        return $this->download(Export::csv($this->app->books->export()), 'text/csv', 'csv');
    }

    public function exportJson(Request $request): Response
    {
        return $this->download(Export::json($this->app->books->export()), 'application/json', 'json');
    }

    private function download(string $body, string $type, string $extension): Response
    {
        return new Response($body, 200, [
            'Content-Type' => "{$type}; charset=utf-8",
            'Content-Disposition' => 'attachment; filename="boeken-' . date('Y-m-d') . ".{$extension}\"",
        ]);
    }

    /** A whole number from a form field, or null when empty. */
    private static function optionalInt(mixed $value): ?int
    {
        $value = Books::text($value, 10);
        if ($value === '') {
            return null;
        }
        if (!preg_match('/^-?\d+$/', $value)) {
            throw new InvalidArgumentException('Vul een heel getal in.');
        }
        return (int) $value;
    }

    public function importForm(Request $request): Response
    {
        return $this->app->render('import', ['result' => null, 'toEnrich' => $this->app->books->countToEnrich()]);
    }

    /** Step 1: read the file and show what an import would do, without saving anything. */
    public function import(Request $request): Response
    {
        $file = $request->files['file'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $this->app->flash('error', 'Kies eerst een CSV-bestand.');
            return Response::redirect($this->app->url('/import'));
        }
        try {
            $csv = (string) file_get_contents($file['tmp_name']);
            $preview = $this->app->books->import(StoryGraph::parse($csv), dryRun: true);
        } catch (InvalidArgumentException $e) {
            $this->app->flash('error', 'Import mislukt: ' . $e->getMessage());
            return Response::redirect($this->app->url('/import'));
        }
        // Keep the file until it's confirmed; the session only holds a random name.
        $name = bin2hex(random_bytes(16));
        $dir = $this->importDir();
        file_put_contents("{$dir}/{$name}.csv", $csv);
        $_SESSION['pending_import'] = $name;
        return $this->app->render('import', ['result' => null, 'preview' => $preview]);
    }

    /** Step 2: do it for real. */
    public function confirmImport(Request $request): Response
    {
        $name = $_SESSION['pending_import'] ?? null;
        unset($_SESSION['pending_import']);
        $path = is_string($name) && ctype_xdigit($name) ? $this->importDir() . "/{$name}.csv" : null;
        if ($path === null || !is_file($path)) {
            $this->app->flash('error', 'Kies het bestand opnieuw; het voorbeeld is verlopen.');
            return Response::redirect($this->app->url('/import'));
        }
        try {
            $result = $this->app->books->import(StoryGraph::parse((string) file_get_contents($path)));
        } finally {
            unlink($path);
        }
        $this->app->flash('ok', sprintf('%d boeken toegevoegd, %d aangevuld, %d ongewijzigd.',
            count($result['imported']), count($result['updated']), count($result['unchanged'])));
        return $this->app->render('import', ['result' => $result]);
    }

    /** Folder for files waiting for confirmation; anything older than a day is cleared. */
    private function importDir(): string
    {
        $dir = dirname(__DIR__, 2) . '/var/imports';
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new \RuntimeException("Kan de map {$dir} niet maken.");
        }
        foreach (glob("{$dir}/*.csv") ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
        return $dir;
    }

    public function settings(Request $request): Response
    {
        return $this->renderSettings($request);
    }

    /** @param array<string, mixed>|null $account */
    /**
     * @param array<string, mixed>|null $account
     * @param list<string>|null $recoveryCodes
     */
    private function renderSettings(Request $request, ?array $account = null, ?string $newToken = null, ?array $recoveryCodes = null): Response
    {
        $setupSecret = $_SESSION['totp_setup'] ?? null;
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
            'twoFactor' => $this->app->auth->twoFactorEnabled(),
            'recoveryLeft' => $this->app->auth->recoveryCodesLeft(),
            'setupSecret' => is_string($setupSecret) ? $setupSecret : null,
            'setupUri' => is_string($setupSecret)
                ? Totp::uri($setupSecret, $request->header('host') ?? 'boeken', 'Have I read that')
                : null,
            'recoveryCodes' => $recoveryCodes,
        ]);
    }

    public function startTwoFactor(Request $request): Response
    {
        if ($this->app->auth->twoFactorEnabled()) {
            // Replacing it would lock out the real authenticator: turn it off first (with a code).
            $this->app->flash('error', 'Tweestapsverificatie staat al aan. Zet die eerst uit met een code.');
            return Response::redirect($this->app->url('/settings'));
        }
        $_SESSION['totp_setup'] = Totp::generateSecret();
        return $this->renderSettings($request);
    }

    public function enableTwoFactor(Request $request): Response
    {
        $secret = $_SESSION['totp_setup'] ?? null;
        $code = $request->input('code');
        if ($this->app->auth->twoFactorEnabled()) {
            unset($_SESSION['totp_setup']);
            $this->app->flash('error', 'Tweestapsverificatie staat al aan. Zet die eerst uit met een code.');
            return Response::redirect($this->app->url('/settings'));
        }
        if (!is_string($secret) || !is_string($code) || Totp::verify($secret, $code) === null) {
            $this->app->flash('error', 'Die code klopt niet. Controleer de tijd op je telefoon en probeer het opnieuw.');
            return $this->renderSettings($request);
        }
        unset($_SESSION['totp_setup']);
        $codes = $this->app->auth->enableTwoFactor($secret);
        // Turning it on shouldn't log you out: refresh this session's fingerprint.
        $this->app->auth->login();
        $this->app->flash('ok', 'Tweestapsverificatie staat aan. Bewaar de herstelcodes hieronder.');
        return $this->renderSettings($request, null, null, $codes);
    }

    public function disableTwoFactor(Request $request): Response
    {
        $code = $request->input('code');
        if (!$this->app->auth->registerAttempt($request->ip)) {
            $this->app->flash('error', 'Te veel pogingen. Probeer het over een kwartier opnieuw.');
        } elseif (!is_string($code) || !$this->app->auth->verifySecondFactor($code)) {
            $this->app->flash('error', 'Die code klopt niet; tweestapsverificatie staat nog aan.');
        } else {
            $this->app->auth->clearAttempts($request->ip);
            $this->app->auth->disableTwoFactor();
            $this->app->flash('ok', 'Tweestapsverificatie staat uit.');
        }
        return Response::redirect($this->app->url('/settings'));
    }

    public function backup(Request $request): Response
    {
        return $this->download((new Backup($this->app->books->db()))->toString(), 'application/sql', 'sql');
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
        $token = $this->app->auth->createToken(is_string($name) ? $name : '', $request->input('read_only') === 'on' ? 'read' : 'full');
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
