<?php /** @var App\View $v */ ?>
<header class="shelf-head">
  <h1>📚 <?= $v->e($publicTitle) ?></h1>
  <p class="muted"><?php if ($loggedIn): ?><a href="<?= $v->url('/books') ?>">Beheren →</a><?php else: ?><a href="<?= $v->url('/login') ?>">Inloggen</a><?php endif ?></p>
</header>

<?php if ($goal['goal']): ?><section class="card"><h2>Leesdoel</h2><?= $v->partial('_goal', ['goal' => $goal]) ?></section><?php endif ?>

<?php if ($reading): ?>
<section class="card">
  <h2>Nu aan het lezen</h2>
  <?php foreach ($reading as $b): ?>
  <div class="public-book">
    <?= $v->partial('_cover', ['b' => $b, 'size' => 'S']) ?>
    <div><strong><?= $v->e($b['title']) ?></strong><div class="muted"><?= $v->e($b['author']) ?></div><?= $v->partial('_progress', ['b' => $b]) ?></div>
  </div>
  <?php endforeach ?>
</section>
<?php endif ?>

<section class="card">
  <h2>Gelezen in <?= $year ?> <span class="muted">(<?= count($books) ?>)</span></h2>
  <?php if (count($years) > 1): ?>
  <div class="years">
    <?php foreach ($years as $y): ?>
      <a href="<?= $v->url('/', ['year' => $y['year']]) ?>" class="pill <?= (int) $y['year'] === $year ? 'active' : '' ?>"><?= $y['year'] ?> (<?= $y['books'] ?>)</a>
    <?php endforeach ?>
  </div>
  <?php endif ?>
  <?php if (!$books): ?><p class="muted">Nog niets gelezen dit jaar.</p><?php endif ?>
  <?php foreach ($books as $b): ?>
  <div class="public-book">
    <?= $v->partial('_cover', ['b' => $b, 'size' => 'M']) ?>
    <div>
      <strong><?= $v->e($b['title']) ?></strong>
      <div class="muted"><?= $v->e($b['author']) ?></div>
      <div class="meta">
        <?php if ($b['rating']): ?><span class="stars"><?= $v->stars($b['rating']) ?></span><?php endif ?>
        <span class="muted"><?= $v->e($b['finished']) ?></span>
      </div>
      <?php if ($showReviews && $b['review']): ?><blockquote><?= $v->e($b['review']) ?></blockquote><?php endif ?>
    </div>
  </div>
  <?php endforeach ?>
</section>
<footer class="foot muted">Bijgehouden met Have I read that?</footer>
