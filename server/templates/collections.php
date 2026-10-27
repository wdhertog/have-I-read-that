<?php /** @var App\View $v */
$back = '<svg class="icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>';
// A little row of spines per shelf: more books, more spines; their colours and sizes
// follow from the shelf's name, so they stay the same.
$spines = static function (string $name, int $count): array {
    $n = max(2, min(14, (int) round(log($count + 1, 2) * 1.6)));
    $out = [];
    for ($i = 0; $i < $n; $i++) {
        $h = crc32($name . '#' . $i);
        $out[] = 'tint-' . ($h % 7) . ' w' . [9, 11, 13, 15][($h >> 4) % 4] . ' h' . [45, 50, 54, 58, 64][($h >> 8) % 5];
    }
    return $out;
};
$number = static fn ($n) => rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');
?>
<?php if ($current !== ''): ?>
<a class="back" href="<?= $v->url('/collections', ['view' => 'series']) ?>"><?= $back ?>Alle series</a>
<div class="page-head">
  <div>
    <h1><?= $v->e($current) ?></h1>
    <?php $authors = array_values(array_unique(array_filter(array_merge(...array_map(static fn ($b) => App\Authors::names((string) $b['author']), $seriesBooks ?: [[ 'author' => '' ]]))))); ?>
    <p class="lead"><?php foreach ($authors as $i => $a): ?><?= $i ? ', ' : '' ?><a href="<?= $v->url('/author', ['name' => $a]) ?>"><?= $v->e($a) ?></a><?php endforeach ?><?= $authors ? ' · ' : '' ?><?= count($seriesBooks) ?> <?= count($seriesBooks) === 1 ? 'deel' : 'delen' ?> in je kast · <?= count(array_filter($seriesBooks, static fn ($b) => $b['status'] === 'read')) ?> gelezen</p>
  </div>
</div>
<?php if (!$seriesBooks): ?><p class="empty">Geen boeken in deze serie.</p><?php endif ?>
<ul class="book-grid">
  <?php foreach ($seriesBooks as $b): ?>
    <li><?= $v->partial('_book_card', ['b' => $b + ['part' => $b['series_number'] !== null ? $number($b['series_number']) : null]]) ?></li>
  <?php endforeach ?>
</ul>

<section class="section">
  <h2>Volgens Wikidata</h2>
  <?php if ($parts === null): ?>
    <p class="muted">Welke delen er zijn en welke je mist, zoekt de app op bij Wikidata.</p>
    <form method="post" action="<?= $v->url('/series/refresh') ?>"><?= $v->csrf() ?>
      <input type="hidden" name="series" value="<?= $v->e($current) ?>">
      <button class="secondary">Andere delen opzoeken</button></form>
  <?php elseif ($parts['qid'] === null): ?>
    <p class="muted">Wikidata kent deze serie niet (of niet met deze schrijver).</p>
  <?php else: ?>
    <p class="muted">Je hebt <?= $parts['have'] ?> van de <?= count($parts['parts']) ?> delen.</p>
    <?= $v->partial('_series_parts', ['series' => $current, 'parts' => $parts['parts'], 'back' => 'series', 'onlyMissing' => false]) ?>
    <form method="post" action="<?= $v->url('/series/refresh') ?>" class="inline"><?= $v->csrf() ?>
      <input type="hidden" name="series" value="<?= $v->e($current) ?>">
      <button class="link">opnieuw opzoeken</button></form>
  <?php endif ?>
</section>

<?php else: ?>
<div class="page-head">
  <div>
    <h1>Planken &amp; series</h1>
    <p class="lead">Een plank is een tag; die van StoryGraph komen bij het importeren vanzelf mee. Zet een boek op een plank via <em>Bewerken → Tags</em>.</p>
  </div>
  <a class="button secondary" href="<?= $v->url('/series') ?>">Ontbrekende delen<?= $missing ? " ({$missing})" : '' ?></a>
</div>

<div class="toolbar">
  <nav class="segment" aria-label="Weergave">
    <a href="<?= $v->url('/collections', ['sort' => $sort === 'count' ? 'count' : null]) ?>"<?= $view === 'shelves' ? ' aria-current="true"' : '' ?>>Planken</a>
    <a href="<?= $v->url('/collections', ['view' => 'series']) ?>"<?= $view === 'series' ? ' aria-current="true"' : '' ?>>Series</a>
  </nav>
  <?php if ($view === 'shelves'): ?>
  <nav class="sorter" aria-label="Sorteer">
    <span>Sorteer</span>
    <a href="<?= $v->url('/collections') ?>"<?= $sort === 'name' ? ' aria-current="true"' : '' ?>>Naam</a>
    <a href="<?= $v->url('/collections', ['sort' => 'count']) ?>"<?= $sort === 'count' ? ' aria-current="true"' : '' ?>>Aantal boeken</a>
  </nav>
  <?php endif ?>
</div>

<?php if ($view === 'shelves'): ?>
  <?php if (!$shelves): ?><p class="empty">Nog geen planken.</p><?php endif ?>
  <div class="shelf-grid">
    <?php foreach ($shelves as $s): ?>
    <div class="shelf-card">
      <div class="spines" aria-hidden="true"><?php foreach ($spines($s['name'], $s['count']) as $classes): ?><span class="<?= $classes ?>"></span><?php endforeach ?></div>
      <div class="head">
        <div>
          <a class="shelf-name" href="<?= $v->url('/books', ['shelf' => $s['name']]) ?>"><?= $v->e($s['name']) ?></a>
          <span class="muted"><?= $s['count'] ?> <?= $s['count'] === 1 ? 'boek' : 'boeken' ?></span>
        </div>
        <details>
          <summary class="icon-button button" aria-label="Plank <?= $v->e($s['name']) ?> hernoemen">
            <svg class="icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10.5 2.5l3 3L6 13H3v-3z" stroke="currentColor" stroke-width="1.5" stroke-linejoin="round"/></svg>
          </summary>
        </details>
      </div>
      <form method="post" action="<?= $v->url('/collections/rename') ?>" class="rename" hidden>
        <?= $v->csrf() ?>
        <input type="hidden" name="from" value="<?= $v->e($s['name']) ?>">
        <label>Nieuwe naam <input name="to" value="<?= $v->e($s['name']) ?>" maxlength="200"></label>
        <p class="muted">Een bestaande naam voegt de planken samen; leeg maken haalt de plank bij alle boeken weg.</p>
        <button class="secondary small">Opslaan</button>
      </form>
    </div>
    <?php endforeach ?>
  </div>
<?php else: ?>
  <?php if (!$series): ?><p class="empty">Nog geen series. Vul bij <em>Bewerken</em> de serie en het deel in.</p><?php endif ?>
  <div class="series-grid">
    <?php foreach ($series as $s): ?>
    <div class="shelf-card series">
      <a class="shelf-name" href="<?= $v->url('/collections', ['series' => $s['name']]) ?>"><?= $v->e($s['name']) ?></a>
      <?php if ($s['author'] !== ''): ?><a href="<?= $v->url('/author', ['name' => App\Authors::names($s['author'])[0] ?? $s['author']]) ?>"><?= $v->e(App\Authors::names($s['author'])[0] ?? $s['author']) ?></a><?php endif ?>
      <span class="muted"><?= $s['books'] ?> <?= $s['books'] === 1 ? 'deel' : 'delen' ?> · <?= $s['read'] ?> gelezen</span>
    </div>
    <?php endforeach ?>
  </div>
<?php endif ?>
<?php endif ?>
