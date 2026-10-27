<?php /** @var App\View $v */ ?>
<header class="page-head">
  <div>
    <h1>Auteurs</h1>
    <p class="lead">Alle schrijvers in je kast. Klik een naam voor een korte bio uit Wikidata en Wikipedia, je boeken en hun andere werk. Zie ook de <a href="<?= $v->url('/publishers') ?>">uitgevers</a>.</p>
  </div>
</header>
<?php if (!$authors): ?><p class="muted">Nog geen boeken met een schrijver.</p><?php else: ?>
<section class="card flush">
  <table>
    <thead><tr><th>Auteur</th><th class="num">Boeken</th><th class="num">Gelezen</th><th class="num">Gemiddeld</th></tr></thead>
    <tbody>
    <?php foreach ($authors as $a): ?>
    <tr>
      <td><a class="strong-link" href="<?= $v->url('/author', ['name' => $a['name']]) ?>"><?= $v->e($a['name']) ?></a></td>
      <td class="num"><?= $a['books'] ?></td>
      <td class="num"><?= $a['read'] ?></td>
      <td class="num"><?= $a['rating'] !== null ? $v->rating($a['rating']) . ' <span class="star" aria-hidden="true">★</span>' : '' ?></td>
    </tr>
    <?php endforeach ?>
    </tbody>
  </table>
</section>
<?php endif ?>
