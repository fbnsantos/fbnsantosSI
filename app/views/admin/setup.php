<form class="card auth" method="post" action="/admin/setup">
    <h1>Configuração inicial</h1>
    <p class="muted">Crie a conta de administrador. A chave está em <code>config.php</code> (<code>setup_key</code>).</p>
    <?php if ($error): ?><div class="flash error"><?= e($error) ?></div><?php endif; ?>
    <?= csrf_field() ?>
    <label>Chave de configuração <input type="password" name="setup_key" required autofocus></label>
    <label>Utilizador <input name="username" autocomplete="username" required value="<?= e($_POST['username'] ?? '') ?>"></label>
    <label>Palavra-passe (mín. 10 caracteres) <input type="password" name="password" autocomplete="new-password" minlength="10" required></label>
    <button class="btn primary">Criar administrador</button>
</form>
