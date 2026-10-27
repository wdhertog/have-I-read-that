<?php /** @var App\View $v */
$d = $profile['data'];
// Only as precise as Wikidata knows it: a day, a month or just the year.
$years = static fn (?array $date) => match (true) {
    $date === null => null,
    $date['date'] !== null => $v->date($date['date']),
    !empty($date['month']) => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'][$date['month'] - 1] . ' ' . $date['year'],
    default => (string) $date['year'],
};
?>
<a class="back" href="<?= $v->url('/authors') ?>"><svg class="icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Alle auteurs</a>
<?php
$display = $d['name'] ?? $name;
$initials = implode('', array_map(static fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)), array_slice(preg_split('/\s+/u', trim($display)) ?: [], 0, 2)));
$lifeYears = $d !== null && ($d['born'] || $d['died']) ? trim(($d['born']['year'] ?? '') . '–' . ($d['died']['year'] ?? ''), '–') : null;
// The short Wikidata description as the line above the name, else nationality and years.
$kicker = $d !== null && $d['description'] && mb_strlen($d['description']) <= 80 ? $d['description']
    : implode(' · ', array_filter([$d['countries'][0] ?? null, $lifeYears]));
$series = array_values(array_unique(array_filter(array_column($books, 'series'))));
?>
<section class="author-head">
  <figure>
    <?php if ($d !== null && !empty($d['photo']) && $profile['qid'] !== null): ?>
      <img class="portrait" src="<?= $v->url('/authors/photo/' . substr($profile['qid'], 1)) ?>" alt="Portret van <?= $v->e($display) ?>" width="240" height="300">
      <figcaption>Foto via Wikimedia Commons</figcaption>
    <?php else: ?>
      <div class="portrait" aria-hidden="true"><?= $v->e($initials) ?></div>
    <?php endif ?>
  </figure>

  <div class="author-main">
    <div>
      <?php if ($kicker !== ''): ?><p class="kicker"><?= $v->e($kicker) ?></p><?php endif ?>
      <h1><?= $v->e($display) ?></h1>
    </div>
    <?php if ($d === null): ?>
      <p class="muted">Wikidata kent deze schrijver niet (of niet onder deze naam).</p>
    <?php else: ?>
      <?php if ($d['bio']): ?><p class="bio"><?= nl2br($v->e($d['bio'])) ?></p><?php endif ?>
      <dl class="inline">
        <?php if ($d['born']): ?><div><dt>Geboren</dt><dd><?= $v->e($years($d['born'])) ?><?= $d['birthplace'] ? ', ' . $v->e($d['birthplace']) : '' ?></dd></div><?php endif ?>
        <?php if ($d['died']): ?><div><dt>Overleden</dt><dd><?= $v->e($years($d['died'])) ?><?= $d['deathplace'] ? ', ' . $v->e($d['deathplace']) : '' ?></dd></div><?php endif ?>
        <?php if ($d['countries']): ?><div><dt>Nationaliteit</dt><dd><?= $v->e(implode(', ', $d['countries'])) ?></dd></div><?php endif ?>
        <?php if ($series): ?><div><dt>Series</dt><dd><?php foreach ($series as $i => $s): ?><?= $i ? ', ' : '' ?><a href="<?= $v->url('/collections', ['series' => $s]) ?>"><?= $v->e($s) ?></a><?php endforeach ?></dd></div><?php endif ?>
        <?php if ($d['awards']): ?><div><dt>Prijzen</dt><dd><?= $v->e(implode(', ', $d['awards'])) ?></dd></div><?php endif ?>
      </dl>
    <?php endif ?>
    <div class="source">
      <?php if ($d['wikipedia_url'] ?? null): ?><span>Bron: <a href="<?= $v->e($d['wikipedia_url']) ?>" target="_blank" rel="noopener">Wikipedia</a></span><?php endif ?>
      <?php if ($profile['qid'] !== null): ?><span><a href="https://www.wikidata.org/wiki/<?= $v->e($profile['qid']) ?>" target="_blank" rel="noopener">Bekijk op Wikidata</a> (klopt er iets niet? Daar kun je het verbeteren)</span><?php endif ?>
      <form method="post" action="<?= $v->url('/author/refresh') ?>" class="inline"><?= $v->csrf() ?>
        <input type="hidden" name="name" value="<?= $v->e($name) ?>">
        <button class="secondary small"><svg class="icon" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M12 7a5 5 0 11-1.5-3.5M12 2v3H9" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>Opnieuw opzoeken</button></form>
    </div>
  </div>
</section>

<section class="section" aria-labelledby="jouw">
  <h2 id="jouw">Jij en <?= $v->e($display) ?></h2>
  <div class="statband">
    <div><strong><?= count($books) ?></strong><span><?= count($books) === 1 ? 'boek' : 'boeken' ?> in je kast</span></div>
    <div><strong><?= $readCount ?></strong><span>gelezen<?= $readCount === count($books) && $readCount > 1 ? ', alles dus' : '' ?></span></div>
    <div><strong><?= number_format($pagesRead, 0, ',', '.') ?></strong><span>pagina’s gelezen</span></div>
    <div><strong><?= $rating !== null ? number_format($rating, 2, ',', '') : '–' ?> <?php if ($rating !== null): ?><span class="star" aria-hidden="true">★</span><?php endif ?></strong><span>gemiddelde waardering</span></div>
  </div>
  <ul class="book-grid">
    <?php foreach (array_slice($books, 0, 11) as $b): ?><li><?= $v->partial('_book_card', ['b' => $b]) ?></li><?php endforeach ?>
    <?php if (count($books) > 11): ?>
      <li><a class="more-card" href="<?= $v->url('/books', ['q' => $name]) ?>"><strong>+<?= count($books) - 11 ?></strong><span>Toon alle boeken</span></a></li>
    <?php endif ?>
  </ul>
</section>


<?php if ($works): ?>
<section class="section" id="werk">
  <h2>Ander werk volgens Wikidata</h2>
  <ul class="parts">
    <?php foreach ($works as $w): ?>
      <li class="<?= $w['book'] !== null ? 'have' : 'missing' ?>">
        <span class="part-number"><?= $w['year'] !== null ? (int) $w['year'] : '' ?></span>
        <div>
          <?php if ($w['book'] !== null): ?>
            <svg class="icon" width="14" height="14" viewBox="0 0 14 14" fill="none" aria-hidden="true"><path d="M2.5 7.5l3 3 6-7" stroke="#B3D1A9" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg><span class="sr">In je kast:</span> <a href="<?= $v->url('/books/' . $w['book']['id']) ?>"><?= $v->e($w['book']['title']) ?></a>
          <?php else: ?>
            <strong><?= $v->e($w['title']) ?></strong><?php if (!empty($w['title_en'])): ?> <span class="muted">(<?= $v->e($w['title_en']) ?>)</span><?php endif ?>
            <div class="actions">
              <form method="post" action="<?= $v->url('/author/add') ?>" class="inline"><?= $v->csrf() ?>
                <input type="hidden" name="name" value="<?= $v->e($name) ?>"><input type="hidden" name="qid" value="<?= $v->e($w['qid']) ?>">
                <button class="secondary">Op “Wil ik lezen”</button></form>
              <form method="post" action="<?= $v->url('/author/dismiss') ?>" class="inline"><?= $v->csrf() ?>
                <input type="hidden" name="name" value="<?= $v->e($name) ?>"><input type="hidden" name="qid" value="<?= $v->e($w['qid']) ?>">
                <button class="link">niet interessant</button></form>
            </div>
          <?php endif ?>
        </div>
      </li>
    <?php endforeach ?>
  </ul>
</section>
<?php endif ?>
