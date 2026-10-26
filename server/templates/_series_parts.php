<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; ?>
<ul class="parts">
  <?php foreach ($parts as $p): ?>
    <?php if ($onlyMissing && $p['book'] !== null) { continue; } ?>
    <li class="<?= $p['book'] !== null ? 'have' : 'missing' ?>">
      <span class="part-number"><?= $p['number'] !== null ? $v->e(rtrim(rtrim(number_format((float) $p['number'], 1, ',', ''), '0'), ',')) : '·' ?></span>
      <div>
        <?php if ($p['book'] !== null): ?>
          ✓ <a href="<?= $v->url('/books/' . $p['book']['id']) ?>"><?= $v->e($p['book']['title']) ?></a>
          <span class="badge <?= $v->e($p['book']['status']) ?>"><?= $statuses[$p['book']['status']] ?? '' ?></span>
        <?php else: ?>
          <strong><?= $v->e($p['title']) ?></strong><?php if (!empty($p['title_en'])): ?> <span class="muted">(<?= $v->e($p['title_en']) ?>)</span><?php endif ?>
          <div class="meta"><?= $v->e($p['author'] ?? '') ?><?= !empty($p['year']) ? ' · ' . (int) $p['year'] : '' ?></div>
          <div class="actions">
            <form method="post" action="<?= $v->url('/series/add') ?>" class="inline"><?= $v->csrf() ?>
              <input type="hidden" name="series" value="<?= $v->e($series) ?>"><input type="hidden" name="qid" value="<?= $v->e($p['qid']) ?>">
              <input type="hidden" name="back" value="<?= $v->e($back) ?>">
              <button class="secondary">Op “Wil ik lezen”</button></form>
            <form method="post" action="<?= $v->url('/series/dismiss') ?>" class="inline"><?= $v->csrf() ?>
              <input type="hidden" name="series" value="<?= $v->e($series) ?>"><input type="hidden" name="qid" value="<?= $v->e($p['qid']) ?>">
              <input type="hidden" name="back" value="<?= $v->e($back) ?>">
              <button class="link">niet interessant</button></form>
          </div>
        <?php endif ?>
      </div>
    </li>
  <?php endforeach ?>
</ul>
