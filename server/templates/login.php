<?php /** @var App\View $v */ ?>
<section class="card narrow">
  <h1>📚 Have I read that?</h1>
  <?php if (!$configured): ?>
    <p class="flash error">Er is nog geen wachtwoord ingesteld. Zet <code>APP_PASSWORD</code> in <code>.env</code>.</p>
  <?php endif ?>
  <form method="post">
    <?= $v->csrf() ?>
    <label>Wachtwoord <input type="password" name="password" autofocus required></label>
    <button>Inloggen</button>
  </form>
</section>
