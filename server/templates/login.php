<?php /** @var App\View $v */ ?>
<div class="login">
  <div class="login-inner">
    <div class="login-head">
      <?= $v->partial('_logo', ['size' => 56]) ?>
      <h1>Have I read that?</h1>
      <p>Je eigen boekenkast.</p>
    </div>
    <?php if (!$configured): ?>
      <p class="flash error">Er is nog geen wachtwoord ingesteld. Zet <code>APP_PASSWORD</code> in <code>.env</code>.</p>
    <?php endif ?>
    <form method="post" class="card">
      <?= $v->csrf() ?>
      <label>Wachtwoord <input type="password" name="password" autocomplete="current-password" autofocus required></label>
      <button>Inloggen</button>
    </form>
  </div>
  <?= $v->partial('_login_shelf') ?>
</div>
