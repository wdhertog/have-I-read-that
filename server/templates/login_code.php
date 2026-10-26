<?php /** @var App\View $v */ ?>
<section class="card narrow">
  <h1>Inlogcode</h1>
  <p class="muted">Vul de 6 cijfers uit je authenticator-app in, of een van je herstelcodes.</p>
  <form method="post">
    <?= $v->csrf() ?>
    <label>Code <input name="code" inputmode="numeric" autocomplete="one-time-code" autofocus required maxlength="20"></label>
    <button>Inloggen</button>
  </form>
</section>
