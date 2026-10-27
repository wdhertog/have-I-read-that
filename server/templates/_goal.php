<?php /** @var App\View $v */ if ($goal['goal']): $pct = min(100, (int) round(100 * $goal['read'] / $goal['goal'])); ?>
<p class="goal">
  <strong><?= $goal['read'] ?> van <?= $goal['goal'] ?> boeken</strong> <span class="muted">gelezen in <?= $goal['year'] ?></span>
  <span class="track" role="img" aria-label="<?= $pct ?>% van je leesdoel"><span data-width="<?= $pct ?>"></span></span>
  <span class="muted"><?= $goal['read'] >= $goal['goal'] ? 'Doel gehaald!' : $pct . '%' ?></span>
</p>
<?php endif ?>
