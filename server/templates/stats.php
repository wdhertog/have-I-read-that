<?php /** @var App\View $v */ ?>
<section class="card">
  <h1>Gelezen per jaar</h1>
  <?php if (!$years): ?><p class="muted">Nog geen uitgelezen boeken met een datum.</p><?php endif ?>
  <table class="bars">
    <?php foreach ($years as $y): ?>
    <tr class="<?= (int) $y['year'] === $year ? 'current' : '' ?>">
      <th><a href="<?= $v->url('/stats', ['year' => $y['year']]) ?>"><?= $y['year'] ?></a></th>
      <td class="bar"><span style="width: <?= round(100 * $y['books'] / $maxBooks, 1) ?>%"></span></td>
      <td><?= $y['books'] ?> <?= $y['books'] === 1 ? 'boek' : 'boeken' ?></td>
      <td class="muted"><?= $y['pages'] ? number_format($y['pages'], 0, ',', '.') . ' pag.' : '' ?></td>
      <td class="muted"><?= $y['avg_rating'] ? '⌀ ' . $v->rating($y['avg_rating']) . ' ★' : '' ?></td>
    </tr>
    <?php endforeach ?>
  </table>
</section>

<?php if ($books): ?>
<section class="card">
  <h2>Gelezen in <?= $year ?></h2>
  <div class="shelf">
    <?php foreach ($books as $b): ?>
      <a href="<?= $v->url('/books/' . $b['id']) ?>" title="<?= $v->e("{$b['title']} — {$b['author']} ({$b['finished']})") ?>">
        <?= $v->partial('_cover', ['b' => $b, 'size' => 'M']) ?>
      </a>
    <?php endforeach ?>
  </div>
</section>
<?php endif ?>

<?php if ($topAuthors): ?>
<section class="card">
  <h2>Meest gelezen auteurs</h2>
  <ol><?php foreach ($topAuthors as $a): ?><li><?= $v->e($a['author']) ?> <span class="muted">(<?= $a['n'] ?>)</span></li><?php endforeach ?></ol>
</section>
<?php endif ?>
