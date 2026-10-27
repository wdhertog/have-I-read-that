<?php /** @var App\View $v */
$withTips = array_values(array_filter($overview, static fn (array $s) => $s['missing'] !== []));
$complete = array_values(array_filter($overview, static fn (array $s) => $s['qid'] !== null && $s['missing'] === [] && $s['parts'] !== []));
$unknown = array_values(array_filter($overview, static fn (array $s) => $s['qid'] === null));
?>
<section class="card">
  <h1>Ontbrekende delen</h1>
  <p class="muted">Van de series in je bibliotheek zoekt de app bij Wikidata alle delen op en kijkt welke je al hebt (op titel, oorspronkelijke titel of deelnummer). De rest staat hieronder als tip.</p>
  <?php if ($toRefresh > 0): ?>
    <form method="post" action="<?= $v->url('/series/refresh') ?>"><?= $v->csrf() ?>
      <button class="secondary">Series opzoeken (nog <?= (int) $toRefresh ?>)</button></form>
    <p class="muted">Dit gaat in porties van zo'n 20 seconden; klik nog eens tot het klaar is. Na 30 dagen wordt een serie opnieuw opgezocht.</p>
  <?php endif ?>
</section>

<section class="card" id="tips">
  <h2>Misschien leuk om te lezen</h2>
  <?php if (!$withTips): ?><p class="muted"><?= $overview ? 'Je hebt alle delen die Wikidata kent. 🎉' : 'Nog geen series opgezocht.' ?></p><?php endif ?>
  <?php foreach ($withTips as $s): ?>
    <h3><a href="<?= $v->url('/collections', ['series' => $s['series']]) ?>"><?= $v->e($s['series']) ?></a>
      <span class="muted">— je hebt <?= $s['have'] ?> van de <?= count($s['parts']) ?></span></h3>
    <?= $v->partial('_series_parts', ['series' => $s['series'], 'parts' => $s['missing'], 'back' => 'overview', 'onlyMissing' => true]) ?>
  <?php endforeach ?>
</section>

<?php if ($complete): ?>
<section class="card">
  <h2>Compleet</h2>
  <p><?php foreach ($complete as $i => $s): ?><?= $i ? ', ' : '' ?><a href="<?= $v->url('/collections', ['series' => $s['series']]) ?>"><?= $v->e($s['series']) ?></a><?php endforeach ?></p>
</section>
<?php endif ?>

<?php if ($unknown): ?>
<section class="card">
  <h2>Niet gevonden bij Wikidata</h2>
  <p class="muted">Klopt de naam van de serie en de schrijver? Pas het eventueel aan bij een boek en zoek de serie opnieuw op.</p>
  <ul class="collection-list"><?php foreach ($unknown as $s): ?><li><a href="<?= $v->url('/collections', ['series' => $s['series']]) ?>"><?= $v->e($s['series']) ?></a></li><?php endforeach ?></ul>
</section>
<?php endif ?>
