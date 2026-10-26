<?php

declare(strict_types=1);

namespace App\Controller;

use App\App;
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
}
