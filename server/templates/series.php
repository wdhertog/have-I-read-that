<?php /** @var App\View $v */
$withTips = array_values(array_filter($overview, static fn (array $s) => $s['missing'] !== []));
$complete = array_values(array_filter($overview, static fn (array $s) => $s['qid'] !== null && $s['missing'] === [] && $s['parts'] !== []));
$unknown = array_values(array_filter($overview, static fn (array $s) => $s['qid'] === null));
?>
<header class="page-head">
  <div>
    <h1>Tips</h1>
    <p class="lead">Van de series in je kast zoekt de app bij Wikidata alle delen op en kijkt welke je al hebt (op titel, oorspronkelijke titel of deelnummer). De rest staat hier als tip.</p>
  </div>
  <?php if ($toRefresh > 0): ?>
    <form method="post" action="<?= $v->url('/series/refresh') ?>"><?= $v->csrf() ?>
      <button class="secondary">Series opzoeken (nog <?= (int) $toRefresh ?>)</button></form>
  <?php endif ?>
</header>
<?php if ($toRefresh > 0): ?><p class="muted">Dit gaat in porties van zo'n 20 seconden; klik nog eens tot het klaar is. Na 30 dagen wordt een serie opnieuw opgezocht.</p><?php endif ?>

<section class="section" id="tips">
  <h2>Ontbrekende delen</h2>
  <?php if (!$withTips): ?><p class="muted"><?= $overview ? 'Je hebt alle delen die Wikidata kent.' : 'Nog geen series opgezocht.' ?></p><?php endif ?>
  <?php foreach ($withTips as $s): ?>
    <div class="card">
      <div class="card-head">
        <h3><a class="strong-link" href="<?= $v->url('/collections', ['series' => $s['series']]) ?>"><?= $v->e($s['series']) ?></a></h3>
        <span class="muted">je hebt <?= $s['have'] ?> van de <?= count($s['parts']) ?></span>
      </div>
      <?= $v->partial('_series_parts', ['series' => $s['series'], 'parts' => $s['missing'], 'back' => 'overview', 'onlyMissing' => true]) ?>
    </div>
  <?php endforeach ?>
</section>

<?php if ($complete): ?>
<section class="section">
  <h2>Compleet</h2>
  <p class="pills"><?php foreach ($complete as $s): ?><a class="pill" href="<?= $v->url('/collections', ['series' => $s['series']]) ?>"><?= $v->e($s['series']) ?></a><?php endforeach ?></p>
</section>
<?php endif ?>

<?php if ($unknown): ?>
<section class="section">
  <h2>Niet gevonden bij Wikidata</h2>
  <p class="muted">Klopt de naam van de serie en de schrijver? Pas het eventueel aan bij een boek en zoek de serie opnieuw op.</p>
  <ul class="pills"><?php foreach ($unknown as $s): ?><li><a class="pill" href="<?= $v->url('/collections', ['series' => $s['series']]) ?>"><?= $v->e($s['series']) ?></a></li><?php endforeach ?></ul>
</section>
<?php endif ?>
