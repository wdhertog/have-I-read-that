<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; ?>
<ul class="books">
  <?php foreach ($books as $b): ?>
  <li>
    <a href="<?= $v->url('/books/' . $b['id']) ?>" class="book-row">
      <?= $v->partial('_cover', ['b' => $b, 'size' => 'S']) ?>
      <div>
        <strong><?= $v->e($b['title']) ?></strong>
        <div class="muted"><?= $v->e($b['author']) ?><?= !empty($b['published_year']) ? ' · ' . (int) $b['published_year'] : '' ?></div>
        <div class="meta">
          <span class="badge <?= $b['status'] ?>"><?= $statuses[$b['status']] ?></span>
          <?php if ($b['rating']): ?><span class="stars"><?= $v->stars($b['rating']) ?></span><?php endif ?>
          <?php if ($b['last_finished'] ?? null): ?><span class="muted"><?= $v->e($b['last_finished']) ?></span><?php endif ?>
        </div>
      </div>
    </a>
  </li>
  <?php endforeach ?>
</ul>
