<?php /** @var App\View $v */ ?>
<section class="card">
  <h1>StoryGraph-historie importeren</h1>
  <ol>
    <li>Ga in StoryGraph naar <em>Manage Account → Manage Your Data → Export StoryGraph Library</em>.</li>
    <li>Download het CSV-bestand zodra het klaar is.</li>
    <li>Upload het hier. Er wordt <strong>niets</strong> naar Mastodon gepost.</li>
  </ol>
  <p class="muted">Je kunt de import gerust opnieuw doen. Boeken die er al zijn (zelfde ISBN of zelfde titel en auteur) worden niet dubbel toegevoegd: alleen lege velden worden aangevuld en ontbrekende leesmomenten toegevoegd. Wat je zelf hebt ingevuld of aangepast blijft staan. Je ziet eerst een voorbeeld.</p>
  <form method="post" enctype="multipart/form-data" class="row">
    <?= $v->csrf() ?>
    <input type="file" name="file" accept=".csv,text/csv" required>
    <button>Voorbeeld bekijken</button>
  </form>
</section>

<?php if (isset($toEnrich)): ?>
<section class="card" id="aanvullen">
  <h2>Gegevens aanvullen</h2>
  <p>Zoek voor je boeken met een ISBN de lege velden (pagina's, uitgever, jaar, taal, auteur) en de cover op bij de KB, Open Library en Google Books. Wat je zelf hebt ingevuld blijft staan.</p>
  <?php if ($toEnrich > 0): ?>
    <form method="post" action="<?= $v->url('/import/enrich') ?>"><?= $v->csrf() ?>
      <button class="secondary">Aanvullen (nog <?= (int) $toEnrich ?> boeken)</button></form>
    <p class="muted">Dit gaat in porties van zo'n 20 seconden; klik nog eens tot het klaar is.</p>
  <?php else: ?><p class="muted">Alle boeken met een ISBN zijn opgezocht.</p><?php endif ?>
</section>
<?php endif ?>

<?php if (!empty($preview)): ?>
<section class="card">
  <h2>Voorbeeld: dit gaat er gebeuren</h2>
  <p><strong><?= count($preview['imported']) ?></strong> nieuwe boeken, <strong><?= count($preview['updated']) ?></strong> bestaande boeken aangevuld, <?= count($preview['unchanged']) ?> ongewijzigd.</p>
  <?php if ($preview['updated']): ?>
    <details open><summary>Aangevuld</summary><ul>
      <?php foreach ($preview['updated'] as $title => $fields): ?><li><?= $v->e($title) ?>: <span class="muted"><?= $v->e(implode(', ', $fields)) ?></span></li><?php endforeach ?>
    </ul></details>
  <?php endif ?>
  <?php if ($preview['imported']): ?>
    <details><summary>Nieuw</summary><ul>
      <?php foreach ($preview['imported'] as $title): ?><li><?= $v->e($title) ?></li><?php endforeach ?>
    </ul></details>
  <?php endif ?>
  <form method="post" action="<?= $v->url('/import/confirm') ?>" class="actions">
    <?= $v->csrf() ?>
    <button>Bevestigen en importeren</button>
    <a class="button secondary" href="<?= $v->url('/import') ?>">Annuleren</a>
  </form>
</section>
<?php endif ?>
<section class="card">
  <h2>Exporteren</h2>
  <p>Download al je boeken, leesmomenten en leesdoelen. De CSV heeft het StoryGraph-formaat en kun je hier ook weer importeren; de JSON bevat alles.</p>
  <p class="actions">
    <a class="button secondary" href="<?= $v->url('/export.csv') ?>">CSV (StoryGraph-formaat)</a>
    <a class="button secondary" href="<?= $v->url('/export.json') ?>">JSON</a>
  </p>
  <p>Een volledige back-up van de database (ook tokens en instellingen) als SQL, terug te zetten met phpMyAdmin:</p>
  <p><a class="button secondary" href="<?= $v->url('/backup.sql') ?>">Database-back-up (SQL)</a></p>
</section>

<?php if ($result): ?>
<section class="card">
  <h2>Resultaat</h2>
  <p><?= count($result['imported']) ?> toegevoegd, <?= count($result['updated']) ?> aangevuld, <?= count($result['unchanged']) ?> ongewijzigd.</p>
  <?php if ($result['updated']): ?>
    <details><summary>Aangevuld</summary><ul>
      <?php foreach ($result['updated'] as $t => $fields): ?><li><?= $v->e($t) ?>: <span class="muted"><?= $v->e(implode(', ', $fields)) ?></span></li><?php endforeach ?>
    </ul></details>
  <?php endif ?>
  <p><a href="<?= $v->url('/books') ?>">Naar je boeken →</a></p>
</section>
<?php endif ?>
