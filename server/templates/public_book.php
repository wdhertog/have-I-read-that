<?php /** @var App\View $v */ ?>
<p><a href="<?= $v->url('/') ?>">← <?= $v->e($publicTitle) ?></a></p>
<section class="card book-detail">
  <?= $v->partial('_cover', ['b' => $book, 'size' => 'L']) ?>
  <div>
    <h1><?= $v->e($book['title']) ?></h1>
    <?php if ($book['author'] !== ''): ?><p class="muted"><?= $v->e($book['author']) ?></p><?php endif ?>
    <?php if ($book['series']): ?><p class="muted"><?= $v->e($book['series']) ?><?= $book['series_number'] !== null ? ', deel ' . $v->e(rtrim(rtrim(number_format($book['series_number'], 1, ',', ''), '0'), ',')) : '' ?></p><?php endif ?>
    <?php if ($book['rating']): ?><p><span class="stars"><?= $v->stars($book['rating']) ?></span> <span class="muted"><?= $v->rating($book['rating']) ?></span></p><?php endif ?>
    <?php if ($book['status'] === 'reading'): ?>
      <p><span class="badge reading">Aan het lezen</span></p>
      <?= $v->partial('_progress', ['b' => $book]) ?>
    <?php endif ?>
    <?php if ($finished): ?>
      <p class="muted">Uitgelezen op <?= $v->e(implode(', ', $finished)) ?></p>
    <?php endif ?>
    <?php if ($book['genre'] ?? null): ?><p class="muted"><?= $v->e($book['genre']) ?></p><?php endif ?>
    <?php if ($book['description'] ?? null): ?><div class="description"><?= nl2br($v->e($book['description'])) ?></div><?php endif ?>
    <?php if ($showReviews && $book['review']): ?><blockquote><?= $v->e($book['review']) ?></blockquote><?php endif ?>
  </div>
</section>
<?php if ($quotes): ?>
<section class="card">
  <h2>Citaten en notities</h2>
  <?= $v->partial('_notes', ['notes' => $quotes, 'editable' => false]) ?>
</section>
<?php endif ?>
