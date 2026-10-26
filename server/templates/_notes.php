<?php /** @var App\View $v */ ?>
<?php foreach ($notes as $n): ?>
  <figure class="note <?= $n['kind'] ?>">
    <?php if ($n['kind'] === 'quote'): ?><blockquote><?= $v->e($n['text']) ?></blockquote><?php else: ?><p><?= $v->e($n['text']) ?></p><?php endif ?>
    <figcaption class="muted">
      <?= $n['page'] !== null ? 'p. ' . (int) $n['page'] . ' · ' : '' ?><?= $n['kind'] === 'quote' ? 'citaat' : 'notitie' ?><?php if (!empty($editable)): ?><?= $n['public'] ? ' · openbaar' : ' · privé' ?>
      <form method="post" action="<?= $v->url("/notes/{$n['id']}/delete") ?>" class="inline" data-confirm="Verwijderen?"><?= $v->csrf() ?><button class="link">verwijder</button></form><?php endif ?>
    </figcaption>
  </figure>
<?php endforeach ?>
