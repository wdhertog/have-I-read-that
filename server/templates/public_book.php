<?php /** @var App\View $v */ ?>
<p><a href="<?= $v->url('/') ?>">← <?= $v->e($publicTitle) ?></a></p>
<section class="card book-detail">
  <?= $v->partial('_cover', ['b' => $book, 'size' => 'L']) ?>
  <div>
    <h1><?= $v->e($book['title']) ?></h1>
    <?php if ($book['author'] !== ''): ?><p class="muted"><?= $v->e($book['author']) ?></p><?php endif ?>
    <?php if ($book['rating']): ?><p><span class="stars"><?= $v->stars($book['rating']) ?></span> <span class="muted"><?= $v->rating($book['rating']) ?></span></p><?php endif ?>
    <?php if ($book['status'] === 'reading'): ?>
      <p><span class="badge reading">Aan het lezen</span></p>
      <?= $v->partial('_progress', ['b' => $book]) ?>
    <?php endif ?>
    <?php if ($finished): ?>
      <p class="muted">Uitgelezen op <?= $v->e(implode(', ', $finished)) ?></p>
    <?php endif ?>
    <?php if ($showReviews && $book['review']): ?><blockquote><?= $v->e($book['review']) ?></blockquote><?php endif ?>
  </div>
</section>
