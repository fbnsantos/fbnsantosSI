<h1>Alterar palavra-passe</h1>
<form class="card narrow" method="post">
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label>Palavra-passe atual <input type="password" name="current" autocomplete="current-password" required></label>
    <label>Nova palavra-passe (mín. 10) <input type="password" name="new" autocomplete="new-password" minlength="10" required></label>
    <label>Confirmar nova palavra-passe <input type="password" name="confirm" autocomplete="new-password" minlength="10" required></label>
    <button class="btn primary">Guardar</button>
</form>
