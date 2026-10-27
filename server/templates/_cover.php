<?php /** @var App\View $v */
// A real cover when there is one; underneath (and when it fails to load) a typographic
// cover in one of seven tints, always the same for a book.
$url = $v->cover($b);
$short = trim((string) preg_split('/\s*[:(\[]/u', (string) $b['title'])[0]) ?: (string) $b['title'];
$author = App\Authors::names((string) ($b['author'] ?? ''))[0] ?? '';
?>
<div class="cover <?= $v->e($size ?? 'M') ?> tint-<?= ((int) $b['id']) % 7 ?>">
  <span class="cover-title"><?= $v->e(mb_strlen($short) > 80 ? mb_substr($short, 0, 80) . '…' : $short) ?></span>
  <?php if ($author !== ''): ?><span class="cover-foot"><span class="cover-author"><?= $v->e($author) ?></span></span><?php endif ?>
  <?php if ($url): ?><img src="<?= $v->e($url) ?>" alt="" loading="lazy"><?php endif ?>
</div>
