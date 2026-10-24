<?php /** @var App\View $v */ $url = $v->cover($b['isbn'] ?? null, $size); ?>
<div class="cover <?= $size ?>">
  <?php if ($url): ?><img src="<?= $v->e($url) ?>" alt="" loading="lazy" onerror="this.remove()"><?php endif ?>
  <span><?= $v->e(mb_substr($b['title'], 0, 40)) ?></span>
</div>
