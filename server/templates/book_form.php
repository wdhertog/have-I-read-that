<?php
/** @var App\View $v */
$val = static fn (string $key) => $v->e($book[$key] ?? '');
$owned = in_array($book['owned'] ?? null, [true, 1, '1', 'on'], true);
?>
<?php if (!$edit): ?>
<section class="card">
  <h2>Zoeken bij Open Library</h2>
  <form method="get" class="row">
    <input type="search" name="q" value="<?= $v->e($q) ?>" placeholder="Titel, auteur of ISBN" autofocus>
    <button>Zoek</button>
  </form>
  <?php if ($results !== null): ?>
    <?php if (!$results): ?><p class="muted">Niets gevonden — vul het formulier hieronder handmatig in.</p><?php endif ?>
    <ul class="results">
      <?php foreach ($results as $r): ?>
      <li><a href="<?= $v->url('/books/add', ['title' => $r['title'], 'author' => $r['author'], 'isbn' => $r['isbn'], 'pages' => $r['pages']]) ?>">
        <strong><?= $v->e($r['title']) ?></strong> — <?= $v->e($r['author']) ?> <?php if ($r['year']): ?><span class="muted">(<?= $r['year'] ?>)</span><?php endif ?>
      </a></li>
      <?php endforeach ?>
    </ul>
  <?php endif ?>
</section>
<?php endif ?>

<?php if (!empty($duplicate)): ?>
<div class="flash error">
  Dit boek lijkt al in je bibliotheek te staan:
  <a href="<?= $v->url('/books/' . $duplicate['id']) ?>"><?= $v->e($duplicate['title']) ?></a><?= $duplicate['author'] !== '' ? ' — ' . $v->e($duplicate['author']) : '' ?>.
  Vink hieronder “Toch toevoegen” aan als je het nog een keer wilt toevoegen.
</div>
<?php endif ?>

<section class="card">
  <h2><?= $edit ? 'Boek bewerken' : 'Boek toevoegen' ?></h2>
  <form method="post" action="<?= $v->url($edit ? "/books/{$id}/edit" : '/books/add') ?>" class="grid" <?= $edit ? 'enctype="multipart/form-data"' : '' ?>>
    <?= $v->csrf() ?>
    <label class="wide">Titel <input name="title" value="<?= $val('title') ?>" required></label>
    <label class="wide">Auteur(s) <input name="author" value="<?= $val('author') ?>"></label>
    <label>ISBN <input name="isbn" value="<?= $val('isbn') ?>"></label>
    <label>Pagina's <input name="pages" type="number" min="1" value="<?= $val('pages') ?>"></label>
    <label>Formaat
      <select name="format">
        <option value="">—</option>
        <?php foreach (App\Books::FORMATS as $f): ?>
          <option value="<?= $f ?>" <?= ($book['format'] ?? '') === $f ? 'selected' : '' ?>><?= $f ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label>Status
      <select name="status">
        <?php foreach (App\Books::STATUSES as $key => $label): ?>
          <option value="<?= $key ?>" <?= ($book['status'] ?? '') === $key ? 'selected' : '' ?>><?= $label ?></option>
        <?php endforeach ?>
      </select>
    </label>
    <label>Beoordeling (0–5) <input name="rating" type="number" min="0.25" max="5" step="0.25" value="<?= $val('rating') ?>"></label>
    <label>Tags / planken <input name="tags" value="<?= $val('tags') ?>" placeholder="fantasy, bieb"></label>
    <label>Uitgever <input name="publisher" value="<?= $val('publisher') ?>" maxlength="255"></label>
    <label>Jaar <input name="published_year" value="<?= $val('published_year') ?>" inputmode="numeric" maxlength="4"></label>
    <label>Serie <input name="series" value="<?= $val('series') ?>" maxlength="255"></label>
    <label>Deel <input name="series_number" value="<?= $v->e(isset($book['series_number']) && $book['series_number'] !== null && $book['series_number'] !== '' ? rtrim(rtrim(number_format((float) str_replace(',', '.', (string) $book['series_number']), 1, ',', ''), '0'), ',') : '') ?>" inputmode="decimal" placeholder="3 of 2,5"></label>
    <?php if (!$edit): ?>
      <label>Begonnen op <input name="started" type="date"></label>
      <label>Uitgelezen op <input name="finished" type="date"></label>
    <?php endif ?>
    <label class="wide">Recensie <textarea name="review" rows="5"><?= $val('review') ?></textarea></label>
    <details class="wide"<?= ($book['description'] ?? null) || ($book['genre'] ?? null) || ($book['original_title'] ?? null) ? ' open' : '' ?>>
      <summary>Meer gegevens (worden ook aangevuld uit de bronnen)</summary>
      <div class="grid">
        <label>Genre <input name="genre" value="<?= $val('genre') ?>" maxlength="255" placeholder="fantasy, roman"></label>
        <label>Eerste verschijning <input name="first_published" value="<?= $val('first_published') ?>" inputmode="numeric" maxlength="5" placeholder="1998"></label>
        <label>Oorspronkelijke titel <input name="original_title" value="<?= $val('original_title') ?>" maxlength="500"></label>
        <label>Oorspronkelijke taal <input name="original_language" value="<?= $v->e(($book['original_language'] ?? '') !== '' ? App\Language::label($book['original_language']) : '') ?>" maxlength="20" placeholder="Engels"></label>
        <label>Vertaler <input name="translator" value="<?= $val('translator') ?>" maxlength="255"></label>
        <label>Prijzen <input name="awards" value="<?= $val('awards') ?>" maxlength="1000"></label>
        <label class="wide">Beschrijving <textarea name="description" rows="5"><?= $val('description') ?></textarea></label>
      </div>
    </details>
    <label class="check"><input type="checkbox" name="owned" <?= $owned ? 'checked' : '' ?>> In bezit</label>
    <?php if ($edit): ?>
      <label class="wide">Eigen omslag (JPEG, PNG of WebP) <input type="file" name="cover" accept="image/jpeg,image/png,image/webp"></label>
      <label class="check"><input type="checkbox" name="remove_cover"> Eigen omslag verwijderen (dan weer die van Open Library)</label>
    <?php endif ?>
    <?php if (!empty($duplicate)): ?>
      <label class="check"><input type="checkbox" name="allow_duplicate"> Toch toevoegen</label>
    <?php endif ?>
    <?php if (!$edit): ?>
      <label class="check"><input type="checkbox" name="toot" <?= $postByDefault ? 'checked' : '' ?>> Post naar Mastodon (bij “Aan het lezen” of “Gelezen”)</label>
    <?php endif ?>
    <div class="wide"><button>Opslaan</button></div>
  </form>
</section>
