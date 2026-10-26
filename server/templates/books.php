<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; ?>
<form class="filters" method="get">
  <a href="<?= $v->url('/books', ['q' => $q]) ?>" class="pill <?= $status === '' ? 'active' : '' ?>">Alles (<?= array_sum($counts) ?>)</a>
  <?php foreach ($statuses as $key => $label): ?>
    <a href="<?= $v->url('/books', ['status' => $key, 'q' => $q]) ?>" class="pill <?= $status === $key ? 'active' : '' ?>"><?= $label ?> (<?= $counts[$key] ?? 0 ?>)</a>
  <?php endforeach ?>
  <?php if ($shelf !== ''): ?>
    <a href="<?= $v->url('/books', ['status' => $status, 'q' => $q]) ?>" class="pill active" title="Plank-filter weghalen">Plank: <?= $v->e($shelf) ?> ×</a>
    <input type="hidden" name="shelf" value="<?= $v->e($shelf) ?>">
  <?php endif ?>
  <input type="hidden" name="status" value="<?= $v->e($status) ?>">
  <input type="search" name="q" value="<?= $v->e($q) ?>" placeholder="Zoek titel, auteur, tag…">
</form>

<?php if (!$books): ?>
  <p class="empty">Nog geen boeken hier. <a href="<?= $v->url('/books/add') ?>">Voeg er een toe</a> of <a href="<?= $v->url('/import') ?>">importeer je StoryGraph-historie</a>.</p>
<?php endif ?>

<ul class="books">
  <?php foreach ($books as $b): ?>
  <li>
    <a href="<?= $v->url('/books/' . $b['id']) ?>" class="book-row">
      <?= $v->partial('_cover', ['b' => $b, 'size' => 'S']) ?>
      <div>
        <strong><?= $v->e($b['title']) ?></strong>
        <div class="muted"><?= $v->e($b['author']) ?></div>
        <div class="meta">
          <span class="badge <?= $b['status'] ?>"><?= $statuses[$b['status']] ?></span>
          <?php if ($b['rating']): ?><span class="stars"><?= $v->stars($b['rating']) ?></span><?php endif ?>
          <?php if ($b['last_finished']): ?><span class="muted"><?= $v->e($b['last_finished']) ?></span><?php endif ?>
        </div>
        <?php if ($b['status'] === 'reading'): ?><?= $v->partial('_progress', ['b' => $b]) ?><?php endif ?>
      </div>
    </a>
  </li>
  <?php endforeach ?>
</ul>
