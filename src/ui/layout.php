<?php

function h(?string $s): string
{
    return htmlspecialchars($s ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function current_user(): string
{
    $user = $_SESSION['user'] ?? 'jdoe';
    $u = user_by_name($user);
    if (!$u || $u['active'] !== 'true' || $u['web_service_access_only'] === 'true') {
        $user = $_SESSION['user'] = 'jdoe';
    }
    return $user;
}

function redirect(string $to): void
{
    header('Location: ' . $to, true, 303);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = [$type, $message];
}

function pending_approvals_for(string $user): array
{
    $mine = [];
    foreach (rec_query('sysapproval_approver', 'state=requested^ORDERBYsys_created_on')[0] as $a) {
        if (can_act_on_approval($user, $a) && !sod_violation($user, $a)) {
            $mine[] = $a;
        }
    }
    return $mine;
}

function render_page(string $title, string $body, string $active = ''): void
{
    $user = current_user();
    $me = user_by_name($user);
    $approvals = count(pending_approvals_for($user));
    $people = rec_query('sys_user', 'active=true^web_service_access_only=false^ORDERBYname')[0];
    $flashes = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    $groups = [];
    foreach (schema() as $t => $def) {
        $groups[$def['group']][$t] = $def['label'];
    }
    $nav = fn(string $key, string $href, string $label) => '<a href="' . $href . '" class="' . ($active === $key ? 'active' : '') . '">' . $label . '</a>';
    header('Content-Type: text/html; charset=UTF-8');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= h($title) ?> | ServiceNow mock</title>
<link rel="stylesheet" href="/assets/style.css">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/"><span class="logo">now</span><span class="mock">mock</span><span class="instance"><?= h(cfg()['instance_name']) ?></span></a>
  <nav>
    <?= $nav('catalog', '/catalog', 'Service Catalog') ?>
    <?= $nav('requests', '/requests', 'My Requests') ?>
    <?= $nav('approvals', '/approvals', 'My Approvals' . ($approvals ? ' <span class="badge">' . $approvals . '</span>' : '')) ?>
    <details class="menu <?= $active === 'backend' ? 'active' : '' ?>">
      <summary>All tables</summary>
      <div class="menu-panel">
        <?php foreach ($groups as $group => $tables): ?>
          <div class="menu-group"><?= h($group) ?></div>
          <?php foreach ($tables as $t => $label): ?>
            <a href="/list/<?= h($t) ?>"><?= h($label) ?> <code><?= h($t) ?></code></a>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </details>
    <?= $nav('pacsync', '/pacsync', 'PAC Sync') ?>
    <?= $nav('integration', '/integration', 'REST API') ?>
  </nav>
  <form class="impersonate" method="post" action="/impersonate">
    <label title="Impersonate a user to act as requester, approver or admin">Acting as</label>
    <select name="user" onchange="this.form.submit()">
      <?php foreach ($people as $p): ?>
        <option value="<?= h($p['user_name']) ?>" <?= $p['user_name'] === $user ? 'selected' : '' ?>><?= h($p['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="hidden" name="back" value="<?= h($_SERVER['REQUEST_URI']) ?>">
  </form>
</header>
<main>
  <?php foreach ($flashes as [$type, $msg]): ?>
    <div class="flash <?= h($type) ?>"><?= nl2br(h($msg)) ?></div>
  <?php endforeach; ?>
  <?= $body ?>
</main>
<footer>Local ServiceNow mock for PAC Sync development. Signed in as <?= h($me['name'] ?? $user) ?>
  (<?= h(implode(', ', user_roles($user)) ?: 'no roles') ?>).</footer>
</body>
</html>
    <?php
}

function state_pill(string $table, string $field, string $value): string
{
    $label = display_value($table, $field, $value);
    $tone = match ($value) {
        'deployed', 'approved', 'closed_complete' => 'good',
        'rejected', 'failed', 'closed_incomplete' => 'bad',
        'pending_approval', 'requested', 'staged', 'work_in_progress', 'retire_requested' => 'warn',
        'retired', 'removed', 'not_required', 'not_yet_requested' => 'muted',
        default => 'neutral',
    };
    return '<span class="pill ' . $tone . '">' . h($label) . '</span>';
}

function ref_link(string $table, string $field, string $value): string
{
    if ($value === '') {
        return '';
    }
    $fd = field_def($table, $field);
    if ($fd['type'] === 'reference') {
        return '<a href="/form/' . h($fd['ref']) . '/' . h($value) . '">' . h(display_value($table, $field, $value)) . '</a>';
    }
    if ($fd['type'] === 'choice' && in_array($field, ['state', 'u_state', 'approval', 'u_deploy_status', 'u_status'], true)) {
        return state_pill($table, $field, $value);
    }
    return h(display_value($table, $field, $value));
}
