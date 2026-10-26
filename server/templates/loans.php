<?php /** @var App\View $v */ $days = static fn (int $d) => $d === 1 ? '1 dag' : "{$d} dagen"; ?>
<section class="card">
  <h1>Uitgeleend</h1>
  <p class="muted">Een boek leen je uit op de pagina van het boek. Alleen jij ziet dit.</p>
  <?php if (!$people): ?><p class="muted">Je hebt nog niets uitgeleend.</p><?php endif ?>
</section>
<?php foreach ($people as $person): ?>
<section class="card">
  <h2><?= $v->e($person['name']) ?>
    <span class="muted"><?= count($person['current']) ? '· heeft er nu ' . count($person['current']) : '· heeft er nu geen' ?></span></h2>
  <?php foreach ($person['current'] as $loan): ?>
    <div class="loan-row">
      <a href="<?= $v->url('/books/' . $loan['book_id']) ?>"><strong><?= $v->e($loan['title']) ?></strong></a>
      <span class="muted">sinds <?= $v->e($loan['lent_on']) ?> (<?= $days($loan['days']) ?>)<?= $loan['note'] ? ' · ' . $v->e($loan['note']) : '' ?></span>
      <form method="post" action="<?= $v->url("/books/{$loan['book_id']}/return") ?>" class="inline"><?= $v->csrf() ?><button class="link">teruggekregen</button></form>
    </div>
  <?php endforeach ?>
  <?php if ($person['history']): ?>
    <details><summary>Eerder geleend (<?= count($person['history']) ?>)</summary>
      <?php foreach ($person['history'] as $loan): ?>
        <div class="loan-row muted"><a href="<?= $v->url('/books/' . $loan['book_id']) ?>"><?= $v->e($loan['title']) ?></a>
          <?= $v->e($loan['lent_on']) ?> – <?= $v->e($loan['returned_on']) ?> (<?= $days($loan['days']) ?>)</div>
      <?php endforeach ?>
    </details>
  <?php endif ?>
</section>
<?php endforeach ?>
