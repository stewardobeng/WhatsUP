<h1>Reset your password</h1><p class="muted">We will send a single-use link if an active account matches your address.</p>
<form method="post" action="<?= e(url('forgot')) ?>" class="stack"><?= csrf_field() ?>
<label for="email">Email address</label><input id="email" name="email" type="email" autocomplete="email" required>
<button class="button" type="submit">Send reset link</button></form>
<p class="form-links"><a href="<?= e(url('login')) ?>">Return to sign in</a></p>
