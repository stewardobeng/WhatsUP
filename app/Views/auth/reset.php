<h1>Choose a new password</h1>
<form method="post" action="<?= e(url('reset')) ?>" class="stack"><?= csrf_field() ?><input type="hidden" name="token" value="<?= e($token) ?>">
<p class="hint" id="password-help">Use at least 12 characters.</p><label for="password">New password</label><input id="password" name="password" type="password" autocomplete="new-password" aria-describedby="password-help" minlength="12" required>
<button class="button" type="submit">Update password</button></form>
