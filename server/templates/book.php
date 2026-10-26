<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; $today = date('Y-m-d'); ?>
<section class="card book-detail">
  <?= $v->partial('_cover', ['b' => $book, 'size' => 'L']) ?>
  <div>
    <h1><?= $v->e($book['title']) ?></h1>
    <p class="muted"><?= $v->e($book['author']) ?></p>
    <?php if ($book['series']): ?>
      <p><a href="<?= $v->url('/collections', ['series' => $book['series']]) ?>"><?= $v->e($book['series']) ?></a><?= $book['series_number'] !== null ? ', deel ' . $v->e(rtrim(rtrim(number_format($book['series_number'], 1, ',', ''), '0'), ',')) : '' ?></p>
    <?php endif ?>
    <p>
      <span class="badge <?= $book['status'] ?>"><?= $statuses[$book['status']] ?></span>
      <?php if ($book['rating']): ?><span class="stars"><?= $v->stars($book['rating']) ?></span> <span class="muted"><?= $v->rating($book['rating']) ?></span><?php endif ?>
    </p>
    <?= $v->partial('_progress', ['b' => $book]) ?>
    <dl>
      <?php foreach (['contributors' => 'Medewerkers', 'isbn' => 'ISBN', 'pages' => "Pagina's", 'format' => 'Formaat', 'tags' => 'Tags', 'moods' => 'Moods'] as $key => $label): ?>
        <?php if ($book[$key] ?? null): ?><dt><?= $label ?></dt><dd><?= $v->e($book[$key]) ?></dd><?php endif ?>
      <?php endforeach ?>
      <?php foreach ($book['details'] ?? [] as $name => $value): ?>
        <dt><?= $v->e(App\StoryGraph::DETAIL_LABELS[$name] ?? $name) ?></dt><dd><?= $v->e($value) ?></dd>
      <?php endforeach ?>
      <?php if ($book['content_warnings'] ?? null): ?>
        <dt>Inhoudswaarschuwingen</dt><dd><?= $v->e($book['content_warnings']) ?><?php if ($book['content_warning_description']): ?><br><span class="muted"><?= $v->e($book['content_warning_description']) ?></span><?php endif ?></dd>
      <?php endif ?>
      <dt>Toegevoegd</dt><dd><?= $v->e($book['date_added']) ?><?= $book['source'] === 'storygraph' ? ' (StoryGraph)' : '' ?></dd>
    </dl>
    <?php if ($book['review']): ?><blockquote><?= $v->e($book['review']) ?></blockquote><?php endif ?>
    <div class="actions">
      <a class="button secondary" href="<?= $v->url("/books/{$book['id']}/edit") ?>">Bewerken</a>
      <form method="post" action="<?= $v->url("/books/{$book['id']}/delete") ?>" class="inline" data-confirm="Dit boek verwijderen?">
        <?= $v->csrf() ?><button class="danger">Verwijderen</button>
      </form>
    </div>
  </div>
</section>

<div class="two">
<?php if ($book['status'] !== 'reading'): ?>
<section class="card">
  <h2>Begin met lezen</h2>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/start") ?>">
    <?= $v->csrf() ?>
    <label>Begonnen op <input type="date" name="started" value="<?= $today ?>"></label>
    <label class="check"><input type="checkbox" name="toot" <?= $postByDefault ? 'checked' : '' ?>> Post naar Mastodon</label>
    <button><?= $book['status'] === 'read' ? 'Opnieuw lezen' : 'Start' ?></button>
  </form>
</section>
<?php endif ?>

<?php if ($book['status'] === 'reading'): ?>
<section class="card">
  <h2>Hoe ver ben je?</h2>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/progress") ?>">
    <?= $v->csrf() ?>
    <?php if ($book['pages']): ?>
      <label>Op pagina (van <?= (int) $book['pages'] ?>) <input type="number" name="page" min="0" max="<?= (int) $book['pages'] ?>" value="<?= $v->e($book['progress_page']) ?>"></label>
    <?php else: ?>
      <label>Percentage gelezen <input type="number" name="percent" min="0" max="100" value="<?= $v->e($book['progress_percent']) ?>"></label>
    <?php endif ?>
    <label class="check"><input type="checkbox" name="toot"> Post naar Mastodon</label>
    <button>Bijwerken</button>
  </form>
</section>
<?php endif ?>

<?php if (in_array($book['status'], ['reading', 'to-read'], true)): ?>
<section class="card">
  <h2>Uitgelezen</h2>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/finish") ?>">
    <?= $v->csrf() ?>
    <label>Uitgelezen op <input type="date" name="finished" value="<?= $today ?>"></label>
    <label>Beoordeling (0–5) <input name="rating" type="number" min="0.25" max="5" step="0.25" value="<?= $v->e($book['rating']) ?>"></label>
    <label>Recensie <textarea name="review" rows="4"><?= $v->e($book['review']) ?></textarea></label>
    <label class="check"><input type="checkbox" name="dnf"> Niet uitgelezen (DNF)</label>
    <label class="check"><input type="checkbox" name="toot" <?= $postByDefault ? 'checked' : '' ?>> Post naar Mastodon</label>
    <button>Opslaan</button>
  </form>
</section>
<?php endif ?>
</div>

<section class="card" id="notities">
  <h2>Notities en citaten</h2>
  <?php if (!$notes): ?><p class="muted">Nog niets. Bewaar hier mooie zinnen of je gedachten tijdens het lezen.</p><?php endif ?>
  <?= $v->partial('_notes', ['notes' => $notes, 'editable' => true]) ?>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/notes") ?>" class="grid">
    <?= $v->csrf() ?>
    <label>Soort
      <select name="kind"><option value="note">Notitie</option><option value="quote">Citaat</option></select>
    </label>
    <label>Pagina <input name="page" type="number" min="0" max="<?= App\Books::MAX_PAGES ?>"></label>
    <label class="wide">Tekst <textarea name="text" rows="3" maxlength="<?= App\Books::MAX_NOTE ?>" required></textarea></label>
    <label class="check"><input type="checkbox" name="public"> Openbaar (op de openbare boekpagina)</label>
    <div class="wide"><button class="secondary">Bewaren</button></div>
  </form>
</section>

<section class="card">
  <h2>Leesmomenten</h2>
  <?php if ($reads): ?>
  <table>
    <tr><th>Begonnen</th><th>Uitgelezen</th><th></th></tr>
    <?php foreach ($reads as $r): ?>
    <tr>
      <td><?= $v->e($r['started'] ?? '—') ?></td><td><?= $v->e($r['finished'] ?? '…') ?></td>
      <td><form method="post" action="<?= $v->url("/reads/{$r['id']}/delete") ?>" class="inline"><?= $v->csrf() ?><button class="link">verwijder</button></form></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php else: ?><p class="muted">Nog geen leesmomenten.</p><?php endif ?>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/reads") ?>" class="row">
    <?= $v->csrf() ?>
    <input type="date" name="started" title="Begonnen">
    <input type="date" name="finished" title="Uitgelezen">
    <button class="secondary">Toevoegen</button>
  </form>
</section>

<section class="card">
  <h2>Mastodon</h2>
  <?php foreach ($posts as $p): ?>
    <p><?= $v->e($p['created_at']) ?> — <?= $p['kind'] === 'finished' ? 'uitgelezen' : 'begonnen' ?><?php if ($p['url']): ?>: <a href="<?= $v->e($p['url']) ?>" target="_blank" rel="noopener">bekijk toot</a><?php endif ?></p>
  <?php endforeach ?>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/toot") ?>">
    <?= $v->csrf() ?>
    <label>Tekst (pas gerust aan) <textarea name="text" rows="6"><?= $v->e($tootText) ?></textarea></label>
    <input type="hidden" name="kind" value="<?= $tootKind ?>">
    <button class="secondary">Nu posten</button>
  </form>
</section>
