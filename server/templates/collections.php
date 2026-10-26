<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; ?>
<?php if ($current !== ''): ?>
<section class="card">
  <p><a href="<?= $v->url('/collections') ?>">← Alle planken en series</a></p>
  <h1><?= $v->e($current) ?></h1>
  <?php if (!$seriesBooks): ?><p class="muted">Geen boeken in deze serie.</p><?php endif ?>
  <ul class="books">
    <?php foreach ($seriesBooks as $b): ?>
    <li>
      <a href="<?= $v->url('/books/' . $b['id']) ?>" class="book-row">
        <?= $v->partial('_cover', ['b' => $b, 'size' => 'S']) ?>
        <div>
          <strong><?= $b['series_number'] !== null ? 'Deel ' . $v->e(rtrim(rtrim(number_format($b['series_number'], 1, ',', ''), '0'), ',')) . ': ' : '' ?><?= $v->e($b['title']) ?></strong>
          <div class="meta"><span class="badge <?= $b['status'] ?>"><?= $statuses[$b['status']] ?></span>
            <?php if ($b['rating']): ?><span class="stars"><?= $v->stars($b['rating']) ?></span><?php endif ?></div>
        </div>
      </a>
    </li>
    <?php endforeach ?>
  </ul>
</section>
<?php endif ?>

<section class="card">
  <h1>Planken</h1>
  <p class="muted">Een plank is een tag; die van StoryGraph komen er bij het importeren vanzelf in. Een boek zet je op een plank via <em>Bewerken → Tags</em>.</p>
  <?php if (!$shelves): ?><p class="muted">Nog geen planken.</p><?php endif ?>
  <ul class="collection-list">
    <?php foreach ($shelves as $s): ?>
    <li>
      <a href="<?= $v->url('/books', ['shelf' => $s['name']]) ?>"><?= $v->e($s['name']) ?></a> <span class="muted">(<?= $s['count'] ?>)</span>
      <details class="inline-details"><summary>hernoemen</summary>
        <form method="post" action="<?= $v->url('/collections/rename') ?>" class="row">
          <?= $v->csrf() ?>
          <input type="hidden" name="from" value="<?= $v->e($s['name']) ?>">
          <input name="to" value="<?= $v->e($s['name']) ?>" maxlength="200" aria-label="Nieuwe naam">
          <button class="secondary">Opslaan</button>
        </form>
        <p class="muted">Een bestaande naam voegt de planken samen; leeg maken haalt de plank bij alle boeken weg.</p>
      </details>
    </li>
    <?php endforeach ?>
  </ul>
</section>

<section class="card">
  <h1>Series</h1>
  <p class="muted">Vul bij <em>Bewerken</em> de serie en het deel in.</p>
  <?php if (!$series): ?><p class="muted">Nog geen series.</p><?php endif ?>
  <ul class="collection-list">
    <?php foreach ($series as $s): ?>
      <li><a href="<?= $v->url('/collections', ['series' => $s['name']]) ?>"><?= $v->e($s['name']) ?></a>
        <span class="muted"><?= $s['read'] ?> van <?= $s['books'] ?> gelezen</span></li>
    <?php endforeach ?>
  </ul>
</section>
