<?php /** @var App\View $v */ ?>
<section class="empty-state">
  <svg width="48" height="48" viewBox="0 0 24 24" fill="none" aria-hidden="true"><path d="M5 4h4v16H5zM10 4h4v16h-4zM15.5 4.6l3.6-1 3.7 15.4-3.6 1z" stroke="currentColor" stroke-width="1.4" stroke-linejoin="round"/></svg>
  <h1>Niet gevonden</h1>
  <p class="lead">Deze pagina staat niet (meer) in de kast.</p>
  <p><a class="button secondary" href="<?= $v->url('/') ?>">Terug naar je boeken</a></p>
</section>
