<?php
// Service Portal side: home, catalog item, my requests, request detail, approvals, REST API help.

function page_home(): void
{
    $user = current_user();
    $counts = [];
    foreach (table_def('u_pac_rule')['fields']['u_state']['choices'] as $state => $label) {
        $counts[$state] = rec_query('u_pac_rule', "u_state=$state", 0)[1];
    }
    $myOpen = rec_query('sc_req_item', 'requested_for=' . user_sys_id($user) . '^stateINopen,work_in_progress', 0)[1];
    $approvals = count(pending_approvals_for($user));
    ob_start(); ?>
    <section class="hero">
      <h1>How can we help?</h1>
      <p>Request a PAC file exception, follow your requests, and approve the ones assigned to you.</p>
    </section>
    <section class="tiles">
      <a class="tile primary" href="/catalog/pac">
        <h2>Request PAC exception</h2>
        <p>Send a destination DIRECT or through a specific proxy, change or extend an existing rule, or retire it.</p>
      </a>
      <a class="tile" href="/requests"><h2>My Requests</h2><p><strong><?= $myOpen ?></strong> open</p></a>
      <a class="tile" href="/approvals"><h2>My Approvals</h2><p><strong><?= $approvals ?></strong> waiting for you</p></a>
      <a class="tile" href="/list/u_pac_rule"><h2>PAC Rules</h2><p>Backend table <code>u_pac_rule</code></p></a>
    </section>
    <section class="card">
      <h2>PAC rules by state</h2>
      <div class="stats">
        <?php foreach ($counts as $state => $n): ?>
          <a class="stat" href="/list/u_pac_rule?sysparm_query=<?= urlencode("u_state=$state") ?>">
            <span class="num"><?= $n ?></span><?= state_pill('u_pac_rule', 'u_state', $state) ?></a>
        <?php endforeach; ?>
      </div>
    </section>
    <?php render_page('Home', ob_get_clean(), 'home');
}

function page_catalog_index(): void
{
    ob_start(); ?>
    <h1>Service Catalog</h1>
    <section class="tiles">
      <a class="tile primary" href="/catalog/pac">
        <h2>Request PAC exception</h2>
        <p>Network &gt; Proxy. New, modify, extend or retire a PAC file rule. Approved by your group manager and Network Security (and CAB for global rules).</p>
      </a>
    </section>
    <?php render_page('Service Catalog', ob_get_clean(), 'catalog');
}

function rule_for_js(array $r): array
{
    $dt = fn($v) => $v ? substr(str_replace(' ', 'T', $v), 0, 16) : '';
    return [
        'number' => $r['number'], 'u_action' => $r['u_action'], 'u_match_type' => $r['u_match_type'],
        'u_value' => $r['u_value'], 'u_proxy_profile' => $r['u_proxy_profile'], 'u_scope' => $r['u_scope'],
        'u_regions' => array_values(array_filter(explode(',', $r['u_regions']))), 'u_priority' => $r['u_priority'],
        'u_valid_from' => $dt($r['u_valid_from']), 'u_valid_to' => $dt($r['u_valid_to']),
        'u_application' => $r['u_application'], 'u_owner_group' => $r['u_owner_group'],
        'u_change_request' => $r['u_change_request'],
        'label' => "{$r['number']} | " . strtoupper($r['u_action']) . " {$r['u_value']} (" . display_value('u_pac_rule', 'u_scope', $r['u_scope'])
            . ($r['u_scope'] === 'regional' ? ': ' . display_value('u_pac_rule', 'u_regions', $r['u_regions']) : '') . ')',
    ];
}

function page_catalog_pac(string $method): void
{
    $user = current_user();
    $form = ['request_type' => $_GET['type'] ?? 'new', 'u_action' => 'direct', 'u_match_type' => 'host_exact',
        'u_scope' => 'regional', 'u_priority' => '500', 'u_regions' => [], 'target_rule' => $_GET['rule'] ?? ''];
    $error = null;
    if ($method === 'POST') {
        $form = $_POST + ['u_regions' => []];
        try {
            $ritm = submit_pac_request($form, $user);
            flash('success', "Request {$ritm['number']} submitted. It now waits for approval.");
            redirect('/ritm/' . $ritm['sys_id']);
            return;
        } catch (BusinessRuleException $e) {
            $error = $e->getMessage();
        }
    }
    $profiles = rec_query('u_pac_proxy_profile', 'u_active=true^ORDERBYu_name')[0];
    $regions = rec_query('u_pac_region', 'u_active=true^ORDERBYu_code')[0];
    $groups = rec_query('sys_user_group', 'ORDERBYname')[0];
    $changes = rec_query('change_request', 'stateNOT INclosed^ORDERBYnumber')[0];
    $targets = array_map('rule_for_js', targetable_rules());
    $targetsById = array_combine(array_column(targetable_rules(), 'sys_id'), $targets) ?: [];
    $v = fn(string $k) => h(is_array($form[$k] ?? '') ? '' : ($form[$k] ?? ''));
    $sel = fn(string $k, string $val) => (($form[$k] ?? '') === $val) ? 'selected' : '';
    $chk = fn(string $k, string $val) => (($form[$k] ?? '') === $val) ? 'checked' : '';
    ob_start(); ?>
    <div class="breadcrumb"><a href="/catalog">Service Catalog</a> › Network › Proxy</div>
    <div class="catalog-layout">
    <form class="card catalog" method="post" action="/catalog/pac" id="pac-form" novalidate>
      <h1>Request PAC exception</h1>
      <p class="lead">Ask for a destination to bypass the proxy (DIRECT) or to use a specific proxy chain, in one or more regional PAC files.</p>
      <?php if ($error): ?><div class="flash error"><strong>Please fix the following:</strong><br><?= nl2br(h($error)) ?></div><?php endif; ?>

      <fieldset>
        <legend>What do you need?</legend>
        <div class="radio-row">
          <?php foreach (['new' => 'New rule', 'modify' => 'Modify a rule', 'extend' => 'Extend a temporary rule', 'retire' => 'Retire a rule'] as $k => $label): ?>
            <label class="radio-card"><input type="radio" name="request_type" value="<?= $k ?>" <?= $chk('request_type', $k) ?>> <?= $label ?></label>
          <?php endforeach; ?>
        </div>
        <div class="field" data-show="request_type=modify|extend|retire">
          <label for="target_rule">Existing rule <span class="req">*</span></label>
          <select name="target_rule" id="target_rule">
            <option value="">-- Select a rule --</option>
            <?php foreach ($targetsById as $id => $t): ?>
              <option value="<?= h($id) ?>" <?= $sel('target_rule', $id) ?>><?= h($t['label']) ?></option>
            <?php endforeach; ?>
          </select>
          <small>Only approved or deployed rules can be changed.</small>
        </div>
      </fieldset>

      <fieldset id="rule-fields" data-show="request_type=new|modify|extend">
        <legend>Rule</legend>
        <div class="grid2">
          <div class="field lockable">
            <label for="u_action">Action <span class="req">*</span></label>
            <select name="u_action" id="u_action">
              <option value="direct" <?= $sel('u_action', 'direct') ?>>Direct (bypass the proxy)</option>
              <option value="proxy" <?= $sel('u_action', 'proxy') ?>>Proxy (use a proxy profile)</option>
            </select>
          </div>
          <div class="field lockable" data-show="u_action=proxy">
            <label for="u_proxy_profile">Proxy profile <span class="req">*</span></label>
            <select name="u_proxy_profile" id="u_proxy_profile">
              <option value="">-- Select --</option>
              <?php foreach ($profiles as $p): ?>
                <option value="<?= h($p['sys_id']) ?>" <?= $sel('u_proxy_profile', $p['sys_id']) ?> title="<?= h($p['u_proxy_chain']) ?>"><?= h($p['u_name']) ?> (<?= h($p['u_proxy_chain']) ?>)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field lockable">
            <label for="u_match_type">Match type <span class="req">*</span></label>
            <select name="u_match_type" id="u_match_type">
              <?php foreach (table_def('u_pac_rule')['fields']['u_match_type']['choices'] as $k => $label): ?>
                <option value="<?= $k ?>" <?= $sel('u_match_type', $k) ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="field lockable">
            <label for="u_value">Value <span class="req">*</span></label>
            <input name="u_value" id="u_value" value="<?= $v('u_value') ?>" autocomplete="off">
            <small id="value-hint"></small>
          </div>
        </div>
        <div class="field lockable">
          <label>Scope <span class="req">*</span></label>
          <div class="radio-row">
            <label class="radio-card"><input type="radio" name="u_scope" value="regional" <?= $chk('u_scope', 'regional') ?>> Regional</label>
            <label class="radio-card"><input type="radio" name="u_scope" value="global" <?= $chk('u_scope', 'global') ?>> Global (all regions, needs a change request and CAB)</label>
          </div>
        </div>
        <div class="field lockable" data-show="u_scope=regional">
          <label>Regions <span class="req">*</span></label>
          <div class="check-row">
            <?php foreach ($regions as $r): ?>
              <label><input type="checkbox" name="u_regions[]" value="<?= h($r['sys_id']) ?>" <?= in_array($r['sys_id'], (array)($form['u_regions'] ?? []), true) ? 'checked' : '' ?>>
                <?= h($r['u_code']) ?> <small><?= h($r['u_name']) ?></small></label>
            <?php endforeach; ?>
          </div>
        </div>
        <div class="field lockable" data-show="u_scope=global">
          <label for="u_change_request">Change request <span class="req">*</span></label>
          <select name="u_change_request" id="u_change_request">
            <option value="">-- Select --</option>
            <?php foreach ($changes as $c): ?>
              <option value="<?= h($c['sys_id']) ?>" <?= $sel('u_change_request', $c['sys_id']) ?>><?= h($c['number']) ?> | <?= h($c['short_description']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="grid3">
          <div class="field lockable">
            <label for="u_priority">Priority</label>
            <input type="number" min="1" max="999" name="u_priority" id="u_priority" value="<?= $v('u_priority') ?>">
            <small>1 to 999, lower is evaluated first.</small>
          </div>
          <div class="field lockable">
            <label for="u_valid_from">Valid from (UTC)</label>
            <input type="datetime-local" name="u_valid_from" id="u_valid_from" value="<?= $v('u_valid_from') ?>">
            <small>Empty = as soon as deployed.</small>
          </div>
          <div class="field">
            <label for="u_valid_to">Valid to (UTC) <span class="req extend-only">*</span></label>
            <input type="datetime-local" name="u_valid_to" id="u_valid_to" value="<?= $v('u_valid_to') ?>">
            <small>Empty = permanent. At most 365 days.</small>
          </div>
        </div>
        <div class="grid2">
          <div class="field lockable">
            <label for="u_owner_group">Owner group <span class="req">*</span></label>
            <select name="u_owner_group" id="u_owner_group">
              <option value="">-- Select --</option>
              <?php foreach ($groups as $g): ?>
                <option value="<?= h($g['sys_id']) ?>" <?= $sel('u_owner_group', $g['sys_id']) ?>><?= h($g['name']) ?></option>
              <?php endforeach; ?>
            </select>
            <small>Its manager approves first and is notified before expiry.</small>
          </div>
          <div class="field lockable">
            <label for="u_application">Business service</label>
            <input name="u_application" id="u_application" value="<?= $v('u_application') ?>">
          </div>
        </div>
      </fieldset>

      <fieldset>
        <legend>Why?</legend>
        <div class="field">
          <label for="u_justification">Business justification <span class="req">*</span></label>
          <textarea name="u_justification" id="u_justification" rows="3"><?= $v('u_justification') ?></textarea>
        </div>
      </fieldset>
      <div class="actions"><button class="btn primary" type="submit">Submit request</button></div>
    </form>
    <aside class="card help">
      <h3>How it works</h3>
      <ol>
        <li>Your owner group manager approves.</li>
        <li>Network Security reviews.</li>
        <li>CAB approves global rules.</li>
        <li>PAC Sync deploys the rule within about 15 minutes and closes your request.</li>
      </ol>
      <h3>Match types</h3>
      <dl>
        <dt>Host (exact)</dt><dd><code>intranet.corp.local</code></dd>
        <dt>Domain and sub-domains</dt><dd><code>corp.local</code> also matches <code>a.corp.local</code></dd>
        <dt>IP network</dt><dd><code>10.20.0.0/16</code></dd>
        <dt>URL pattern</dt><dd><code>https://sso.partner.com/*</code></dd>
      </dl>
    </aside>
    </div>
    <script>window.PAC_RULES = <?= json_encode($targetsById, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;</script>
    <script src="/assets/catalog.js"></script>
    <?php render_page('Request PAC exception', ob_get_clean(), 'catalog');
}

function page_requests(): void
{
    $user = current_user();
    $all = isset($_GET['all']) && is_pac_admin($user);
    $q = ($all ? '' : 'requested_for=' . user_sys_id($user) . '^') . 'ORDERBYDESCnumber';
    [$rows] = rec_query('sc_req_item', $q);
    ob_start(); ?>
    <div class="page-head">
      <h1><?= $all ? 'All requests' : 'My Requests' ?></h1>
      <?php if (is_pac_admin($user)): ?>
        <a class="btn" href="/requests<?= $all ? '' : '?all=1' ?>"><?= $all ? 'Only mine' : 'Show all' ?></a>
      <?php endif; ?>
      <a class="btn primary" href="/catalog/pac">New request</a>
    </div>
    <table class="list">
      <thead><tr><th>Number</th><th>Type</th><th>Description</th><th>Requested for</th><th>Approval</th><th>State</th><th>Opened</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td><a href="/ritm/<?= h($r['sys_id']) ?>"><?= h($r['number']) ?></a></td>
          <td><?= h(display_value('sc_req_item', 'u_request_type', $r['u_request_type'])) ?></td>
          <td><?= h($r['short_description']) ?></td>
          <td><?= h(display_value('sc_req_item', 'requested_for', $r['requested_for'])) ?></td>
          <td><?= state_pill('sc_req_item', 'approval', $r['approval']) ?></td>
          <td><?= state_pill('sc_req_item', 'state', $r['state']) ?></td>
          <td><?= h($r['sys_created_on']) ?></td>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="7" class="empty">No requests yet. <a href="/catalog/pac">Request a PAC exception</a>.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <?php render_page('My Requests', ob_get_clean(), 'requests');
}

function rule_summary(?array $rule): string
{
    if (!$rule) {
        return '';
    }
    $rows = [
        'Rule' => '<a href="/form/u_pac_rule/' . h($rule['sys_id']) . '">' . h($rule['number']) . '</a> ' . state_pill('u_pac_rule', 'u_state', $rule['u_state'])
            . ' ' . state_pill('u_pac_rule', 'u_deploy_status', $rule['u_deploy_status']),
        'Action' => h(display_value('u_pac_rule', 'u_action', $rule['u_action']))
            . ($rule['u_proxy_profile'] ? ' via ' . h(display_value('u_pac_rule', 'u_proxy_profile', $rule['u_proxy_profile'])) : ''),
        'Destination' => h(display_value('u_pac_rule', 'u_match_type', $rule['u_match_type'])) . ': <code>' . h($rule['u_value']) . '</code>',
        'Scope' => h(display_value('u_pac_rule', 'u_scope', $rule['u_scope']))
            . ($rule['u_scope'] === 'regional' ? ': ' . h(display_value('u_pac_rule', 'u_regions', $rule['u_regions'])) : ''),
        'Priority' => h($rule['u_priority']),
        'Validity' => h(($rule['u_valid_from'] ?: 'now') . ' → ' . ($rule['u_valid_to'] ?: 'permanent')),
    ];
    if ($rule['u_deploy_version'] || $rule['u_deploy_message']) {
        $rows['Deployment'] = h(trim($rule['u_deploy_version'] . ' ' . $rule['u_deployed_on'] . ' ' . $rule['u_deploy_message']));
    }
    $out = '<dl class="summary">';
    foreach ($rows as $k => $v) {
        $out .= "<dt>$k</dt><dd>$v</dd>";
    }
    return $out . '</dl>';
}

/**
 * "PAC Sync deployment" step of the RITM timeline, driven by the rule's u_deploy_status (written by PAC Sync),
 * with the u_pac_deployment rows of the rule's regions logged since the request was approved.
 */
function deployment_step(array $ritm, ?array $rule, array $approvals): string
{
    $closed = $ritm['state'] === 'closed_complete';
    if ($ritm['approval'] !== 'approved' || !$rule) {
        return '<li class="not_yet_requested"><div><strong>PAC Sync deployment</strong></div></li>';
    }
    $status = $rule['u_deploy_status'];
    $class = 'requested';
    $detail = '';
    if ($status === 'failed') {
        $class = 'rejected';
        $pill = state_pill('u_pac_rule', 'u_deploy_status', 'failed');
        $detail = $rule['u_deploy_message'] !== '' ? '<blockquote>' . nl2br(h($rule['u_deploy_message'])) . '</blockquote>' : '';
    } elseif ($status === 'staged' && !$closed) {
        $pill = state_pill('u_pac_rule', 'u_deploy_status', 'staged');
        $detail = '<div class="muted">' . ($rule['u_deploy_message'] !== '' ? h($rule['u_deploy_message'])
            : 'Staged in ZIA, waiting for promotion') . ' (<code>pac-sync promote --region X</code>)</div>';
    } elseif (in_array($status, ['deployed', 'removed'], true) || $closed) {
        $class = 'approved';
        $pill = state_pill('u_pac_rule', 'u_deploy_status', $status === 'removed' ? 'removed' : 'deployed')
            . ($closed ? ' ' . state_pill('sc_req_item', 'state', 'closed_complete') : '');
        $detail = '<div class="muted">' . h(trim(($rule['u_deploy_version'] ? 'ZIA ' . $rule['u_deploy_version'] : '')
            . ($rule['u_deployed_on'] ? ' on ' . $rule['u_deployed_on'] : ''))) . '</div>'
            . ($rule['u_deploy_message'] !== '' ? '<div class="muted">' . h($rule['u_deploy_message']) . '</div>' : '');
    } else {
        $pill = '<span class="pill warn">Waiting for next run</span>';
    }

    $approvedAt = max(array_merge([''], array_column(array_filter($approvals, fn($a) => $a['state'] === 'approved'), 'sys_updated_on')));
    $regions = $rule['u_scope'] === 'global'
        ? array_column(rec_query('u_pac_region', 'u_active=true')[0], 'sys_id')
        : array_values(array_filter(explode(',', $rule['u_regions'])));
    $rows = [];
    if ($regions) {
        $q = 'u_regionIN' . implode(',', $regions) . ($approvedAt !== '' ? '^sys_created_on>=' . $approvedAt : '') . '^ORDERBYDESCsys_created_on';
        $rows = rec_query('u_pac_deployment', $q, 20)[0];
    }
    $list = '';
    foreach ($rows as $d) {
        $list .= '<li><a href="/form/u_pac_deployment/' . h($d['sys_id']) . '">' . h($d['number']) . '</a> '
            . h(display_value('u_pac_deployment', 'u_region', $d['u_region'])) . ' '
            . state_pill('u_pac_deployment', 'u_status', $d['u_status'])
            . ($d['u_zia_version'] !== '' ? ' v' . h($d['u_zia_version']) : '')
            . ' <span class="muted">' . h($d['sys_created_on']) . '</span></li>';
    }
    return '<li class="' . $class . '"><div><strong>PAC Sync deployment</strong> ' . $pill . '</div>' . $detail
        . ($list ? '<ul class="deployments">' . $list . '</ul>' : '') . '</li>';
}

function page_ritm(string $id): void
{
    $ritm = rec_get('sc_req_item', $id) ?? throw new NotFoundException('Request not found');
    $approvals = rec_query('sysapproval_approver', "sysapproval=$id^ORDERBYorder")[0];
    $rule = $ritm['u_pac_rule'] ? rec_get('u_pac_rule', $ritm['u_pac_rule']) : null;
    $target = $ritm['u_target_rule'] ? rec_get('u_pac_rule', $ritm['u_target_rule']) : null;
    $keys = array_filter([$id, $ritm['u_pac_rule'], $ritm['u_target_rule'], ...array_column($approvals, 'sys_id')]);
    $activity = rec_query('sys_audit', 'documentkeyIN' . implode(',', $keys) . '^fieldname!=record^ORDERBYDESCsys_created_on', 60)[0];
    $vars = json_decode($ritm['variables'] ?: '{}', true) ?: [];
    ob_start(); ?>
    <div class="breadcrumb"><a href="/requests">My Requests</a> › <?= h($ritm['number']) ?></div>
    <div class="page-head">
      <h1><?= h($ritm['number']) ?> <small><?= h($ritm['short_description']) ?></small></h1>
      <?= state_pill('sc_req_item', 'approval', $ritm['approval']) ?> <?= state_pill('sc_req_item', 'state', $ritm['state']) ?>
      <a class="btn" href="/form/sc_req_item/<?= h($id) ?>">Open in backend</a>
    </div>
    <div class="grid2 top">
      <section class="card">
        <h2>Request</h2>
        <dl class="summary">
          <dt>Item</dt><dd><?= h($ritm['cat_item']) ?></dd>
          <dt>Type</dt><dd><?= h(display_value('sc_req_item', 'u_request_type', $ritm['u_request_type'])) ?></dd>
          <dt>Requested for</dt><dd><?= h(display_value('sc_req_item', 'requested_for', $ritm['requested_for'])) ?></dd>
          <dt>Opened</dt><dd><?= h($ritm['sys_created_on']) ?></dd>
          <dt>Justification</dt><dd><?= nl2br(h($vars['u_justification'] ?? '')) ?></dd>
        </dl>
        <?php if ($rule): ?><h3><?= $target ? 'New rule' : 'Rule' ?></h3><?= rule_summary($rule) ?><?php endif; ?>
        <?php if ($target): ?><h3><?= $ritm['u_request_type'] === 'retire' ? 'Rule to retire' : 'Replaces' ?></h3><?= rule_summary($target) ?><?php endif; ?>
      </section>
      <section class="card">
        <h2>Approvals</h2>
        <ol class="timeline">
          <?php foreach ($approvals as $a): ?>
            <li class="<?= h($a['state']) ?>">
              <div><strong><?= h($a['u_step']) ?></strong> <?= state_pill('sysapproval_approver', 'state', $a['state']) ?></div>
              <div class="muted"><?= h($a['approver'] ? display_value('sysapproval_approver', 'approver', $a['approver'])
                      : 'Group: ' . display_value('sysapproval_approver', 'u_approval_group', $a['u_approval_group'])) ?>
                <?php if (in_array($a['state'], ['approved', 'rejected'], true)): ?>, by <?= h($a['sys_updated_by']) ?> on <?= h($a['sys_updated_on']) ?><?php endif; ?></div>
              <?php if ($a['comments']): ?><blockquote><?= nl2br(h($a['comments'])) ?></blockquote><?php endif; ?>
            </li>
          <?php endforeach; ?>
          <?= deployment_step($ritm, $ritm['u_request_type'] === 'retire' ? $target : $rule, $approvals) ?>
        </ol>
      </section>
    </div>
    <section class="card">
      <h2>Activity</h2>
      <table class="list compact">
        <thead><tr><th>When</th><th>Who</th><th>Record</th><th>Field</th><th>Change</th></tr></thead>
        <tbody>
        <?php foreach ($activity as $a): ?>
          <tr><td><?= h($a['sys_created_on']) ?></td><td><?= h($a['user']) ?></td><td><?= h(table_def($a['tablename'])['label']) ?></td>
            <td><?= h(field_def($a['tablename'], $a['fieldname'])['label']) ?></td>
            <td><?= h(display_value($a['tablename'], $a['fieldname'], $a['oldvalue'])) ?> → <strong><?= h(display_value($a['tablename'], $a['fieldname'], $a['newvalue'])) ?></strong></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
    <?php render_page($ritm['number'], ob_get_clean(), 'requests');
}

function page_approvals(string $method, ?string $id): void
{
    $user = current_user();
    if ($method === 'POST' && $id) {
        $decision = ($_POST['decision'] ?? '') === 'approve' ? 'approved' : 'rejected';
        try {
            in_transaction(fn() => rec_update('sysapproval_approver', $id, ['state' => $decision, 'comments' => trim($_POST['comments'] ?? '')], $user));
            $a = rec_get('sysapproval_approver', $id);
            $ritm = rec_get('sc_req_item', $a['sysapproval']);
            flash('success', "{$ritm['number']}: {$a['u_step']} " . ($decision === 'approved' ? 'approved' : 'rejected') . '.');
        } catch (BusinessRuleException $e) {
            flash('error', $e->getMessage());
        }
        redirect('/approvals');
        return;
    }
    $pending = pending_approvals_for($user);
    [$done] = rec_query('sysapproval_approver', "sys_updated_by=$user^stateINapproved,rejected^ORDERBYDESCsys_updated_on", 20);
    ob_start(); ?>
    <h1>My Approvals</h1>
    <?php if (!$pending): ?><div class="card empty">Nothing waiting for you. Switch user (top right) to act as another approver.</div><?php endif; ?>
    <?php foreach ($pending as $a):
        $ritm = rec_get('sc_req_item', $a['sysapproval']);
        $rule = $ritm['u_pac_rule'] ? rec_get('u_pac_rule', $ritm['u_pac_rule']) : null;
        $target = $ritm['u_target_rule'] ? rec_get('u_pac_rule', $ritm['u_target_rule']) : null;
        $vars = json_decode($ritm['variables'] ?: '{}', true) ?: []; ?>
      <section class="card approval">
        <div class="page-head">
          <h2><a href="/ritm/<?= h($ritm['sys_id']) ?>"><?= h($ritm['number']) ?></a> <small><?= h($ritm['short_description']) ?></small></h2>
          <span class="pill warn"><?= h($a['u_step']) ?></span>
        </div>
        <p class="muted">Requested by <?= h(display_value('sc_req_item', 'requested_for', $ritm['requested_for'])) ?> on <?= h($ritm['sys_created_on']) ?>.
          Justification: <?= h($vars['u_justification'] ?? '') ?></p>
        <div class="grid2">
          <div><?php if ($rule): ?><h3>Rule</h3><?= rule_summary($rule) ?><?php endif; ?></div>
          <div><?php if ($target): ?><h3><?= $ritm['u_request_type'] === 'retire' ? 'Rule to retire' : 'Replaces' ?></h3><?= rule_summary($target) ?><?php endif; ?></div>
        </div>
        <form method="post" action="/approvals/<?= h($a['sys_id']) ?>" class="decision">
          <textarea name="comments" rows="2" placeholder="Comments (required to reject)"></textarea>
          <button class="btn primary" name="decision" value="approve">Approve</button>
          <button class="btn danger" name="decision" value="reject">Reject</button>
        </form>
      </section>
    <?php endforeach; ?>
    <?php if ($done): ?>
      <h2>Recently decided by you</h2>
      <table class="list compact">
        <thead><tr><th>Request</th><th>Step</th><th>Decision</th><th>Comments</th><th>When</th></tr></thead>
        <tbody>
        <?php foreach ($done as $a): ?>
          <tr><td><a href="/ritm/<?= h($a['sysapproval']) ?>"><?= h(display_value('sysapproval_approver', 'sysapproval', $a['sysapproval'])) ?></a></td>
            <td><?= h($a['u_step']) ?></td><td><?= state_pill('sysapproval_approver', 'state', $a['state']) ?></td>
            <td><?= h($a['comments']) ?></td><td><?= h($a['sys_updated_on']) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
    <?php render_page('My Approvals', ob_get_clean(), 'approvals');
}

function page_integration(): void
{
    $base = 'http://' . ($_SERVER['HTTP_HOST'] ?? '127.0.0.1:8090');
    [$clientId, $client] = [array_key_first(cfg()['oauth_clients']), cfg()['oauth_clients'][array_key_first(cfg()['oauth_clients'])]];
    $pw = cfg()['seed_passwords']['svc_pac_sync'];
    ob_start(); ?>
    <h1>REST API</h1>
    <p class="lead">Same contract as a real instance: <code>/api/now/table/{table}</code> and <code>/oauth_token.do</code>.
      Point PAC Sync at <code><?= h($base) ?></code> with <code>source: api</code>.</p>
    <div class="grid2 top">
      <section class="card">
        <h2>Credentials</h2>
        <table class="list compact">
          <tr><th>OAuth client_id</th><td><code><?= h($clientId) ?></code></td></tr>
          <tr><th>OAuth client_secret</th><td><code><?= h($client['secret']) ?></code></td></tr>
          <tr><th>Token acts as</th><td><code><?= h($client['user']) ?></code> (role <code><?= ROLE_INTEGRATION ?></code>)</td></tr>
          <tr><th>Basic auth</th><td><code>svc_pac_sync / <?= h($pw) ?></code> or <code>admin / admin</code></td></tr>
        </table>
        <h3>PAC Sync environment</h3>
        <pre>SNOW_CLIENT_ID=<?= h($clientId) ?>

SNOW_CLIENT_SECRET=<?= h($client['secret']) ?></pre>
      </section>
      <section class="card">
        <h2>Integration account ACLs</h2>
        <table class="list compact">
          <thead><tr><th>Table</th><th>Read</th><th>Write</th><th>Create</th></tr></thead>
          <tr><td>u_pac_region</td><td>yes</td><td>no</td><td>no</td></tr>
          <tr><td>u_pac_proxy_profile</td><td>yes</td><td>no</td><td>no</td></tr>
          <tr><td>u_pac_rule</td><td>yes</td><td>u_deploy_*, u_state (approved→deployed/retired, deployed→retired, retire_requested→retired)</td><td>no</td></tr>
          <tr><td>u_pac_deployment</td><td>yes</td><td>no</td><td>yes</td></tr>
        </table>
      </section>
    </div>
    <section class="card">
      <h2>Try it</h2>
      <pre>
# 1. token
curl -s -X POST <?= h($base) ?>/oauth_token.do \
  -d grant_type=client_credentials -d client_id=<?= h($clientId) ?> -d client_secret=<?= h($client['secret']) ?>


# 2. the query PAC Sync runs
curl -s -H "Authorization: Bearer $TOKEN" \
  "<?= h($base) ?>/api/now/table/u_pac_rule?sysparm_query=u_stateINapproved,deployed,retire_requested^ORDERBYu_priority&amp;sysparm_exclude_reference_link=true&amp;sysparm_limit=1000"

# 3. status write-back
curl -s -X PATCH -u svc_pac_sync:<?= h($pw) ?> -H "Content-Type: application/json" \
  -d '{"u_deploy_status":"deployed","u_state":"deployed"}' <?= h($base) ?>/api/now/table/u_pac_rule/&lt;sys_id&gt;</pre>
      <p>Supported: <code>sysparm_query</code> (=, !=, IN, NOT IN, LIKE, STARTSWITH, ENDSWITH, &lt;, &gt;, ISEMPTY, ISNOTEMPTY, ^OR, ^NQ, ORDERBY, ORDERBYDESC),
        <code>sysparm_fields</code>, <code>sysparm_limit</code>, <code>sysparm_offset</code>, <code>sysparm_display_value</code> (true/false/all),
        <code>sysparm_exclude_reference_link</code>, header <code>X-Total-Count</code>. Business rule failures return 403 like ServiceNow.</p>
    </section>
    <?php render_page('REST API', ob_get_clean(), 'integration');
}

/**
 * /pacsync: what PAC Sync last did per region, from the u_pac_deployment rows it logs (staged, deployed, failed,
 * rolled_back) and the rules it marked failed. Reads u_pac_deployment, u_pac_region and u_pac_rule only; every value
 * is escaped. The ZIA version columns are the ones PAC Sync reported, not a live read of ZIA.
 */
function page_pacsync(): void
{
    $regions = rec_query('u_pac_region', 'u_active=true^ORDERBYu_code')[0];
    // Per region: its newest row, because a later run of another region must not hide that this one is still not activated.
    $lastByRegion = [];
    foreach ($regions as $reg) {
        $lastByRegion[$reg['sys_id']] = rec_query('u_pac_deployment', 'u_region=' . $reg['sys_id'] . '^ORDERBYDESCsys_created_on', 1)[0][0] ?? null;
    }
    $notActivated = [];
    foreach ($regions as $reg) {
        $row = $lastByRegion[$reg['sys_id']];
        if ($row && stripos($row['u_summary'], 'not activated') !== false) {
            $notActivated[] = $reg['u_code'] . ' (' . $row['number'] . ', ' . $row['sys_created_on'] . ')';
        }
    }
    ob_start(); ?>
    <h1>PAC Sync <small>latest ZIA versions per region, as reported by PAC Sync</small></h1>
    <?php if ($notActivated): ?>
      <div class="flash error"><strong>Not activated: <?= h(implode(', ', $notActivated)) ?>.</strong> The newest run of
        these regions saved its versions in ZIA but did not activate them: other pending changes exist. Review and activate in the ZIA portal.</div>
    <?php endif; ?>
    <table class="list">
      <thead><tr><th>Region</th><th>PAC (base name)</th><th>Deployed</th><th>Staged</th><th>Last run</th><th>Status</th><th>Summary</th></tr></thead>
      <tbody>
      <?php foreach ($regions as $reg):
          $rows = rec_query('u_pac_deployment', 'u_region=' . $reg['sys_id'] . '^ORDERBYDESCsys_created_on', 50)[0];
          $dep = $stg = $last = null;
          foreach ($rows as $row) {
              $last ??= $row;
              $dep ??= in_array($row['u_status'], ['deployed', 'rolled_back'], true) ? $row : null; // a rollback deploys that version
              $stg ??= $row['u_status'] === 'staged' ? $row : null;
          }
          $first = $last ? trim(strtok($last['u_summary'], "\n")) : ''; ?>
        <tr>
          <td><a href="/form/u_pac_region/<?= h($reg['sys_id']) ?>"><?= h($reg['u_code']) ?></a></td>
          <td><code><?= h($reg['u_zia_pac_name']) ?></code></td>
          <td><?= $dep ? 'v' . h($dep['u_zia_version']) . ' <span class="muted">' . h($dep['sys_created_on']) . '</span>' : '-' ?></td>
          <td><?= $stg && (!$dep || $stg['sys_created_on'] >= $dep['sys_created_on']) ? 'v' . h($stg['u_zia_version']) . ' <span class="muted">' . h($stg['sys_created_on']) . '</span>' : '-' ?></td>
          <td><?= $last ? '<a href="/form/u_pac_deployment/' . h($last['sys_id']) . '">' . h($last['number']) . '</a> <span class="muted">' . h($last['u_finished'] ?: $last['sys_created_on']) . '</span>' : '-' ?></td>
          <td><?= $last ? state_pill('u_pac_deployment', 'u_status', $last['u_status']) : '' ?></td>
          <td><code><?= h($first) ?></code></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php $failed = rec_query('u_pac_rule', 'u_deploy_status=failed^ORDERBYnumber')[0]; ?>
    <h2>Rules PAC Sync marked failed</h2>
    <?php if (!$failed): ?><div class="card empty">None.</div><?php else: ?>
    <table class="list compact">
      <thead><tr><th>Rule</th><th>State</th><th>Value</th><th>Message</th></tr></thead>
      <tbody>
      <?php foreach ($failed as $r): ?>
        <tr><td><a href="/form/u_pac_rule/<?= h($r['sys_id']) ?>"><?= h($r['number']) ?></a></td>
          <td><?= state_pill('u_pac_rule', 'u_state', $r['u_state']) ?></td>
          <td><code><?= h($r['u_value']) ?></code></td><td><?= nl2br(h($r['u_deploy_message'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <?php endif; ?>
    <?php render_page('PAC Sync', ob_get_clean(), 'pacsync');
}
