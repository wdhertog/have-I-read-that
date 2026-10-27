<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; ?>
<?= $v->partial('_back', ['href' => $v->url('/import'), 'label' => 'Import & export']) ?>
<header class="page-head">
  <div>
    <h1>Mogelijk dubbele boeken</h1>
    <p class="lead">Boeken met dezelfde schrijver en (hoofd)titel, of waarvan de oorspronkelijke titel de titel van de ander is. Kies welke je bewaart; de ander wordt erin opgenomen.</p>
  </div>
</header>
<?php if (!$pairs): ?><p class="muted">Geen dubbele boeken gevonden.</p><?php endif ?>
<section>
  <?php foreach ($pairs as [$a, $b]): ?>
    <div class="pair card">
      <?php foreach ([[$a, $b], [$b, $a]] as [$keep, $other]): ?>
        <div class="pair-book">
          <?= $v->partial('_cover', ['b' => $keep, 'size' => 'S']) ?>
          <div>
          <a class="strong-link" href="<?= $v->url('/books/' . $keep['id']) ?>"><?= $v->e($keep['title']) ?></a>
          <div class="meta"><?= $v->e($keep['author']) ?><?= $keep['isbn'] ? ' · ' . $v->e($keep['isbn']) : '' ?> · <span class="badge <?= $v->e($keep['status']) ?>"><?= $statuses[$keep['status']] ?></span> toegevoegd <?= $v->e($v->date($keep['date_added'])) ?></div>
          <form method="post" action="<?= $v->url("/books/{$keep['id']}/merge") ?>" data-confirm="“<?= $v->e($other['title']) ?>” opnemen in “<?= $v->e($keep['title']) ?>”?">
            <?= $v->csrf() ?><input type="hidden" name="other" value="<?= $other['id'] ?>">
            <button class="secondary small">Deze bewaren</button>
          </form>
          </div>
        </div>
      <?php endforeach ?>
    </div>
  <?php endforeach ?>
</section>
