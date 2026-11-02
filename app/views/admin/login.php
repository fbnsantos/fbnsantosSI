<form class="card auth" method="post" action="/admin/login">
    <h1><?= e(setting('site_name')) ?></h1>
    <p class="muted">Entrar no backoffice</p>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label>Utilizador <input name="username" autocomplete="username" required autofocus></label>
    <label>Palavra-passe <input type="password" name="password" autocomplete="current-password" required></label>
    <button class="btn primary">Entrar</button>
</form>
