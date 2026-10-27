<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; ?>
<a href="<?= $v->url('/books/' . $b['id']) ?>" class="book-card">
  <?= $v->partial('_cover', ['b' => $b, 'size' => 'M']) ?>
  <span class="info">
    <span class="title"><?php if (($b['part'] ?? null) !== null): ?><span class="muted">Deel <?= $v->e($b['part']) ?> · </span><?php endif ?><?= $v->e($b['title']) ?></span>
    <?php if (($b['author'] ?? '') !== ''): ?><span class="by"><?= $v->e($b['author']) ?></span><?php endif ?>
    <span class="meta">
      <span class="badge <?= $v->e($b['status']) ?>"><?= $statuses[$b['status']] ?></span>
      <?= $v->partial('_stars', ['rating' => $b['rating']]) ?>
      <?php if (!empty($b['lent_to'])): ?><span class="badge lent">bij <?= $v->e($b['lent_to']) ?></span><?php endif ?>
    </span>
    <?php if ($b['status'] === 'reading'): ?><?= $v->partial('_progress', ['b' => $b]) ?><?php endif ?>
  </span>
</a>
