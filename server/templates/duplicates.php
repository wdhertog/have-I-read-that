<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; ?>
<section class="card">
  <h1>Mogelijk dubbele boeken</h1>
  <p class="muted">Boeken met dezelfde schrijver en (hoofd)titel, of waarvan de oorspronkelijke titel de titel van de ander is. Kies welke je bewaart; de ander wordt erin opgenomen.</p>
  <?php if (!$pairs): ?><p class="muted">Geen dubbele boeken gevonden.</p><?php endif ?>
  <?php foreach ($pairs as [$a, $b]): ?>
    <div class="pair">
      <?php foreach ([[$a, $b], [$b, $a]] as [$keep, $other]): ?>
        <div>
          <a href="<?= $v->url('/books/' . $keep['id']) ?>"><strong><?= $v->e($keep['title']) ?></strong></a>
          <div class="meta"><?= $v->e($keep['author']) ?><?= $keep['isbn'] ? ' · ' . $v->e($keep['isbn']) : '' ?> · <?= $statuses[$keep['status']] ?> · toegevoegd <?= $v->e($keep['date_added']) ?></div>
          <form method="post" action="<?= $v->url("/books/{$keep['id']}/merge") ?>" data-confirm="“<?= $v->e($other['title']) ?>” opnemen in “<?= $v->e($keep['title']) ?>”?">
            <?= $v->csrf() ?><input type="hidden" name="other" value="<?= $other['id'] ?>">
            <button class="secondary">Deze bewaren</button>
          </form>
        </div>
      <?php endforeach ?>
    </div>
  <?php endforeach ?>
</section>
