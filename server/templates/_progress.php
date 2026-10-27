<?php /** @var App\View $v */ if (($b['progress_percent'] ?? null) !== null): ?>
<span class="progress-line">
  <span class="track"><span data-width="<?= (int) $b['progress_percent'] ?>"></span></span>
  <span><?php if ($b['progress_page'] !== null && $b['pages']): ?>p. <?= (int) $b['progress_page'] ?> van <?= (int) $b['pages'] ?> · <?php endif ?><?= (int) $b['progress_percent'] ?>%</span>
</span>
<?php endif ?>
