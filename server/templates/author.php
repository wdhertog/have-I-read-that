<?php /** @var App\View $v */
$d = $profile['data'];
// Only as precise as Wikidata knows it: a day, a month or just the year.
$years = static fn (?array $date) => match (true) {
    $date === null => null,
    $date['date'] !== null => date('j-n-Y', strtotime($date['date'])),
    !empty($date['month']) => ['januari', 'februari', 'maart', 'april', 'mei', 'juni', 'juli', 'augustus', 'september', 'oktober', 'november', 'december'][$date['month'] - 1] . ' ' . $date['year'],
    default => (string) $date['year'],
};
?>
<p><a href="<?= $v->url('/authors') ?>">← Alle auteurs</a></p>
<section class="card author">
  <?php if ($d !== null && !empty($d['photo']) && $profile['qid'] !== null): ?>
    <img class="author-photo" src="<?= $v->url('/authors/photo/' . substr($profile['qid'], 1)) ?>" alt="<?= $v->e($d['name']) ?>" width="160">
  <?php endif ?>
  <div>
    <h1><?= $v->e($d['name'] ?? $name) ?></h1>
    <?php if ($d === null): ?>
      <p class="muted">Wikidata kent deze schrijver niet (of niet onder deze naam).</p>
    <?php else: ?>
      <?php if ($d['description']): ?><p class="muted"><?= $v->e($d['description']) ?></p><?php endif ?>
      <dl>
        <?php if ($d['born']): ?><dt>Geboren</dt><dd><?= $v->e($years($d['born'])) ?><?= $d['birthplace'] ? ' in ' . $v->e($d['birthplace']) : '' ?></dd><?php endif ?>
        <?php if ($d['died']): ?><dt>Overleden</dt><dd><?= $v->e($years($d['died'])) ?><?= $d['deathplace'] ? ' in ' . $v->e($d['deathplace']) : '' ?></dd><?php endif ?>
        <?php if ($d['countries']): ?><dt>Nationaliteit</dt><dd><?= $v->e(implode(', ', $d['countries'])) ?></dd><?php endif ?>
        <?php if ($d['awards']): ?><dt>Prijzen</dt><dd><?= $v->e(implode(', ', $d['awards'])) ?></dd><?php endif ?>
      </dl>
      <?php if ($d['bio']): ?>
        <div class="description"><?= nl2br($v->e($d['bio'])) ?></div>
        <p class="muted">Bron: <a href="<?= $v->e($d['wikipedia_url']) ?>" target="_blank" rel="noopener">Wikipedia</a><?= !empty($d['photo']) ? ', foto via Wikimedia Commons' : '' ?>.</p>
      <?php endif ?>
    <?php endif ?>
    <form method="post" action="<?= $v->url('/author/refresh') ?>" class="inline"><?= $v->csrf() ?>
      <input type="hidden" name="name" value="<?= $v->e($name) ?>"><button class="link">opnieuw opzoeken</button></form>
  </div>
</section>

<section class="card">
  <h2>Jouw boeken</h2>
  <p><?= count($books) ?> <?= count($books) === 1 ? 'boek' : 'boeken' ?>, waarvan <?= $readCount ?> gelezen<?= $pagesRead ? ' (' . number_format($pagesRead, 0, ',', '.') . " pagina's)" : '' ?><?= $rating !== null ? ', gemiddeld ' . $v->rating($rating) . ' ★' : '' ?>.</p>
  <?= $v->partial('_book_rows', ['books' => $books]) ?>
</section>

<?php if ($works): ?>
<section class="card" id="werk">
  <h2>Ander werk volgens Wikidata</h2>
  <ul class="parts">
    <?php foreach ($works as $w): ?>
      <li class="<?= $w['book'] !== null ? 'have' : 'missing' ?>">
        <span class="part-number"><?= $w['year'] !== null ? (int) $w['year'] : '' ?></span>
        <div>
          <?php if ($w['book'] !== null): ?>
            ✓ <a href="<?= $v->url('/books/' . $w['book']['id']) ?>"><?= $v->e($w['book']['title']) ?></a>
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
