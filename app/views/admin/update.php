<h1>Atualizar site</h1>

<?php if ($result): ?>
    <div class="flash <?= $result['ok'] ? 'ok' : 'error' ?>"><?= $result['ok'] ? 'Site atualizado.' : 'A atualização falhou. Veja o registo abaixo.' ?></div>
    <pre class="card deploy-log"><?= e($result['output']) ?></pre>
<?php endif; ?>

<form class="card narrow" method="post" onsubmit="this.querySelector('button').disabled = true; this.querySelector('button').textContent = 'A atualizar…';">
    <?= csrf_field() ?>
    <p>Vai buscar a versão mais recente do ramo <strong><?= e($branch) ?></strong> ao GitHub e substitui o código no servidor
        (o mesmo que o <code>git.php</code>). Conteúdos, imagens e <code>config.php</code> não são afetados.</p>
    <p class="muted">Versão instalada: <?= $version !== '' ? e($version) : 'desconhecida (git indisponível)' ?></p>
    <button class="btn primary" data-confirm="Atualizar o site com a versão do GitHub?">Atualizar agora</button>
</form>
