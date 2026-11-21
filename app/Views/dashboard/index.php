<?php
$firstName = explode(' ', trim((string)($user['name'] ?? 'there')))[0] ?: 'there';
$quickActions = [
    ['href' => url('campaigns'), 'label' => 'Plan a campaign', 'hint' => 'Pick a template and audience'],
    ['href' => url('contacts'), 'label' => 'Add contacts', 'hint' => 'Only opted-in people'],
    ['href' => url('templates'), 'label' => 'Write a template', 'hint' => 'Text, image, audio, document'],
];
?>
<div class="page-heading split"><div><p class="eyebrow">Your workspace</p><h1>Good day, <?= e($firstName) ?></h1><p class="muted">Reach opted-in contacts with care and clarity.</p></div><a class="button" href="<?= e(url('campaigns')) ?>">Plan a campaign <span aria-hidden="true">→</span></a></div>

<div class="stats-grid">
  <article class="stat-card"><span class="stat-icon" aria-hidden="true">◎</span><p class="stat-label">Contacts</p><p class="stat-value"><?= number_format($contactCount) ?></p><a href="<?= e(url('contacts')) ?>">Manage contacts <span aria-hidden="true">→</span></a></article>
  <article class="stat-card"><span class="stat-icon" aria-hidden="true">✉</span><p class="stat-label">Campaigns</p><p class="stat-value"><?= number_format($campaignCount) ?></p><a href="<?= e(url('campaigns')) ?>">View campaigns <span aria-hidden="true">→</span></a></article>
  <article class="stat-card"><span class="stat-icon" aria-hidden="true">▤</span><p class="stat-label">Templates</p><p class="stat-value"><?= number_format($templateCount) ?></p><a href="<?= e(url('templates')) ?>">Manage content <span aria-hidden="true">→</span></a></article>
  <article class="stat-card quota-card"><span class="stat-icon" aria-hidden="true">↗</span><p class="stat-label">Daily message-part limit</p><p class="stat-value"><?= number_format((int)$user['daily_limit']) ?></p><span class="muted">Each recipient × each part counts once.</span></article>
</div>

<section class="section-block" aria-labelledby="quick-actions-heading">
  <div class="section-heading"><div><h2 id="quick-actions-heading">Quick actions</h2><p class="muted">Jump straight into the part of the workflow you need.</p></div></div>
  <div class="quick-actions">
    <?php foreach($quickActions as $action): ?>
    <a class="quick-action" href="<?= e($action['href']) ?>"><strong><?= e($action['label']) ?></strong><span><?= e($action['hint']) ?></span></a>
    <?php endforeach; ?>
  </div>
</section>

<section class="section-block"><div class="section-heading"><div><h2>Recent campaigns</h2><p class="muted">Follow the delivery status of your recent work.</p></div><a class="text-link" href="<?= e(url('campaigns')) ?>">All campaigns</a></div>
<?php if(!$campaigns): ?><div class="empty-card"><h3>No campaigns yet</h3><p>Create a reusable template, add opted-in contacts, and launch when you are ready.</p><a href="<?= e(url('templates')) ?>" class="button button-secondary">Create a template</a></div><?php else: ?><div class="table-wrap"><table><thead><tr><th>Campaign</th><th>Template</th><th>Status</th><th>Recipients</th><th>Created</th></tr></thead><tbody><?php foreach($campaigns as $c): ?><tr><td><a href="<?= e(url('campaigns/'.$c['id'])) ?>"><?= e($c['name']) ?></a></td><td><?= e($c['template_name']) ?></td><td><span class="badge badge-<?= e($c['status']) ?>"><?= e(ucfirst($c['status'])) ?></span></td><td><?= (int)$c['recipient_count'] ?></td><td><?= e(date('M j, Y',strtotime($c['created_at']))) ?></td></tr><?php endforeach; ?></tbody></table></div><?php endif; ?></section>

<div class="notice notice-info"><strong>Consent comes first.</strong> Only message contacts who have opted in. Suppressed and ineligible contacts are excluded automatically; opt-outs are rechecked before dispatch.</div>
