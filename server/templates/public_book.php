<?php /** @var App\View $v */ ?>
<?= $v->partial('_back', ['href' => $v->url('/'), 'label' => $publicTitle]) ?>
<article class="detail">
  <div class="detail-cover"><?= $v->partial('_cover', ['b' => $book, 'size' => 'L']) ?></div>
  <div class="detail-main">
    <h1><?= $v->e($book['title']) ?></h1>
    <?php if ($book['author'] !== ''): ?><p class="byline">door <?= $v->e($book['author']) ?></p><?php endif ?>
    <div class="status-line">
      <?php if ($book['status'] === 'reading'): ?><span class="badge big reading">Aan het lezen</span><?php endif ?>
      <?php if ($book['rating']): ?><?= $v->partial('_stars', ['rating' => $book['rating'], 'big' => true]) ?> <span class="muted"><?= $v->rating($book['rating']) ?> van 5</span><?php endif ?>
      <?php if ($book['series']): ?><span class="muted"><?= $v->e($book['series']) ?><?= $book['series_number'] !== null ? ', deel ' . $v->e(rtrim(rtrim(number_format($book['series_number'], 1, ',', ''), '0'), ',')) : '' ?></span><?php endif ?>
    </div>
    <?php if ($book['status'] === 'reading'): ?><?= $v->partial('_progress', ['b' => $book]) ?><?php endif ?>
    <?php if ($showReviews && $book['review']): ?>
      <figure class="review"><blockquote><?= $v->e($book['review']) ?></blockquote></figure>
    <?php endif ?>
    <?php if ($book['description'] ?? null): ?><div class="description"><?= nl2br($v->e($book['description'])) ?></div><?php endif ?>
    <dl class="facts">
      <?php if ($finished): ?><div><dt>Uitgelezen</dt><dd><?= $v->e(implode(', ', array_map([$v, 'date'], $finished))) ?></dd></div><?php endif ?>
      <?php if ($book['genre'] ?? null): ?><div><dt>Genre</dt><dd><?= $v->e($book['genre']) ?></dd></div><?php endif ?>
    </dl>
  </div>
</article>
<?php if ($quotes): ?>
<section class="section">
  <h2>Citaten en notities</h2>
  <?= $v->partial('_notes', ['notes' => $quotes, 'editable' => false]) ?>
</section>
<?php endif ?>
