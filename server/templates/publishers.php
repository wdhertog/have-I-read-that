<?php /** @var App\View $v */ $top = array_slice($publishers, 0, 5); ?>
<header class="page-head">
  <div>
    <h1>Uitgevers</h1>
    <p class="lead">Uitgevers van je boeken, zoals ingevuld of aangevuld uit de catalogi. Verschillende schrijfwijzen, zoals “Uitgeverij De Bezige Bij” en “Bezige Bij”, tellen als één.</p>
  </div>
</header>
<?php if (!$publishers): ?><p class="muted">Nog geen boeken met een uitgever. Vul ze aan via <a href="<?= $v->url('/import') ?>#aanvullen">Gegevens aanvullen</a>.</p><?php endif ?>

<?php if ($publishers): ?>
<div class="two">
<section class="card">
  <h2>Favorieten</h2>
  <p class="muted">Waar je het meest van las.</p>
  <ol class="ranked"><?php foreach ($top as $p): ?><li><a href="<?= $v->url('/publisher', ['name' => $p['name']]) ?>"><?= $v->e($p['name']) ?></a> <span class="muted"><?= $p['read'] ?> gelezen</span></li><?php endforeach ?></ol>
</section>
<section class="card">
  <h2>Best beoordeeld</h2>
  <p class="muted">Met minstens drie beoordeelde boeken.</p>
  <?php if (!$best): ?><p class="muted">Nog geen uitgever met drie beoordeelde boeken.</p><?php endif ?>
  <ol class="ranked"><?php foreach ($best as $p): ?><li><a href="<?= $v->url('/publisher', ['name' => $p['name']]) ?>"><?= $v->e($p['name']) ?></a> <span class="muted"><?= $v->rating($p['rating']) ?> <span class="star" aria-hidden="true">★</span> · <?= $p['rated'] ?> boeken</span></li><?php endforeach ?></ol>
</section>
</div>

<section class="section">
  <h2>Alle uitgevers</h2>
  <div class="card flush">
  <table>
    <thead><tr><th>Uitgever</th><th class="num">Boeken</th><th class="num">Gelezen</th><th class="num">Pagina’s</th><th class="num">Gemiddeld</th></tr></thead>
    <tbody>
    <?php foreach ($publishers as $p): ?>
    <tr>
      <td><a class="strong-link" href="<?= $v->url('/publisher', ['name' => $p['name']]) ?>"><?= $v->e($p['name']) ?></a></td>
      <td class="num"><?= $p['books'] ?></td>
      <td class="num"><?= $p['read'] ?></td>
      <td class="num"><?= $p['pages'] ? number_format($p['pages'], 0, ',', '.') : '' ?></td>
      <td class="num"><?= $p['rating'] !== null ? $v->rating($p['rating']) . ' <span class="star" aria-hidden="true">★</span>' : '' ?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
  </div>
</section>
<?php endif ?>
