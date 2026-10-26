<?php /** @var App\View $v */ if (($b['progress_percent'] ?? null) !== null): ?>
<span class="progress-line">
  <progress max="100" value="<?= (int) $b['progress_percent'] ?>"></progress>
  <span class="muted"><?php if ($b['progress_page'] !== null && $b['pages']): ?>p. <?= (int) $b['progress_page'] ?> van <?= (int) $b['pages'] ?> · <?php endif ?><?= (int) $b['progress_percent'] ?>%</span>
</span>
<?php endif ?>
