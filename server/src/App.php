<?php

declare(strict_types=1);

namespace App;

use App\Controller\Api;
use App\Controller\Shelf;
use App\Controller\Web;
use PDO;

/**
 * Wires everything together and turns a Request into a Response.
 */
final class App
{
    public readonly Books $books;
    public readonly Auth $auth;
    public readonly Tooter $tooter;
    public readonly Mastodon $mastodon;
    public readonly View $view;
    public readonly YearReview $yearReview;
    public readonly Covers $covers;
    public readonly Catalog $catalog;
    public readonly Loans $loans;
    public readonly SeriesParts $seriesParts;
    public readonly Authors $authors;
    /** The request being handled, for absolute URLs. */
    private ?Request $request = null;
    private Router $router;

    public function __construct(public readonly Config $config, PDO $db, public readonly string $basePath = '')
    {
        $this->books = new Books($db);
        $this->auth = new Auth($config, $db);
        $this->mastodon = Mastodon::fromConfig($config);
        $this->catalog = new Catalog($config);
        $this->loans = new Loans($db);
        $this->seriesParts = new SeriesParts($db, $this->books, $this->catalog->wikidata());
        $this->authors = new Authors(
            $db,
            $this->books,
            $this->catalog->wikidata(),
            $config->get('AUTHORS_DIR', dirname($config->get('COVERS_DIR', dirname(__DIR__) . '/var/covers')) . '/authors'),
            \Closure::fromCallable([Http::class, 'get']),
        );
        $this->covers = new Covers(
            $config->get('COVERS_DIR', dirname(__DIR__) . '/var/covers'),
            [$this->catalog, 'cover'],
            implode(',', $this->catalog->coverSources()),
        );
        $this->tooter = new Tooter($this->books, $this->mastodon, $config, [$this->covers, 'image']);
        $this->yearReview = new YearReview($this->books, $this->mastodon, [$this->covers, 'image']);
        $this->view = new View(dirname(__DIR__) . '/templates', $basePath, fn (array $book) => $this->coverUrl($book));
        $this->router = new Router();

        $web = new Web($this);
        $shelf = new Shelf($this);
        $api = new Api($this);
        $r = $this->router;

        $r->add('GET', '/', fn (Request $q) => $this->publicPage()
            ? $shelf->index($q)
            : Response::redirect($this->url('/books')));
        $r->add('GET', '/login', [$web, 'loginForm']);
        $r->add('POST', '/login', [$web, 'login']);
        $r->add('GET', '/login/code', [$web, 'codeForm']);
        $r->add('POST', '/login/code', [$web, 'code']);
        $r->add('POST', '/logout', [$web, 'logout']);
        $r->add('GET', '/books', [$web, 'index']);
        $r->add('GET', '/books/add', [$web, 'addForm']);
        $r->add('POST', '/books/add', [$web, 'add']);
        $r->add('GET', '/books/{id}', [$web, 'show']);
        $r->add('GET', '/books/{id}/edit', [$web, 'editForm']);
        $r->add('POST', '/books/{id}/edit', [$web, 'edit']);
        $r->add('POST', '/books/{id}/delete', [$web, 'delete']);
        $r->add('POST', '/books/{id}/start', [$web, 'start']);
        $r->add('POST', '/books/{id}/finish', [$web, 'finish']);
        $r->add('POST', '/books/{id}/toot', [$web, 'toot']);
        $r->add('POST', '/books/{id}/reads', [$web, 'addRead']);
        $r->add('POST', '/books/{id}/progress', [$web, 'progress']);
        $r->add('POST', '/books/{id}/notes', [$web, 'addNote']);
        $r->add('POST', '/notes/{id}/delete', [$web, 'deleteNote']);
        $r->add('GET', '/collections', [$web, 'collections']);
        $r->add('POST', '/books/{id}/lend', [$web, 'lend']);
        $r->add('POST', '/books/{id}/return', [$web, 'returnBook']);
        $r->add('POST', '/loans/{id}/delete', [$web, 'deleteLoan']);
        $r->add('GET', '/loans', [$web, 'loans']);
        $r->add('POST', '/books/{id}/enrich', [$web, 'enrich']);
        $r->add('POST', '/import/enrich', [$web, 'enrichAll']);
        $r->add('POST', '/collections/rename', [$web, 'renameShelf']);
        $r->add('GET', '/series', [$web, 'seriesSuggestions']);
        $r->add('POST', '/series/refresh', [$web, 'refreshSeries']);
        $r->add('POST', '/series/add', [$web, 'addSeriesPart']);
        $r->add('POST', '/series/dismiss', [$web, 'dismissSeriesPart']);
        $r->add('POST', '/books/{id}/merge', [$web, 'merge']);
        $r->add('GET', '/duplicates', [$web, 'duplicates']);
        $r->add('GET', '/authors', [$web, 'authors']);
        $r->add('GET', '/author', [$web, 'author']);
        $r->add('POST', '/author/refresh', [$web, 'refreshAuthor']);
        $r->add('POST', '/author/add', [$web, 'addAuthorWork']);
        $r->add('POST', '/author/dismiss', [$web, 'dismissAuthorWork']);
        $r->add('GET', '/authors/photo/{id}', [$web, 'authorPhoto']);
        $r->add('GET', '/publishers', [$web, 'publishers']);
        $r->add('GET', '/publisher', [$web, 'publisher']);
        $r->add('POST', '/reads/{id}/delete', [$web, 'deleteRead']);
        $r->add('GET', '/stats', [$web, 'stats']);
        $r->add('POST', '/stats/goal', [$web, 'setGoal']);
        $r->add('POST', '/stats/year-review', [$web, 'postYearReview']);
        $r->add('GET', '/stats/collage/{year}', [$web, 'collage']);
        $r->add('GET', '/boek/{id}', [$shelf, 'book']);
        $r->add('GET', '/feed.xml', [$shelf, 'feed']);
        $r->add('GET', '/cover/{id}', [$shelf, 'cover']);
        $r->add('GET', '/export.csv', [$web, 'exportCsv']);
        $r->add('GET', '/export.json', [$web, 'exportJson']);
        $r->add('GET', '/import', [$web, 'importForm']);
        $r->add('POST', '/import', [$web, 'import']);
        $r->add('POST', '/import/confirm', [$web, 'confirmImport']);
        $r->add('GET', '/settings', [$web, 'settings']);
        $r->add('POST', '/settings/test', [$web, 'testMastodon']);
        $r->add('POST', '/settings/tokens', [$web, 'createToken']);
        $r->add('POST', '/settings/tokens/{id}/delete', [$web, 'deleteToken']);
        $r->add('POST', '/settings/2fa/start', [$web, 'startTwoFactor']);
        $r->add('POST', '/settings/2fa/enable', [$web, 'enableTwoFactor']);
        $r->add('POST', '/settings/2fa/disable', [$web, 'disableTwoFactor']);
        $r->add('GET', '/backup.sql', [$web, 'backup']);

        $r->add('GET', '/api/me', [$api, 'me']);
        $r->add('GET', '/api/books', [$api, 'index']);
        $r->add('POST', '/api/books', [$api, 'create']);
        $r->add('GET', '/api/books/{id}', [$api, 'show']);
        $r->add('POST', '/api/books/{id}/start', [$api, 'start']);
        $r->add('POST', '/api/books/{id}/finish', [$api, 'finish']);
        $r->add('POST', '/api/books/{id}/toot', [$api, 'toot']);
        // Only GET and POST: some shared hosting blocks PUT and DELETE.
        $r->add('POST', '/api/books/{id}', [$api, 'update']);
        $r->add('POST', '/api/books/{id}/delete', [$api, 'delete']);
        $r->add('POST', '/api/books/{id}/progress', [$api, 'progress']);
        $r->add('POST', '/api/books/{id}/reads', [$api, 'addRead']);
        $r->add('POST', '/api/reads/{id}', [$api, 'updateRead']);
        $r->add('POST', '/api/reads/{id}/delete', [$api, 'deleteRead']);
        $r->add('GET', '/api/lookup', [$api, 'lookup']);
        $r->add('GET', '/api/search', [$api, 'search']);
        $r->add('GET', '/api/collections', [$api, 'collections']);
        $r->add('GET', '/api/loans', [$api, 'loans']);
        $r->add('POST', '/api/books/{id}/lend', [$api, 'lend']);
        $r->add('POST', '/api/books/{id}/return', [$api, 'returnBook']);
        $r->add('POST', '/api/books/{id}/notes', [$api, 'addNote']);
        $r->add('POST', '/api/notes/{id}/delete', [$api, 'deleteNote']);
    }

    /** Pages anyone may open: the login form, and the public shelf, book pages and feed. */
    public static function isPublicPath(string $path): bool
    {
        return in_array($path, ['/', '/login', '/login/code', '/feed.xml'], true)
            || preg_match('#^/(boek|cover)/\d+$#', $path) === 1; // cover checks access itself
    }

    /** URL of a book's cover on this server, or null when it can't have one. */
    public function coverUrl(array $book, bool $absolute = false): ?string
    {
        if (!$this->covers->mayHave($book)) {
            return null;
        }
        $path = "/cover/{$book['id']}?v=" . rawurlencode($this->covers->version($book));
        return $absolute && $this->request ? $this->absoluteUrl($this->request, $path) : $this->url($path);
    }

    /**
     * Absolute URL for links that leave the site (feed, previews, toots). APP_URL wins;
     * otherwise the request's own host, if it looks like a host name.
     */
    public function absoluteUrl(Request $request, string $path): string
    {
        $base = rtrim($this->config->get('APP_URL'), '/');
        if ($base === '') {
            $host = $request->header('host') ?? '';
            $host = preg_match('/^[A-Za-z0-9.-]+(:\d+)?$/', $host) ? $host : 'localhost';
            $base = ($request->https ? 'https' : 'http') . '://' . $host . $this->basePath;
        }
        return $base . $path;
    }

    /**
     * Texts to send someone about a book, with the public book page when there is one.
     *
     * @param array<string, mixed> $book
     * @return array{opinion: string, ask_back: ?string}
     */
    public function shareTexts(array $book): array
    {
        $public = $this->publicPage() && in_array($book['status'], Books::PUBLIC_STATUSES, true) && $this->request !== null;
        return [
            'opinion' => Share::opinion($book, $public ? $this->absoluteUrl($this->request, "/boek/{$book['id']}") : null),
            'ask_back' => Share::askBack($book),
        ];
    }

    public function publicPage(): bool
    {
        return $this->config->bool('PUBLIC_PAGE', true);
    }

    /** @param array<string, mixed> $query */
    public function url(string $path, array $query = []): string
    {
        return $this->view->url($path, $query);
    }

    public function flash(string $category, string $message): void
    {
        $_SESSION['flash'][] = [$category, $message];
    }

    /** @param array<string, mixed> $vars */
    public function render(string $template, array $vars = [], int $status = 200): Response
    {
        $this->view->share('flashes', $_SESSION['flash'] ?? []);
        unset($_SESSION['flash']);
        $this->view->share('loggedIn', $this->auth->loggedIn());
        $this->view->share('csrf', $this->auth->csrfToken());
        $this->view->share('publicPage', $this->publicPage());
        $this->view->share('publicTitle', $this->config->get('PUBLIC_TITLE', 'Wat ik lees'));
        $this->view->share('postByDefault', $this->config->bool('MASTODON_POST_BY_DEFAULT', true));
        return Response::html($this->view->render($template, $vars), $status);
    }

    public function handle(Request $request): Response
    {
        $this->request = $request;
        $response = $this->dispatch($request);
        $headers = [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'same-origin',
            // Everything, covers included, comes from this server.
            'Content-Security-Policy' => "default-src 'self'; object-src 'none'; "
                . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
        ];
        // Only the public pages may be cached; everything else can contain private data or tokens.
        if (!self::isPublicPath($request->path) || str_starts_with($request->path, '/login') || $this->auth->loggedIn()) {
            $headers['Cache-Control'] = 'no-store';
        }
        if ($request->https) {
            $headers['Strict-Transport-Security'] = 'max-age=31536000';
        }
        $response->headers += $headers;
        return $response;
    }

    private function dispatch(Request $request): Response
    {
        $isApi = str_starts_with($request->path, '/api/');
        try {
            if ($isApi) {
                $scope = $this->auth->checkToken($request->header('authorization'));
                if ($scope === null) {
                    return Response::json(['error' => 'Ongeldig of ontbrekend token.'], 401);
                }
                if ($scope === 'read' && $request->method !== 'GET') {
                    return Response::json(['error' => 'Dit token mag alleen lezen.'], 403);
                }
            } elseif (!self::isPublicPath($request->path) && !$this->auth->loggedIn()) {
                return $request->method === 'GET'
                    ? Response::redirect($this->url('/login', ['next' => $request->path]))
                    : new Response('Niet ingelogd', 403);
            }
            if (!$isApi && $request->method === 'POST' && !$this->auth->checkCsrf($request->input('_csrf'))) {
                return Response::html('Sessie verlopen, ga terug en probeer het opnieuw.', 400);
            }
            $response = $this->router->dispatch($request);
        } catch (\Throwable $e) {
            error_log((string) $e);
            $detail = $this->config->bool('APP_DEBUG') ? ' ' . $e->getMessage() : '';
            return $isApi
                ? Response::json(['error' => 'Interne fout.' . $detail], 500)
                : Response::html('Er ging iets mis. Kijk in de serverlog voor details.' . htmlspecialchars($detail), 500);
        }
        if ($response === null) {
            return $isApi ? Response::json(['error' => 'Niet gevonden.'], 404) : $this->render('404', [], 404);
        }
        return $response;
    }
}
