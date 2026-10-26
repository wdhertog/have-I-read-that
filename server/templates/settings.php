<?php /** @var App\View $v */ /** @var App\Mastodon $mastodon */ ?>
<section class="card">
  <h1>Android-app</h1>
  <p>Vul in de app dit serveradres in, plus een token dat je hieronder aanmaakt:</p>
  <p class="token"><?= $v->e($serverUrl) ?></p>
  <?php if ($newToken): ?>
    <p><strong>Je nieuwe token</strong> (wordt maar één keer getoond):</p>
    <p class="token"><?= $v->e($newToken) ?></p>
  <?php endif ?>
  <form method="post" action="<?= $v->url('/settings/tokens') ?>" class="row">
    <?= $v->csrf() ?>
    <input name="name" placeholder="Naam, bijv. Pixel 8" maxlength="100">
    <button>Token aanmaken</button>
  </form>
  <?php if ($tokens): ?>
  <table>
    <tr><th>Naam</th><th>Aangemaakt</th><th>Laatst gebruikt</th><th></th></tr>
    <?php foreach ($tokens as $t): ?>
    <tr>
      <td><?= $v->e($t['name']) ?></td><td><?= $v->e($t['created_at']) ?></td><td><?= $v->e($t['last_used_at'] ?? '—') ?></td>
      <td><form method="post" action="<?= $v->url("/settings/tokens/{$t['id']}/delete") ?>" class="inline" data-confirm="Token intrekken?"><?= $v->csrf() ?><button class="link">intrekken</button></form></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
</section>

<section class="card">
  <h1>Mastodon-koppeling</h1>
  <?php if ($mastodon->configured()): ?>
    <p>Instance: <strong><?= $v->e($mastodon->instance()) ?></strong> · zichtbaarheid: <strong><?= $v->e($mastodon->visibility()) ?></strong></p>
    <form method="post" action="<?= $v->url('/settings/test') ?>"><?= $v->csrf() ?><button class="secondary">Test verbinding</button></form>
    <?php if ($account): ?><p>✅ Verbonden als <a href="<?= $v->e($account['url'] ?? '') ?>" target="_blank" rel="noopener">@<?= $v->e($account['acct'] ?? '') ?></a></p><?php endif ?>
  <?php else: ?>
    <p class="flash error">Nog niet ingesteld.</p>
  <?php endif ?>
  <h2>Instellen</h2>
  <ol>
    <li>Ga op je Mastodon-server naar <em>Voorkeuren → Ontwikkelaars → Nieuwe applicatie</em>.</li>
    <li>Vink alleen de scopes <code>write:statuses</code> en <code>read:accounts</code> aan.</li>
    <li>Zet het <em>toegangstoken</em> in <code>MASTODON_ACCESS_TOKEN</code> en je server in <code>MASTODON_INSTANCE</code> in <code>.env</code>.</li>
  </ol>
</section>

<section class="card">
  <h2>Voorbeeld van je toots</h2>
  <p class="muted">Aan te passen met <code>MASTODON_TEMPLATE_FINISHED</code> en <code>MASTODON_TEMPLATE_STARTED</code>. Beschikbaar: <code>{title}</code> <code>{author}</code> <code>{stars}</code> <code>{rating}</code> <code>{review}</code> <code>{isbn}</code>.</p>
  <div class="two">
    <pre class="toot"><?= $v->e($previewStarted) ?></pre>
    <pre class="toot"><?= $v->e($previewFinished) ?></pre>
  </div>
</section>
