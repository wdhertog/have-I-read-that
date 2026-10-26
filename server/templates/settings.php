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
    <label class="check"><input type="checkbox" name="read_only"> Alleen lezen</label>
    <button>Token aanmaken</button>
  </form>
  <?php if ($tokens): ?>
  <table>
    <tr><th>Naam</th><th>Rechten</th><th>Aangemaakt</th><th>Laatst gebruikt</th><th></th></tr>
    <?php foreach ($tokens as $t): ?>
    <tr>
      <td><?= $v->e($t['name']) ?></td><td><?= $t['scope'] === 'read' ? 'alleen lezen' : 'volledig' ?></td><td><?= $v->e($t['created_at']) ?></td><td><?= $v->e($t['last_used_at'] ?? '—') ?></td>
      <td><form method="post" action="<?= $v->url("/settings/tokens/{$t['id']}/delete") ?>" class="inline" data-confirm="Token intrekken?"><?= $v->csrf() ?><button class="link">intrekken</button></form></td>
    </tr>
    <?php endforeach ?>
  </table>
  <?php endif ?>
</section>

<section class="card">
  <h1>Tweestapsverificatie</h1>
  <?php if ($recoveryCodes): ?>
    <p><strong>Je herstelcodes</strong> (worden maar één keer getoond). Elke code werkt één keer, voor als je je telefoon kwijt bent. Bewaar ze in je wachtwoordmanager.</p>
    <p class="token"><?= $v->e(implode('  ', $recoveryCodes)) ?></p>
  <?php endif ?>
  <?php if ($twoFactor): ?>
    <p>✅ Staat aan. Bij het inloggen vraagt de site na je wachtwoord om een code uit je authenticator-app. Nog <?= (int) $recoveryLeft ?> herstelcodes over.</p>
    <form method="post" action="<?= $v->url('/settings/2fa/disable') ?>" class="row">
      <?= $v->csrf() ?>
      <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="Huidige code of herstelcode" required>
      <button class="danger">Uitzetten</button>
    </form>
  <?php elseif ($setupSecret): ?>
    <ol>
      <li>Scan deze QR-code met je authenticator-app (bijv. Aegis, 2FAS, Google Authenticator of je wachtwoordmanager).</li>
      <li>Vul de 6 cijfers in die de app toont.</li>
    </ol>
    <div id="totp-qr" class="qr" data-uri="<?= $v->e($setupUri) ?>"></div>
    <p class="muted">Lukt scannen niet? Voer deze sleutel handmatig in: <code><?= $v->e(trim(chunk_split($setupSecret, 4, ' '))) ?></code></p>
    <form method="post" action="<?= $v->url('/settings/2fa/enable') ?>" class="row">
      <?= $v->csrf() ?>
      <input name="code" inputmode="numeric" autocomplete="one-time-code" placeholder="123456" required maxlength="6">
      <button>Bevestigen en aanzetten</button>
    </form>
    <script src="<?= $v->url('/assets/vendor/qrcode-1.4.4.js') ?>"></script>
  <?php else: ?>
    <p>Laat de site na je wachtwoord ook om een code uit een authenticator-app vragen. De Android-app blijft gewoon werken met zijn token.</p>
    <form method="post" action="<?= $v->url('/settings/2fa/start') ?>"><?= $v->csrf() ?><button class="secondary">Instellen</button></form>
  <?php endif ?>
</section>

<section class="card">
  <h1>Mastodon-koppeling</h1>
  <?php if ($mastodon->configured()): ?>
    <p>Instance: <strong><?= $v->e($mastodon->instance()) ?></strong> · zichtbaarheid: <strong><?= $v->e($mastodon->visibility()) ?></strong></p>
    <form method="post" action="<?= $v->url('/settings/test') ?>"><?= $v->csrf() ?><button class="secondary">Test verbinding</button></form>
    <?php if ($account): ?><p>✅ Verbonden als <a href="<?= $v->e(preg_match('#^https://#', (string) ($account['url'] ?? '')) ? $account['url'] : '#') ?>" target="_blank" rel="noopener">@<?= $v->e($account['acct'] ?? '') ?></a></p><?php endif ?>
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
  <p class="muted">Aan te passen met <code>MASTODON_TEMPLATE_FINISHED</code> en <code>MASTODON_TEMPLATE_STARTED</code>. Beschikbaar: <code>{title}</code> <code>{author}</code> <code>{stars}</code> <code>{rating}</code> <code>{review}</code> <code>{isbn}</code> <code>{url}</code>; bij voortgang (<code>MASTODON_TEMPLATE_PROGRESS</code>) ook <code>{percent}</code> <code>{page}</code> <code>{pages}</code>.</p>
  <div class="two">
    <pre class="toot"><?= $v->e($previewStarted) ?></pre>
    <pre class="toot"><?= $v->e($previewFinished) ?></pre>
  </div>
</section>
