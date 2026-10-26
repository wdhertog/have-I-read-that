<?php /** @var App\View $v */ if ($goal['goal']): $pct = min(100, (int) round(100 * $goal['read'] / $goal['goal'])); ?>
<p class="goal">
  <strong><?= $goal['read'] ?> van <?= $goal['goal'] ?> boeken</strong> gelezen in <?= $goal['year'] ?>
  <progress max="100" value="<?= $pct ?>"></progress>
  <span class="muted"><?= $goal['read'] >= $goal['goal'] ? 'Doel gehaald! 🎉' : $pct . '%' ?></span>
</p>
<?php endif ?>
