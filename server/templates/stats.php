<?php /** @var App\View $v */ ?>
<section class="card">
  <h1>Leesdoel <?= $year ?></h1>
  <?= $v->partial('_goal', ['goal' => $goal]) ?>
  <form method="post" action="<?= $v->url('/stats/goal') ?>" class="row">
    <?= $v->csrf() ?>
    <input type="hidden" name="year" value="<?= $year ?>">
    <input type="number" name="books" min="0" max="<?= App\Books::MAX_GOAL ?>" value="<?= $v->e($goal['goal']) ?>" placeholder="Aantal boeken (leeg = geen doel)">
    <button class="secondary">Doel opslaan</button>
  </form>
</section>

<?php if ($reviewCount > 0): ?>
<section class="card">
  <h2>Jaaroverzicht <?= $year ?></h2>
  <p class="muted">Post een overzicht van je leesjaar naar Mastodon, met een collage van de covers. <a href="<?= $v->url("/stats/collage/{$year}") ?>" target="_blank" rel="noopener">Collage bekijken</a> (even geduld, de covers worden opgehaald).</p>
  <form method="post" action="<?= $v->url('/stats/year-review') ?>" data-confirm="Jaaroverzicht nu posten naar Mastodon?">
    <?= $v->csrf() ?>
    <input type="hidden" name="year" value="<?= $year ?>">
    <label>Tekst (pas gerust aan) <textarea name="text" rows="6"><?= $v->e($reviewText) ?></textarea></label>
    <label class="check"><input type="checkbox" name="collage" checked> Collage van covers meesturen</label>
    <button>Posten naar Mastodon</button>
  </form>
</section>
<?php endif ?>

<section class="card">
  <h1>Gelezen per jaar</h1>
  <?php if (!$years): ?><p class="muted">Nog geen uitgelezen boeken met een datum.</p><?php endif ?>
  <table class="bars">
    <?php foreach ($years as $y): ?>
    <tr class="<?= (int) $y['year'] === $year ? 'current' : '' ?>">
      <th><a href="<?= $v->url('/stats', ['year' => $y['year']]) ?>"><?= $y['year'] ?></a></th>
      <td class="bar"><span data-width="<?= round(100 * $y['books'] / $maxBooks, 1) ?>"></span></td>
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

<?php if ($languages || $genres): ?>
<div class="two">
<?php if ($languages): ?>
<section class="card">
  <h2>Gelezen per taal</h2>
  <p class="muted">De oorspronkelijke taal; tussen haakjes hoeveel je daarvan in vertaling las.</p>
  <ul>
    <?php foreach ($languages as $l): ?>
      <li><?= $v->e(App\Language::label($l['language'])) ?>: <?= $l['books'] ?> <?= $l['books'] === 1 ? 'boek' : 'boeken' ?><?= $l['translated'] ? ' <span class="muted">(' . $l['translated'] . ' vertaald)</span>' : '' ?></li>
    <?php endforeach ?>
  </ul>
  <?php $translated = array_sum(array_column($languages, 'translated')); $total = array_sum(array_column($languages, 'books')); ?>
  <?php if ($translated): ?><p><?= $translated ?> van de <?= $total ?> boeken (<?= round(100 * $translated / $total) ?>%) las je in vertaling.</p><?php endif ?>
</section>
<?php endif ?>
<?php if ($genres): ?>
<section class="card">
  <h2>Meest gelezen genres</h2>
  <ol><?php foreach ($genres as $genre => $n): ?><li><a href="<?= $v->url('/books', ['q' => $genre]) ?>"><?= $v->e($genre) ?></a> <span class="muted">(<?= $n ?>)</span></li><?php endforeach ?></ol>
</section>
<?php endif ?>
</div>
<?php endif ?>

<?php if ($topAuthors): ?>
<section class="card">
  <h2>Meest gelezen auteurs</h2>
  <ol><?php foreach ($topAuthors as $a): ?><li><?= $v->e($a['author']) ?> <span class="muted">(<?= $a['n'] ?>)</span></li><?php endforeach ?></ol>
</section>
<?php endif ?>
