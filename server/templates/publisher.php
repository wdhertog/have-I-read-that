<?php /** @var App\View $v */ ?>
<?= $v->partial('_back', ['href' => $v->url('/publishers'), 'label' => 'Alle uitgevers']) ?>
<header class="page-head">
  <div>
    <p class="kicker">Uitgever</p>
    <h1><?= $v->e($name) ?></h1>
  </div>
</header>
<?php if ($stats): ?>
<div class="statband">
  <div><strong><?= $stats['books'] ?></strong><span><?= $stats['books'] === 1 ? 'boek' : 'boeken' ?> in je kast</span></div>
  <div><strong><?= $stats['read'] ?></strong><span>gelezen</span></div>
  <div><strong><?= number_format((int) $stats['pages'], 0, ',', '.') ?></strong><span>pagina’s gelezen</span></div>
  <div><strong><?= $stats['rating'] !== null ? $v->rating($stats['rating']) . ' <span class="star" aria-hidden="true">★</span>' : '–' ?></strong><span>gemiddelde waardering</span></div>
</div>
<?php endif ?>
<ul class="book-grid">
  <?php foreach ($books as $b): ?><li><?= $v->partial('_book_card', ['b' => $b]) ?></li><?php endforeach ?>
</ul>
