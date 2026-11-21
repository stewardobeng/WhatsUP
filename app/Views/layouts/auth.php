<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title??'WhatsUP') ?> · WhatsUP</title><link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>"></head>
<body class="auth-body"><main class="auth-card">
<a class="brand" href="<?= e(url('login')) ?>"><span class="brand-mark" aria-hidden="true">W</span>WhatsUP</a>
<?php foreach(take_flashes() as $flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endforeach; ?>
<?php extract($data,EXTR_SKIP); require __DIR__.'/../auth/'.$contentView.'.php'; ?></main>
<footer class="auth-footer">Consent-first bulk messaging. Never message people who have not opted in.</footer></body></html>
