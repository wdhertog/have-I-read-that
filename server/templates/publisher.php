<?php /** @var App\View $v */ ?>
<p><a href="<?= $v->url('/publishers') ?>">← Alle uitgevers</a></p>
<section class="card">
  <h1><?= $v->e($name) ?></h1>
  <?php if ($stats): ?>
    <p><?= $stats['books'] ?> <?= $stats['books'] === 1 ? 'boek' : 'boeken' ?>, waarvan <?= $stats['read'] ?> gelezen<?= $stats['pages'] ? ' (' . number_format($stats['pages'], 0, ',', '.') . " pagina's)" : '' ?><?= $stats['rating'] !== null ? ', gemiddeld ' . $v->rating($stats['rating']) . ' ★' : '' ?>.</p>
  <?php endif ?>
  <?= $v->partial('_book_rows', ['books' => $books]) ?>
</section>
