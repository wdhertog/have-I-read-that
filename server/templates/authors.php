<?php /** @var App\View $v */ ?>
<section class="card">
  <h1>Auteurs</h1>
  <p class="muted">Alle schrijvers in je bibliotheek. Klik op een naam voor een korte bio uit Wikidata en Wikipedia, je boeken en hun andere werk. Zie ook de <a href="<?= $v->url('/publishers') ?>">uitgevers</a>.</p>
  <?php if (!$authors): ?><p class="muted">Nog geen boeken met een schrijver.</p><?php endif ?>
  <table>
    <tr><th>Auteur</th><th class="num">Boeken</th><th class="num">Gelezen</th><th class="num">Gemiddeld</th></tr>
    <?php foreach ($authors as $a): ?>
    <tr>
      <td><a href="<?= $v->url('/author', ['name' => $a['name']]) ?>"><?= $v->e($a['name']) ?></a></td>
      <td class="num"><?= $a['books'] ?></td>
      <td class="num"><?= $a['read'] ?></td>
      <td class="num"><?= $a['rating'] !== null ? $v->rating($a['rating']) . ' ★' : '' ?></td>
    </tr>
    <?php endforeach ?>
  </table>
</section>
