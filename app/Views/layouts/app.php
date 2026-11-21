<?php
// render() passes ['contentView'=>..., 'data'=>[...page vars...], 'title'=>...].
// view() extracts with EXTR_SKIP, so $data stays the wrapper array here.
$wrapper = is_array($data ?? null) ? $data : [];
$inner = is_array($wrapper['data'] ?? null) ? $wrapper['data'] : [];
$u = $inner['user'] ?? ($wrapper['user'] ?? null);
$flashes = $inner['flashes'] ?? ($wrapper['flashes'] ?? []);
$title = $wrapper['title'] ?? ($inner['title'] ?? ($title ?? 'WhatsUP'));
$_view = $wrapper['contentView'] ?? ($contentView ?? null);
$role = (string)($u['role'] ?? 'operator');
$isActive = static function (string $prefix) use ($_view): bool {
    return $_view !== null && str_starts_with($_view, $prefix . '/');
};
$sectionLabels = [
    'dashboard' => 'Overview', 'contacts' => 'Contacts', 'groups' => 'Groups',
    'templates' => 'Templates', 'campaigns' => 'Campaigns', 'admin' => 'Administration',
    'profile' => 'Settings', 'errors' => 'Notice',
];
$currentSection = 'Overview';
foreach ($sectionLabels as $prefix => $label) {
    if ($isActive($prefix)) { $currentSection = $label; break; }
}
$initial = strtoupper(mb_substr(trim((string)($u['name'] ?? 'W')), 0, 1));
$icon = static function (string $name): string {
    $paths = [
        'overview' => '<path d="M3 10.5 10 4l7 6.5"/><path d="M5 9.5V18h10V9.5"/><path d="M9 18v-5h2v5"/>',
        'contacts' => '<circle cx="10" cy="7" r="3"/><path d="M4 17c0-3 2.7-5 6-5s6 2 6 5"/>',
        'groups' => '<circle cx="7" cy="7" r="2.5"/><circle cx="14" cy="8.5" r="2"/><path d="M2.5 16c0-2.6 2-4 4.5-4s4.5 1.4 4.5 4"/><path d="M12 16c0-2 1-3.2 2.8-3.2 1.6 0 2.7 1.1 2.7 3.2"/>',
        'templates' => '<rect x="3" y="3.5" width="14" height="13" rx="2"/><path d="M6.5 7.5h7M6.5 10.5h7M6.5 13.5h4"/>',
        'campaigns' => '<path d="M3 8.5v3l5 1.5 7 3V4L8 7 3 8.5Z"/><path d="M8 13v3.5"/>',
        'admin' => '<path d="M10 2.8 16 5v5c0 3.6-2.5 6-6 7.2C6.5 16 4 13.6 4 10V5l6-2.2Z"/><path d="M7.8 10l1.6 1.6 3-3.2"/>',
        'settings' => '<circle cx="10" cy="10" r="2.6"/><path d="M10 2.8v2M10 15.2v2M2.8 10h2M15.2 10h2M4.9 4.9l1.4 1.4M13.7 13.7l1.4 1.4M15.1 4.9l-1.4 1.4M6.3 13.7l-1.4 1.4"/>',
        'logout' => '<path d="M8 3.5H4.5v13H8"/><path d="M9 10h8"/><path d="M14 7l3 3-3 3"/>',
    ];
    return '<svg class="nav-icon" viewBox="0 0 20 20" width="18" height="18" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' . ($paths[$name] ?? '') . '</svg>';
};
?>
<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($title) ?> · WhatsUP</title><meta name="description" content="WhatsUP campaign manager for consented WhatsApp outreach.">
<link rel="stylesheet" href="<?= e(url('assets/css/app.css')) ?>">
<script src="<?= e(url('assets/js/app.js')) ?>" defer></script></head>
<body class="app-body">
<a class="skip-link" href="#main">Skip to content</a>
<div class="app-frame">
<?php if($u): ?>
<aside id="sidebar" class="sidebar" aria-label="Workspace">
  <div class="sidebar-head">
    <a class="brand brand-inverse" href="<?= e(url('dashboard')) ?>"><span class="brand-mark" aria-hidden="true">W</span>WhatsUP</a>
    <button class="sidebar-close" type="button" data-sidebar-close aria-label="Close navigation">&times;</button>
  </div>
  <div class="workspace-chip">
    <span class="avatar" aria-hidden="true"><?= e($initial) ?></span>
    <span class="workspace-meta"><strong><?= e($u['name'] ?? 'Your workspace') ?></strong><span><?= e(ucfirst($role)) ?> account</span></span>
  </div>
  <nav class="sidebar-nav" aria-label="Primary">
    <p class="nav-heading" id="nav-workspace">Workspace</p>
    <ul aria-labelledby="nav-workspace">
      <li><a href="<?= e(url('dashboard')) ?>" <?= $isActive('dashboard')?'aria-current="page"':'' ?>><?= $icon('overview') ?><span>Overview</span></a></li>
      <li><a href="<?= e(url('contacts')) ?>" <?= $isActive('contacts')?'aria-current="page"':'' ?>><?= $icon('contacts') ?><span>Contacts</span></a></li>
      <li><a href="<?= e(url('groups')) ?>" <?= $isActive('groups')?'aria-current="page"':'' ?>><?= $icon('groups') ?><span>Groups</span></a></li>
      <li><a href="<?= e(url('templates')) ?>" <?= $isActive('templates')?'aria-current="page"':'' ?>><?= $icon('templates') ?><span>Templates</span></a></li>
      <li><a href="<?= e(url('campaigns')) ?>" <?= $isActive('campaigns')?'aria-current="page"':'' ?>><?= $icon('campaigns') ?><span>Campaigns</span></a></li>
    </ul>
    <?php if($role === 'admin'): ?>
    <p class="nav-heading" id="nav-admin">Administration</p>
    <ul aria-labelledby="nav-admin">
      <li><a href="<?= e(url('admin')) ?>" <?= $isActive('admin')?'aria-current="page"':'' ?>><?= $icon('admin') ?><span>Admin console</span></a></li>
    </ul>
    <?php endif; ?>
    <p class="nav-heading" id="nav-account">Account</p>
    <ul aria-labelledby="nav-account">
      <li><a href="<?= e(url('profile')) ?>" <?= $isActive('profile')?'aria-current="page"':'' ?>><?= $icon('settings') ?><span>Settings</span></a></li>
    </ul>
  </nav>
  <div class="sidebar-foot">
    <p class="sidebar-quota"><span>Daily limit</span><strong><?= number_format((int)($u['daily_limit'] ?? 0)) ?> parts</strong></p>
    <form method="post" action="<?= e(url('logout')) ?>"><?= csrf_field() ?><button class="sidebar-signout" type="submit"><?= $icon('logout') ?><span>Sign out</span></button></form>
  </div>
</aside>
<div class="sidebar-backdrop" data-sidebar-backdrop hidden></div>
<div class="app-column">
  <header class="app-topbar">
    <button class="sidebar-toggle" type="button" aria-expanded="false" aria-controls="sidebar" aria-label="Open navigation">
      <span class="sidebar-toggle-bars" aria-hidden="true"></span>Menu
    </button>
    <p class="topbar-location"><span class="topbar-eyebrow"><?= e($currentSection) ?></span><span class="topbar-title"><?= e($title) ?></span></p>
    <a class="button button-small topbar-action" href="<?= e(url('campaigns')) ?>">Plan a campaign</a>
  </header>
  <main id="main" class="app-content">
    <div class="content-shell">
    <?php foreach($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endforeach; ?>
    <?php if($_view && is_file(__DIR__.'/../'.$_view.'.php')) { extract($inner,EXTR_SKIP); require __DIR__.'/../'.$_view.'.php'; } else { echo '<p>Content unavailable.</p>'; } ?>
    </div>
  </main>
  <footer class="app-footer"><p>WhatsUP · Consented messaging workspace. <a href="<?= e(url('profile')) ?>">Privacy and limits</a></p></footer>
</div>
<?php else: ?>
<main id="main" class="app-content"><div class="content-shell"><?php foreach($flashes as $flash): ?><div class="alert alert-<?= e($flash['type']) ?>" role="status"><?= e($flash['message']) ?></div><?php endforeach; ?><?php if($_view && is_file(__DIR__.'/../'.$_view.'.php')) { extract($inner,EXTR_SKIP); require __DIR__.'/../'.$_view.'.php'; } else { echo '<p>Content unavailable.</p>'; } ?></div></main>
<?php endif; ?>
</div>
</body></html>
