<?php /** @var App\View $v */ $days = static fn (int $d) => $d === 1 ? '1 dag' : "{$d} dagen"; ?>
<header class="page-head">
  <div>
    <h1>Uitgeleend</h1>
    <p class="lead">Een boek leen je uit op de pagina van het boek. Alleen jij ziet dit.</p>
  </div>
</header>
<?php if (!$people): ?><p class="muted">Je hebt nog niets uitgeleend.</p><?php endif ?>
<div class="two">
<?php foreach ($people as $person): ?>
<section class="card">
  <div class="person">
    <span class="avatar" aria-hidden="true"><?= $v->e(mb_strtoupper(mb_substr($person['name'], 0, 1))) ?></span>
    <div>
      <h2><?= $v->e($person['name']) ?></h2>
      <p class="muted"><?= count($person['current']) ? 'heeft er nu ' . count($person['current']) : 'heeft er nu geen' ?></p>
    </div>
  </div>
  <?php foreach ($person['current'] as $loan): ?>
    <div class="loan-row">
      <div>
        <a class="strong-link" href="<?= $v->url('/books/' . $loan['book_id']) ?>"><?= $v->e($loan['title']) ?></a>
        <div class="muted">sinds <?= $v->e($v->date($loan['lent_on'])) ?> · <?= $days($loan['days']) ?><?= $loan['note'] ? ' · ' . $v->e($loan['note']) : '' ?></div>
      </div>
      <form method="post" action="<?= $v->url("/books/{$loan['book_id']}/return") ?>" class="inline"><?= $v->csrf() ?><button class="secondary small">Teruggekregen</button></form>
    </div>
  <?php endforeach ?>
  <?php if ($person['history']): ?>
    <details class="history"><summary>Eerder geleend (<?= count($person['history']) ?>)</summary>
      <?php foreach ($person['history'] as $loan): ?>
        <div class="loan-row muted"><a href="<?= $v->url('/books/' . $loan['book_id']) ?>"><?= $v->e($loan['title']) ?></a>
          <span><?= $v->e($v->date($loan['lent_on'])) ?> – <?= $v->e($v->date($loan['returned_on'])) ?> · <?= $days($loan['days']) ?></span></div>
      <?php endforeach ?>
    </details>
  <?php endif ?>
</section>
<?php endforeach ?>
</div>
