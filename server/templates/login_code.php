<?php /** @var App\View $v */ ?>
<div class="login">
  <div class="login-inner">
    <div class="login-head">
      <?= $v->partial('_logo', ['size' => 56]) ?>
      <h1>Inlogcode</h1>
      <p>Vul de 6 cijfers uit je authenticator-app in, of een van je herstelcodes.</p>
    </div>
    <form method="post" class="card">
      <?= $v->csrf() ?>
      <label>Code <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required maxlength="20"></label>
      <button>Inloggen</button>
    </form>
  </div>
  <?= $v->partial('_login_shelf') ?>
</div>
