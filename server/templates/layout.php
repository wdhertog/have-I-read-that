<?php /** @var App\View $v */ ?>
<!doctype html>
<html lang="nl">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= $v->e(($title ?? null) ? $title . ' · ' : '') ?>Have I read that?</title>
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
    <a class="brand" href="<?= $v->url('/books') ?>">📚 Have I read that?</a>
    <nav>
      <a href="<?= $v->url('/books') ?>">Boeken</a>
      <a href="<?= $v->url('/books/add') ?>">+ Toevoegen</a>
      <a href="<?= $v->url('/collections') ?>">Planken &amp; series</a>
      <a href="<?= $v->url('/stats') ?>">Statistieken</a>
      <a href="<?= $v->url('/import') ?>">Import/export</a>
      <a href="<?= $v->url('/settings') ?>">Instellingen</a>
      <?php if ($publicPage): ?><a href="<?= $v->url('/') ?>">Openbaar</a><?php endif ?>
      <form method="post" action="<?= $v->url('/logout') ?>" class="inline"><?= $v->csrf() ?><button class="link">Uitloggen</button></form>
    </nav>
  </header>
  <?php endif ?>
  <main>
    <?php foreach ($flashes as [$category, $message]): ?>
      <div class="flash <?= $v->e($category) ?>"><?= $v->e($message) ?></div>
    <?php endforeach ?>
    <?= $content ?>
  </main>
</body>
</html>
