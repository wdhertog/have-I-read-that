<?php /** @var App\View $v */ if (($rating ?? null) !== null && $rating > 0): $label = App\Mastodon::formatRating($rating); ?>
<span class="stars<?= !empty($big) ? ' big' : '' ?>" role="img" aria-label="<?= $v->e(str_replace('.', ',', $label)) ?> van 5 sterren"><?= $v->e($v->stars($rating)) ?></span>
<?php endif ?>
