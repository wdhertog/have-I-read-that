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
    private Router $router;

    public function __construct(public readonly Config $config, PDO $db, public readonly string $basePath = '')
    {
        $this->books = new Books($db);
        $this->auth = new Auth($config, $db);
        $this->mastodon = Mastodon::fromConfig($config);
        $this->tooter = new Tooter($this->books, $this->mastodon, $config);
        $this->view = new View(dirname(__DIR__) . '/templates', $basePath);
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
        $r->add('POST', '/reads/{id}/delete', [$web, 'deleteRead']);
        $r->add('GET', '/stats', [$web, 'stats']);
        $r->add('GET', '/import', [$web, 'importForm']);
        $r->add('POST', '/import', [$web, 'import']);
        $r->add('GET', '/settings', [$web, 'settings']);
        $r->add('POST', '/settings/test', [$web, 'testMastodon']);
        $r->add('POST', '/settings/tokens', [$web, 'createToken']);
        $r->add('POST', '/settings/tokens/{id}/delete', [$web, 'deleteToken']);

        $r->add('GET', '/api/me', [$api, 'me']);
        $r->add('GET', '/api/books', [$api, 'index']);
        $r->add('POST', '/api/books', [$api, 'create']);
        $r->add('GET', '/api/books/{id}', [$api, 'show']);
        $r->add('POST', '/api/books/{id}/start', [$api, 'start']);
        $r->add('POST', '/api/books/{id}/finish', [$api, 'finish']);
        $r->add('POST', '/api/books/{id}/toot', [$api, 'toot']);
        $r->add('GET', '/api/lookup', [$api, 'lookup']);
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
        $isApi = str_starts_with($request->path, '/api/');
        try {
            if ($isApi) {
                if (!$this->auth->checkToken($request->header('authorization'))) {
                    return Response::json(['error' => 'Ongeldig of ontbrekend token.'], 401);
                }
            } elseif (!in_array($request->path, ['/', '/login'], true) && !$this->auth->loggedIn()) {
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
