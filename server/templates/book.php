<?php /** @var App\View $v */ $statuses = App\Books::STATUSES; $today = date('Y-m-d'); ?>
<a class="back" href="<?= $v->url('/books') ?>"><svg class="icon" width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M10 3L5 8l5 5" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>Alle boeken</a>

<?php
$number = static fn ($n) => rtrim(rtrim(number_format((float) $n, 1, ',', ''), '0'), ',');
$facts = [];
if ($book['translator'] ?? null) { $facts['Vertaling'] = $v->e($book['translator']); }
if ($book['publisher'] || $book['published_year']) {
    $facts['Uitgever'] = implode(', ', array_filter([
        $book['publisher'] ? '<a href="' . $v->url('/publisher', ['name' => $book['publisher']]) . '">' . $v->e($book['publisher']) . '</a>' : null,
        $book['published_year'] ? (string) (int) $book['published_year'] : null,
    ]));
}
if ($book['pages']) { $facts["Pagina’s"] = number_format((int) $book['pages'], 0, ',', '.'); }
if ($book['format'] || $book['language']) {
    $facts['Formaat'] = $v->e(implode(' · ', array_filter([$book['format'] ? $v->format($book['format']) : null, $book['language'] ? App\Language::label($book['language']) : null])));
}
if ($book['genre'] ?? null) {
    $facts['Genre'] = implode(', ', array_map(static fn ($g) => '<a href="' . $v->url('/books', ['q' => $g]) . '">' . $v->e($g) . '</a>', App\Books::tagList($book['genre'])));
}
if (($book['original_title'] ?? null) || ($book['original_language'] ?? null)) {
    $facts['Oorspronkelijk'] = $v->e(implode(' · ', array_filter([$book['original_title'] ?? null, ($book['original_language'] ?? null) ? App\Language::label($book['original_language']) : null])));
}
if ($book['first_published'] ?? null) { $facts['Eerste verschijning'] = (string) (int) $book['first_published']; }
if ($book['contributors'] ?? null) { $facts['Medewerkers'] = $v->e($book['contributors']); }
if ($book['isbn']) { $facts['ISBN'] = '<span class="tabular">' . $v->e($book['isbn']) . '</span>'; }
if ($book['moods'] ?? null) { $facts['Moods'] = $v->e($book['moods']); }
foreach ($book['details'] ?? [] as $name => $value) { $facts[App\StoryGraph::DETAIL_LABELS[$name] ?? (string) $name] = $v->e($value); }
if ($book['content_warnings'] ?? null) {
    $facts['Inhoudswaarschuwingen'] = $v->e($book['content_warnings']) . ($book['content_warning_description'] ? '<br><span class="muted">' . $v->e($book['content_warning_description']) . '</span>' : '');
}
if ($book['awards'] ?? null) { $facts['Prijzen'] = $v->e($book['awards']); }
$facts['Toegevoegd'] = $v->e($v->date($book['date_added'])) . ($book['source'] === 'storygraph' ? ' · via StoryGraph' : '');
?>
<div class="detail">
  <div class="detail-cover">
    <?= $v->partial('_cover', ['b' => $book, 'size' => 'L']) ?>
    <?php if (!$v->cover($book)): ?><p>Geen omslag gevonden · <a href="<?= $v->url("/books/{$book['id']}/edit") ?>#omslag">omslag toevoegen</a></p><?php endif ?>
  </div>

  <div class="detail-main">
    <div>
      <h1><?= $v->e($book['title']) ?></h1>
      <?php if ($book['author'] !== ''): ?>
        <p class="byline">door <?php foreach (App\Authors::names($book['author']) as $i => $author): ?><?= $i ? ', ' : '' ?><a href="<?= $v->url('/author', ['name' => $author]) ?>"><?= $v->e($author) ?></a><?php endforeach ?></p>
      <?php endif ?>
      <div class="status-line">
        <span class="badge big <?= $v->e($book['status']) ?>"><?= $statuses[$book['status']] ?></span>
        <?php if ($book['rating']): ?><?= $v->partial('_stars', ['rating' => $book['rating'], 'big' => true]) ?><span class="muted"><?= $v->e(str_replace('.', ',', $v->rating($book['rating']))) ?> van 5</span><?php endif ?>
        <?php if ($book['series']): ?><span class="series"><a href="<?= $v->url('/collections', ['series' => $book['series']]) ?>"><?= $v->e($book['series']) ?></a><?= $book['series_number'] !== null ? ', deel ' . $v->e($number($book['series_number'])) : '' ?></span><?php endif ?>
        <?php if ($book['lent_to'] ?? null): ?><a class="badge lent" href="#uitlenen">Uitgeleend aan <?= $v->e($book['lent_to']) ?></a><?php endif ?>
      </div>
      <?= $v->partial('_progress', ['b' => $book]) ?>
    </div>

    <?php if ($book['review']): ?>
    <figure class="review">
      <svg class="icon" width="34" height="28" viewBox="0 0 34 28" fill="none" aria-hidden="true"><path d="M2 26V16C2 8 6 3 14 2v5c-4 1-6 4-6 8h6v11H2zm18 0V16c0-8 4-13 12-14v5c-4 1-6 4-6 8h6v11H20z" fill="#E0A458" fill-opacity=".85"/></svg>
      <div>
        <blockquote><?= $v->e($book['review']) ?></blockquote>
        <figcaption>Jouw recensie</figcaption>
      </div>
    </figure>
    <?php endif ?>

    <?php if ($book['description'] ?? null): ?><p class="description"><?= nl2br($v->e($book['description'])) ?></p><?php endif ?>

    <dl class="facts">
      <?php foreach ($facts as $label => $html): ?>
        <div><dt><?= $v->e($label) ?></dt><dd><?= $html ?></dd></div>
      <?php endforeach ?>
    </dl>

    <?php if (App\Books::tagList($book['tags'])): ?>
    <div>
      <h2 class="section-label">Op de planken</h2>
      <div class="tags">
        <?php foreach (App\Books::tagList($book['tags']) as $tag): ?>
          <a class="pill small" href="<?= $v->url('/books', ['shelf' => $tag]) ?>"><?= $v->e($tag) ?></a>
        <?php endforeach ?>
      </div>
    </div>
    <?php endif ?>

    <div class="actions">
      <a class="button" href="<?= $v->url("/books/{$book['id']}/edit") ?>">Bewerken</a>
      <?php if ($book['isbn']): ?>
      <form method="post" action="<?= $v->url("/books/{$book['id']}/enrich") ?>" class="inline">
        <?= $v->csrf() ?><button class="secondary" title="Lege velden en de cover opzoeken bij de ingestelde bronnen">Gegevens aanvullen</button>
      </form>
      <?php endif ?>
      <form method="post" action="<?= $v->url("/books/{$book['id']}/delete") ?>" class="inline push" data-confirm="Dit boek verwijderen?">
        <?= $v->csrf() ?><button class="danger">Verwijderen</button>
      </form>
    </div>
<?php if ($book['status'] !== 'reading'): ?>
<section class="card">
  <h2><?= in_array($book['status'], ['read', 'dnf'], true) ? 'Opnieuw lezen?' : 'Begin met lezen' ?></h2>
  <p class="muted"><?= in_array($book['status'], ['read', 'dnf'], true) ? 'Start een nieuwe leesronde; je eerdere ronde en recensie blijven bewaard.' : 'Zet het op “Aan het lezen” en houd je voortgang bij.' ?></p>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/start") ?>" class="row">
    <?= $v->csrf() ?>
    <label>Begonnen op <input type="date" name="started" value="<?= $today ?>"></label>
    <label class="check"><input type="checkbox" name="toot" <?= $postByDefault ? 'checked' : '' ?>> Post naar Mastodon</label>
    <button class="secondary">Begin met lezen</button>
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
</div>

<div class="two section">
<section class="card" id="uitlenen">
  <h2>Uitlenen</h2>
  <?php $current = array_values(array_filter($loans, static fn ($l) => $l['returned_on'] === null))[0] ?? null; ?>
  <?php if ($current): ?>
    <p>Uitgeleend aan <strong><?= $v->e($current['borrower']) ?></strong> sinds <?= $v->e($v->date($current['lent_on'])) ?> (<?= (int) $current['days'] === 1 ? '1 dag' : (int) $current['days'] . ' dagen' ?>)<?= $current['note'] ? ' · ' . $v->e($current['note']) : '' ?>.</p>
    <form method="post" action="<?= $v->url("/books/{$book['id']}/return") ?>" class="row">
      <?= $v->csrf() ?>
      <label>Teruggekregen op <input type="date" name="date" value="<?= $today ?>"></label>
      <button>Teruggekregen</button>
    </form>
  <?php else: ?>
    <?php if (!$book['owned']): ?><p class="muted">Dit boek staat niet als “in bezit”, maar uitlenen kan natuurlijk toch.</p><?php endif ?>
    <form method="post" action="<?= $v->url("/books/{$book['id']}/lend") ?>" class="grid">
      <?= $v->csrf() ?>
      <label>Aan <input name="borrower" list="borrowers" maxlength="100" required autocomplete="off"></label>
      <label>Sinds <input type="date" name="date" value="<?= $today ?>"></label>
      <label class="wide">Notitie <input name="note" maxlength="500" placeholder="bijv. met boekenlegger"></label>
      <datalist id="borrowers"><?php foreach ($borrowers as $name): ?><option value="<?= $v->e($name) ?>"><?php endforeach ?></datalist>
      <div class="wide"><button class="secondary">Uitlenen</button></div>
    </form>
  <?php endif ?>
  <?php $past = array_filter($loans, static fn ($l) => $l['returned_on'] !== null); if ($past): ?>
    <details><summary>Eerder uitgeleend (<?= count($past) ?>)</summary>
      <?php foreach ($past as $l): ?>
        <div class="loan-row muted"><a href="<?= $v->url('/loans') ?>"><?= $v->e($l['borrower']) ?></a> <?= $v->e($v->date($l['lent_on'])) ?> – <?= $v->e($v->date($l['returned_on'])) ?>
          <form method="post" action="<?= $v->url("/loans/{$l['id']}/delete") ?>" class="inline" data-confirm="Uit de geschiedenis halen?"><?= $v->csrf() ?><button class="link">verwijder</button></form></div>
      <?php endforeach ?>
    </details>
  <?php endif ?>
</section>

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

</div>

<div class="two">
<section class="card">
  <h2>Leesmomenten</h2>
  <?php if ($reads): ?>
  <table>
    <tr><th>Begonnen</th><th>Uitgelezen</th><th></th></tr>
    <?php foreach ($reads as $r): ?>
    <tr>
      <td><?= $r['started'] ? $v->e($v->date($r['started'])) : '—' ?></td><td><?= $r['finished'] ? $v->e($v->date($r['finished'])) : '…' ?></td>
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

</div>

<div class="two">
<section class="card" id="delen">
  <h2>Delen</h2>
  <p class="muted">Stuur iemand een berichtje over dit boek, via Signal, WhatsApp, mail of iets anders. Pas de tekst gerust aan.</p>
  <?php foreach (array_filter(['share-back' => ['Terugvragen', $shareAskBack], 'share-opinion' => ['Wat ik ervan vind', $shareOpinion]], static fn ($s) => $s[1] !== null) as $id => [$label, $text]): ?>
    <label><?= $label ?> <textarea id="<?= $id ?>" rows="<?= min(8, 2 + substr_count($text, "\n")) ?>"><?= $v->e($text) ?></textarea></label>
    <div class="actions share-actions">
      <button type="button" data-share="<?= $id ?>">Delen…</button>
      <a class="button secondary" data-whatsapp="<?= $id ?>" href="https://wa.me/?text=<?= rawurlencode($text) ?>" target="_blank" rel="noopener">WhatsApp</a>
      <button type="button" class="secondary" data-copy="<?= $id ?>">Kopiëren</button>
    </div>
  <?php endforeach ?>
</section>

<section class="card" id="samenvoegen">
  <h2>Samenvoegen</h2>
  <p class="muted">Staat dit boek er twee keer in, bijvoorbeeld als paperback en e-book of als vertaling en origineel? Voeg de andere editie hierbij: leesmomenten, notities, uitleningen en posts komen hierheen, lege velden worden aangevuld en het andere boek verdwijnt. Het ISBN van de andere editie blijft bekend, zodat scannen en importeren dit boek terugvinden.</p>
  <?php if ($mergeCandidates): ?>
  <form method="post" action="<?= $v->url("/books/{$book['id']}/merge") ?>" class="row" data-confirm="Het gekozen boek samenvoegen met dit boek? Het andere boek verdwijnt.">
    <?= $v->csrf() ?>
    <select name="other" aria-label="Andere editie">
      <?php foreach ($mergeCandidates as $c): ?>
        <option value="<?= $c['id'] ?>"><?= $v->e($c['title'] . ($c['author'] !== '' ? ' — ' . $c['author'] : '') . ($c['format'] ? " ({$c['format']})" : '') . ($c['isbn'] ? " · {$c['isbn']}" : '') . ' · ' . App\Books::STATUSES[$c['status']]) ?></option>
      <?php endforeach ?>
    </select>
    <button class="secondary">Hierbij voegen</button>
  </form>
  <?php else: ?><p class="muted">Geen boeken met dezelfde auteur of titel gevonden.</p><?php endif ?>
  <p><a href="<?= $v->url('/duplicates') ?>">Alle mogelijke dubbele boeken bekijken</a></p>
</section>
</div>
