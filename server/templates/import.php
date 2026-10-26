<?php /** @var App\View $v */ ?>
<section class="card">
  <h1>StoryGraph-historie importeren</h1>
  <ol>
    <li>Ga in StoryGraph naar <em>Manage Account → Manage Your Data → Export StoryGraph Library</em>.</li>
    <li>Download het CSV-bestand zodra het klaar is.</li>
    <li>Upload het hier. Er wordt <strong>niets</strong> naar Mastodon gepost.</li>
  </ol>
  <p class="muted">Boeken die al bestaan (zelfde ISBN of zelfde titel + auteur) worden overgeslagen, dus nogmaals importeren is veilig.</p>
  <form method="post" enctype="multipart/form-data" class="row">
    <?= $v->csrf() ?>
    <input type="file" name="file" accept=".csv,text/csv" required>
    <button>Importeren</button>
  </form>
</section>
<section class="card">
  <h2>Exporteren</h2>
  <p>Download al je boeken, leesmomenten en leesdoelen. De CSV heeft het StoryGraph-formaat en kun je hier ook weer importeren; de JSON bevat alles.</p>
  <p class="actions">
    <a class="button secondary" href="<?= $v->url('/export.csv') ?>">CSV (StoryGraph-formaat)</a>
    <a class="button secondary" href="<?= $v->url('/export.json') ?>">JSON</a>
  </p>
</section>

<?php if ($result): ?>
<section class="card">
  <h2>Resultaat</h2>
  <p><?= count($result['imported']) ?> geïmporteerd, <?= count($result['skipped']) ?> overgeslagen.</p>
  <?php if ($result['skipped']): ?>
    <details><summary>Overgeslagen boeken</summary><ul>
      <?php foreach ($result['skipped'] as $t): ?><li><?= $v->e($t) ?></li><?php endforeach ?>
    </ul></details>
  <?php endif ?>
  <p><a href="<?= $v->url('/books') ?>">Naar je boeken →</a></p>
</section>
<?php endif ?>
