<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; $all = array_sum($counts); ?>
<div class="page-head">
  <div>
    <h1>Je boekenkast</h1>
    <p class="lead"><?= number_format($all, 0, ',', '.') ?> <?= $all === 1 ? 'boek' : 'boeken' ?> · <?= number_format($counts['read'] ?? 0, 0, ',', '.') ?> gelezen · <?= (int) ($counts['reading'] ?? 0) ?> nu aan het lezen</p>
  </div>
  <form class="search" method="get" role="search">
    <label for="zoek" class="sr">Zoek in je boeken</label>
    <svg class="icon" width="18" height="18" viewBox="0 0 18 18" fill="none" aria-hidden="true"><circle cx="8" cy="8" r="5.5" stroke="currentColor" stroke-width="1.6"/><path d="M12.5 12.5L16 16" stroke="currentColor" stroke-width="1.6" stroke-linecap="round"/></svg>
    <input id="zoek" type="search" name="q" value="<?= $v->e($q) ?>" placeholder="Zoek titel, auteur, tag…">
    <?php if ($status !== ''): ?><input type="hidden" name="status" value="<?= $v->e($status) ?>"><?php endif ?>
    <?php if ($shelf !== ''): ?><input type="hidden" name="shelf" value="<?= $v->e($shelf) ?>"><?php endif ?>
  </form>
</div>

<nav class="filters" aria-label="Filter op status">
  <a href="<?= $v->url('/books', ['q' => $q, 'shelf' => $shelf]) ?>" class="pill"<?= $status === '' ? ' aria-current="true"' : '' ?>>Alles <span class="count"><?= $all ?></span></a>
  <?php foreach ($statuses as $key => $label): ?>
    <a href="<?= $v->url('/books', ['status' => $key, 'q' => $q, 'shelf' => $shelf]) ?>" class="pill"<?= $status === $key ? ' aria-current="true"' : '' ?>><?= $label ?> <span class="count"><?= (int) ($counts[$key] ?? 0) ?></span></a>
  <?php endforeach ?>
  <?php if ($shelf !== ''): ?>
    <a href="<?= $v->url('/books', ['status' => $status, 'q' => $q]) ?>" class="pill" aria-current="true" aria-label="Plank <?= $v->e($shelf) ?>: filter weghalen">Plank: <?= $v->e($shelf) ?> <span class="count" aria-hidden="true">✕</span></a>
  <?php endif ?>
</nav>

<?php if (!$books): ?>
  <p class="empty"><?= $q !== '' || $status !== '' || $shelf !== '' ? 'Geen boeken gevonden.' : 'Nog geen boeken hier.' ?>
    <a href="<?= $v->url('/books/add') ?>">Voeg er een toe</a> of <a href="<?= $v->url('/import') ?>">importeer je StoryGraph-historie</a>.</p>
<?php endif ?>

<ul class="book-grid">
  <?php foreach ($books as $i => $b): ?>
    <li id="b-<?= $i + 1 ?>"><?= $v->partial('_book_card', ['b' => $b]) ?></li>
  <?php endforeach ?>
</ul>

<?php if ($total > count($books)): ?>
  <div class="load-more">
    <a class="button secondary" href="<?= $v->url('/books', ['status' => $status, 'q' => $q, 'shelf' => $shelf, 'n' => $shown + 120]) ?>#b-<?= count($books) + 1 ?>">Meer boeken laden <span class="muted">(nog <?= $total - count($books) ?>)</span></a>
  </div>
<?php endif ?>
