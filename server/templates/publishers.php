<?php /** @var App\View $v */ $top = array_slice($publishers, 0, 5); ?>
<section class="card">
  <h1>Uitgevers</h1>
  <p class="muted">Uitgevers van je boeken (zoals ingevuld of aangevuld uit de catalogi). Verschillende schrijfwijzen, zoals “Uitgeverij De Bezige Bij” en “Bezige Bij”, tellen als één.</p>
  <?php if (!$publishers): ?><p class="muted">Nog geen boeken met een uitgever. Vul ze aan via <a href="<?= $v->url('/import') ?>#aanvullen">Gegevens aanvullen</a>.</p><?php endif ?>
</section>

<?php if ($publishers): ?>
<div class="two">
<section class="card">
  <h2>Favorieten</h2>
  <p class="muted">Waar je het meest van las.</p>
  <ol><?php foreach ($top as $p): ?><li><a href="<?= $v->url('/publisher', ['name' => $p['name']]) ?>"><?= $v->e($p['name']) ?></a> <span class="muted">(<?= $p['read'] ?> gelezen)</span></li><?php endforeach ?></ol>
</section>
<section class="card">
  <h2>Best beoordeeld</h2>
  <p class="muted">Met minstens drie beoordeelde boeken.</p>
  <?php if (!$best): ?><p class="muted">Nog geen uitgever met drie beoordeelde boeken.</p><?php endif ?>
  <ol><?php foreach ($best as $p): ?><li><a href="<?= $v->url('/publisher', ['name' => $p['name']]) ?>"><?= $v->e($p['name']) ?></a> <span class="muted">(<?= $v->rating($p['rating']) ?> ★, <?= $p['rated'] ?> boeken)</span></li><?php endforeach ?></ol>
</section>
</div>

<section class="card">
  <h2>Alle uitgevers</h2>
  <table>
    <tr><th>Uitgever</th><th class="num">Boeken</th><th class="num">Gelezen</th><th class="num">Pagina's</th><th class="num">Gemiddeld</th></tr>
    <?php foreach ($publishers as $p): ?>
    <tr>
      <td><a href="<?= $v->url('/publisher', ['name' => $p['name']]) ?>"><?= $v->e($p['name']) ?></a></td>
      <td class="num"><?= $p['books'] ?></td>
      <td class="num"><?= $p['read'] ?></td>
      <td class="num"><?= $p['pages'] ? number_format($p['pages'], 0, ',', '.') : '' ?></td>
      <td class="num"><?= $p['rating'] !== null ? $v->rating($p['rating']) . ' ★' : '' ?></td>
    </tr>
    <?php endforeach ?>
  </table>
</section>
<?php endif ?>
