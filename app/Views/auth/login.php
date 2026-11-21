<h1>Sign in</h1><p class="muted">Manage consented WhatsApp outreach from one workspace.</p>
<form method="post" action="<?= e(url('login')) ?>" class="stack"><?= csrf_field() ?>
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="username" required>
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="current-password" required>
<button type="submit" class="button">Sign in</button></form>
<p class="form-links"><a href="<?= e(url('forgot')) ?>">Forgot your password?</a> <span>·</span> <a href="<?= e(url('register')) ?>">Create an account</a></p>
