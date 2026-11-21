<h1>Create your workspace</h1><p class="muted">A private space for contacts, content and approved campaigns.</p>
<form method="post" action="<?= e(url('register')) ?>" class="stack"><?= csrf_field() ?>
<label for="name">Full name</label><input id="name" name="name" autocomplete="name" required minlength="2" maxlength="120">
<label for="email">Work email</label><input id="email" name="email" type="email" autocomplete="email" required>
<p class="hint" id="password-help">Use at least 12 characters. A unique passphrase is best.</p>
<label for="password">Password</label><input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="password-help" minlength="12" required>
<button class="button" type="submit">Create account</button></form>
<p class="form-links"><a href="<?= e(url('login')) ?>">Already registered? Sign in</a></p>
