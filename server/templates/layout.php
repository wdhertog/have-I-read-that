<?php /** @var App\View $v */
$section = static fn (string ...$prefixes) => array_filter($prefixes, static fn (string $p) => $path === $p || str_starts_with($path, rtrim($p, '/') . '/')) !== [];
$nav = [
    ['/books', 'Boeken', $section('/books') && !$section('/books/add')],
    ['/collections', 'Planken & series', $section('/collections')],
    ['/authors', 'Auteurs', $section('/authors', '/author', '/publishers', '/publisher')],
    ['/series', 'Tips', $section('/series')],
    ['/loans', 'Uitgeleend', $section('/loans')],
    ['/stats', 'Statistieken', $section('/stats')],
];
?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#17130F">
  <title><?= $v->e(($title ?? null) ? $title . ' · ' : '') ?>Have I read that?</title>
  <link rel="preload" href="<?= $v->url('/assets/fonts/figtree.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <link rel="preload" href="<?= $v->url('/assets/fonts/literata.woff2') ?>" as="font" type="font/woff2" crossorigin>
  <link rel="stylesheet" href="<?= $v->url('/assets/style.css') ?>">
  <script src="<?= $v->url('/assets/app.js') ?>" defer></script>
  <?php if ($publicPage): ?>
  <link rel="alternate" type="application/rss+xml" title="<?= $v->e($publicTitle) ?>" href="<?= $v->url('/feed.xml') ?>">
  <?php endif ?>
  <?php if (!empty($meta)): ?>
  <meta property="og:type" content="book">
  <meta property="og:title" content="<?= $v->e($meta['title']) ?>">
  <meta property="og:description" content="<?= $v->e($meta['description']) ?>">
  <meta property="og:url" content="<?= $v->e($meta['url']) ?>">
  <?php if ($meta['image']): ?><meta property="og:image" content="<?= $v->e($meta['image']) ?>"><?php endif ?>
  <meta name="description" content="<?= $v->e($meta['description']) ?>">
  <meta name="twitter:card" content="summary">
  <?php endif ?>
</head>
<body>
  <?php if ($loggedIn): ?>
  <header class="top">
    <div class="top-inner">
      <a class="brand" href="<?= $v->url('/books') ?>"><?= $v->partial('_logo') ?><span>Have I read that?</span></a>
      <nav class="mainnav" aria-label="Hoofdmenu">
        <?php foreach ($nav as [$href, $label, $active]): ?>
          <a href="<?= $v->url($href) ?>"<?= $active ? ' aria-current="page"' : '' ?>><?= $v->e($label) ?></a>
        <?php endforeach ?>
      </nav>
      <div class="top-actions">
        <a class="button" href="<?= $v->url('/books/add') ?>"<?= $section('/books/add') ? ' aria-current="page"' : '' ?>>
          <svg class="icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M8 3v10M3 8h10" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
          <span class="label">Toevoegen</span></a>
        <button type="button" class="account" aria-label="Account en instellingen" aria-expanded="false" aria-controls="accountmenu" data-menu="accountmenu">
          <?php if (($ownerName ?? '') !== ''): ?><?= $v->e(mb_strtoupper(mb_substr($ownerName, 0, 1))) ?>
          <?php else: ?><svg class="icon" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="9" cy="6" r="3.2" stroke="currentColor" stroke-width="1.5"/><path d="M3 16c.8-3 3.2-4.5 6-4.5s5.2 1.5 6 4.5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round"/></svg><?php endif ?>
        </button>
        <div class="menu" id="accountmenu" hidden>
          <a href="<?= $v->url('/import') ?>">Import/export</a>
          <a href="<?= $v->url('/publishers') ?>">Uitgevers</a>
          <a href="<?= $v->url('/settings') ?>">Instellingen</a>
          <?php if ($publicPage): ?><a href="<?= $v->url('/') ?>">Openbare pagina</a><?php endif ?>
          <hr>
          <form method="post" action="<?= $v->url('/logout') ?>"><?= $v->csrf() ?><button type="submit">Uitloggen</button></form>
        </div>
      </div>
    </div>
  </header>
  <?php endif ?>
  <main>
    <?php foreach ($flashes as [$category, $message]): ?>
      <div class="flash <?= $v->e($category) ?>" role="status"><?= $v->e($message) ?></div>
    <?php endforeach ?>
    <?= $content ?>
  </main>
</body>
</html>
