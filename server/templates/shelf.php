<?php /** @var App\View $v */ ?>
<header class="public-head">
  <a class="brand" href="<?= $v->url('/') ?>"><?= $v->partial('_logo') ?><span><?= $v->e($publicTitle) ?></span></a>
  <?php if ($loggedIn): ?><a class="button secondary small" href="<?= $v->url('/books') ?>">Beheren</a><?php else: ?><a class="button secondary small" href="<?= $v->url('/login') ?>">Inloggen</a><?php endif ?>
</header>

<?php if ($goal['goal']): ?><section class="card"><h2>Leesdoel <?= $year ?></h2><?= $v->partial('_goal', ['goal' => $goal]) ?></section><?php endif ?>

<?php if ($reading): ?>
<section class="section">
  <h2>Nu aan het lezen</h2>
  <div class="two">
  <?php foreach ($reading as $b): ?>
  <a class="public-book card" href="<?= $v->url('/boek/' . $b['id']) ?>">
    <?= $v->partial('_cover', ['b' => $b, 'size' => 'S']) ?>
    <span class="info"><span class="title"><?= $v->e($b['title']) ?></span><span class="by"><?= $v->e($b['author']) ?></span><?= $v->partial('_progress', ['b' => $b]) ?></span>
  </a>
  <?php endforeach ?>
  </div>
</section>
<?php endif ?>

<section class="section">
  <div class="page-head">
    <h2>Gelezen in <?= $year ?> <span class="muted"><?= count($books) ?></span></h2>
    <?php if (count($years) > 1): ?>
    <nav class="filters" aria-label="Jaar">
      <?php foreach ($years as $y): ?>
        <a href="<?= $v->url('/', ['year' => $y['year']]) ?>" class="pill small"<?= (int) $y['year'] === $year ? ' aria-current="true"' : '' ?>><?= (int) $y['year'] ?><span class="count"><?= (int) $y['books'] ?></span></a>
      <?php endforeach ?>
    </nav>
    <?php endif ?>
  </div>
  <?php if (!$books): ?><p class="muted">Nog niets gelezen dit jaar.</p><?php endif ?>
  <ul class="book-grid">
  <?php foreach ($books as $b): ?>
    <li>
      <a class="book-card" href="<?= $v->url('/boek/' . $b['id']) ?>">
        <?= $v->partial('_cover', ['b' => $b, 'size' => 'M']) ?>
        <span class="info">
          <span class="title"><?= $v->e($b['title']) ?></span>
          <span class="by"><?= $v->e($b['author']) ?></span>
          <span class="meta"><?= $v->partial('_stars', ['rating' => $b['rating']]) ?><span><?= $v->e($v->date($b['finished'])) ?></span></span>
        </span>
      </a>
      <?php if ($showReviews && $b['review']): ?><blockquote class="mini-review"><?= $v->e($b['review']) ?></blockquote><?php endif ?>
    </li>
  <?php endforeach ?>
  </ul>
</section>
<footer class="foot muted">Bijgehouden met Have I read that? · <a href="<?= $v->url('/feed.xml') ?>">RSS</a></footer>
