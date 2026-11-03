<?php
// Platform side: list view, form view, related lists, activity, routing.

const PAGE_SIZE = 20;

function can_edit_table(string $user, string $table): bool
{
    if (!empty(table_def($table)['readonly'])) {
        return false;
    }
    if (is_admin($user)) {
        return true;
    }
    return is_pac_admin($user) && in_array($table, ['u_pac_rule', 'u_pac_proxy_profile', 'u_url_category', 'u_fwd_profile', 'change_request'], true);
}

function page_list(string $table): void
{
    $def = table_def($table);
    $user = current_user();
    $query = trim($_GET['sysparm_query'] ?? '');
    $page = max(1, (int)($_GET['page'] ?? 1));
    [$rows, $total] = rec_query($table, $query ?: 'ORDERBYDESCsys_updated_on', PAGE_SIZE, ($page - 1) * PAGE_SIZE);
    $pages = max(1, (int)ceil($total / PAGE_SIZE));
    $base = fn(array $p) => '/list/' . $table . '?' . http_build_query(array_filter($p + ['sysparm_query' => $query, 'page' => $page]));
    $stripOrder = preg_replace('/\^?ORDERBY(DESC)?[a-z0-9_]+/', '', $query);
    ob_start(); ?>
    <div class="page-head">
      <h1><?= h($def['label']) ?>s <small><code><?= h($table) ?></code> · <?= $total ?> records</small></h1>
      <?php if (can_edit_table($user, $table)): ?><a class="btn primary" href="/form/<?= h($table) ?>/new">New</a><?php endif; ?>
    </div>
    <form class="filter" method="get" action="/list/<?= h($table) ?>">
      <input name="sysparm_query" value="<?= h($query) ?>" placeholder="Encoded query, e.g. u_state=deployed^u_action=proxy^ORDERBYu_priority">
      <button class="btn">Run</button>
      <?php if ($query): ?><a class="btn" href="/list/<?= h($table) ?>">Clear</a><?php endif; ?>
    </form>
    <table class="list">
      <thead><tr>
        <?php foreach ($def['list'] as $f):
            $asc = ltrim("$stripOrder^ORDERBY$f", '^');
            $desc = ltrim("$stripOrder^ORDERBYDESC$f", '^');
            $sortedAsc = str_contains($query, "ORDERBY$f") && !str_contains($query, "ORDERBYDESC$f"); ?>
          <th><a href="<?= h($base(['sysparm_query' => $sortedAsc ? $desc : $asc, 'page' => 1])) ?>"><?= h(field_def($table, $f)['label']) ?></a></th>
        <?php endforeach; ?>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <?php foreach ($def['list'] as $i => $f): ?>
            <td><?= $i === 0 ? '<a href="/form/' . h($table) . '/' . h($r['sys_id']) . '">' . h(display_value($table, $f, $r[$f]) ?: '(empty)') . '</a>' : ref_link($table, $f, $r[$f]) ?></td>
          <?php endforeach; ?>
        </tr>
      <?php endforeach; ?>
      <?php if (!$rows): ?><tr><td colspan="<?= count($def['list']) ?>" class="empty">No records match.</td></tr><?php endif; ?>
      </tbody>
    </table>
    <div class="pager">
      <?php if ($page > 1): ?><a class="btn" href="<?= h($base(['page' => $page - 1])) ?>">‹ Previous</a><?php endif; ?>
      <span>Page <?= $page ?> of <?= $pages ?></span>
      <?php if ($page < $pages): ?><a class="btn" href="<?= h($base(['page' => $page + 1])) ?>">Next ›</a><?php endif; ?>
    </div>
    <?php render_page($def['label'] . 's', ob_get_clean(), 'backend');
}

function form_input(string $table, string $f, string $value, bool $editable): string
{
    $fd = field_def($table, $f);
    $name = h($f);
    if (!$editable) {
        if ($fd['type'] === 'text') {
            return '<div class="ro pre">' . h($value) . '</div>';
        }
        return '<div class="ro">' . (ref_link($table, $f, $value) ?: '<span class="muted">empty</span>') . '</div>';
    }
    switch ($fd['type']) {
        case 'text':
            return "<textarea name=\"$name\" rows=\"3\">" . h($value) . '</textarea>';
        case 'integer':
            return "<input type=\"number\" name=\"$name\" value=\"" . h($value) . '">';
        case 'boolean':
            return "<input type=\"hidden\" name=\"$name\" value=\"false\"><label class=\"inline\"><input type=\"checkbox\" name=\"$name\" value=\"true\" "
                . ($value === 'true' ? 'checked' : '') . '> Yes</label>';
        case 'datetime':
            return "<input type=\"datetime-local\" name=\"$name\" value=\"" . h($value ? substr(str_replace(' ', 'T', $value), 0, 16) : '') . '">';
        case 'password':
            return "<input type=\"password\" name=\"$name\" value=\"\" placeholder=\"Leave empty to keep\" autocomplete=\"new-password\">";
        case 'choice':
            $out = "<select name=\"$name\"><option value=\"\">-- None --</option>";
            foreach ($fd['choices'] as $k => $label) {
                $out .= '<option value="' . h((string)$k) . '"' . ((string)$k === $value ? ' selected' : '') . '>' . h($label) . '</option>';
            }
            return $out . '</select>';
        case 'reference':
            $refDef = table_def($fd['ref']);
            $out = "<select name=\"$name\"><option value=\"\">-- None --</option>";
            foreach (rec_query($fd['ref'], 'ORDERBY' . $refDef['display'])[0] as $r) {
                $out .= '<option value="' . h($r['sys_id']) . '"' . ($r['sys_id'] === $value ? ' selected' : '') . '>' . h($r[$refDef['display']]) . '</option>';
            }
            return $out . '</select>' . ($value ? ' <a class="ref-open" href="/form/' . h($fd['ref']) . '/' . h($value) . '" title="Open record">↗</a>' : '');
        case 'glide_list':
            $selected = array_filter(explode(',', $value));
            $out = "<input type=\"hidden\" name=\"{$name}[]\" value=\"\"><div class=\"check-row\">";
            foreach (rec_query($fd['ref'], 'ORDERBY' . table_def($fd['ref'])['display'])[0] as $r) {
                $out .= '<label><input type="checkbox" name="' . $name . '[]" value="' . h($r['sys_id']) . '"'
                    . (in_array($r['sys_id'], $selected, true) ? ' checked' : '') . '> ' . h($r[table_def($fd['ref'])['display']]) . '</label>';
            }
            return $out . '</div>';
        default:
            return "<input name=\"$name\" value=\"" . h($value) . '">';
    }
}

function page_form(string $table, string $id, string $method): void
{
    $def = table_def($table);
    $user = current_user();
    $isNew = $id === 'new';
    $rec = $isNew ? null : (rec_get($table, $id) ?? throw new NotFoundException("No $table record $id"));
    $editable = can_edit_table($user, $table);
    $error = null;

    if ($method === 'POST' && ($_POST['action'] ?? '') === 'delete' && is_admin($user) && !$isNew) {
        rec_delete($table, $id, $user);
        flash('success', 'Record deleted.');
        redirect('/list/' . $table);
        return;
    }
    if ($method === 'POST' && $editable) {
        $data = [];
        foreach ($def['fields'] as $f => $fd) {
            if (!empty($fd['readonly']) || !array_key_exists($f, $_POST)) {
                continue;
            }
            $v = $_POST[$f];
            $data[$f] = is_array($v) ? implode(',', array_filter($v, 'strlen')) : $v;
            if ($fd['type'] === 'password' && $data[$f] === '') {
                unset($data[$f]);
            }
        }
        try {
            $saved = in_transaction(fn() => $isNew ? rec_insert($table, $data, $user) : rec_update($table, $id, $data, $user));
            flash('success', $def['label'] . ' ' . ($saved[$def['display']] ?? '') . ($isNew ? ' created.' : ' updated.'));
            redirect('/form/' . $table . '/' . $saved['sys_id']);
            return;
        } catch (BusinessRuleException $e) {
            $error = $e->getMessage();
            $rec = array_merge($rec ?? array_fill_keys(columns($table), ''), clean_input($table, $data));
        }
    }
    $rec ??= array_fill_keys(columns($table), '');
    if ($isNew) {
        foreach ($def['fields'] as $f => $fd) {
            if (isset($fd['default']) && $rec[$f] === '') {
                $rec[$f] = $fd['default'];
            }
        }
    }
    $title = $isNew ? "New {$def['label']}" : $def['label'] . ' ' . ($rec[$def['display']] ?? '');
    ob_start(); ?>
    <div class="breadcrumb"><a href="/list/<?= h($table) ?>"><?= h($def['label']) ?>s</a> › <?= h($isNew ? 'New' : ($rec[$def['display']] ?: $id)) ?></div>
    <form method="post" class="card record">
      <div class="page-head">
        <h1><?= h($title) ?></h1>
        <?php if ($table === 'sc_req_item' && !$isNew): ?><a class="btn" href="/ritm/<?= h($id) ?>">Portal view</a><?php endif; ?>
        <?php if ($editable): ?><button class="btn primary" type="submit"><?= $isNew ? 'Submit' : 'Update' ?></button><?php endif; ?>
        <?php if (!$isNew && is_admin($user)): ?><button class="btn danger" name="action" value="delete" onclick="return confirm('Delete this record?')">Delete</button><?php endif; ?>
      </div>
      <?php if ($table === 'u_pac_rule' && !$editable): ?>
        <div class="flash info">PAC rules are changed through the catalog item. <a href="/catalog/pac?type=modify&amp;rule=<?= h($id) ?>">Request a change</a> ·
          <a href="/catalog/pac?type=retire&amp;rule=<?= h($id) ?>">Request retirement</a></div>
      <?php endif; ?>
      <?php if ($error): ?><div class="flash error"><?= nl2br(h($error)) ?></div><?php endif; ?>
      <div class="form-grid">
        <?php foreach ($def['fields'] as $f => $fd):
            $fieldEditable = $editable && empty($fd['readonly']); ?>
          <div class="field <?= in_array($fd['type'], ['text', 'glide_list'], true) ? 'wide' : '' ?>">
            <label><?= h($fd['label']) ?><?= !empty($fd['mandatory']) ? ' <span class="req">*</span>' : '' ?>
              <?php if (!empty($fd['integration'])): ?><span class="tag">PAC Sync</span><?php endif; ?></label>
            <?= form_input($table, $f, (string)($rec[$f] ?? ''), $fieldEditable) ?>
          </div>
        <?php endforeach; ?>
      </div>
      <?php if (!$isNew): ?>
        <div class="sysinfo">sys_id <code><?= h($id) ?></code> · created <?= h($rec['sys_created_on']) ?> by <?= h($rec['sys_created_by']) ?>
          · updated <?= h($rec['sys_updated_on']) ?> by <?= h($rec['sys_updated_by']) ?> · <?= h($rec['sys_mod_count']) ?> updates</div>
      <?php endif; ?>
    </form>
    <?php if (!$isNew) {
        related_lists($table, $id);
        activity_stream($table, $id);
    }
    render_page($title, ob_get_clean(), 'backend');
}

function related_list(string $title, string $table, string $query): void
{
    [$rows] = rec_query($table, $query);
    $cols = table_def($table)['list']; ?>
    <section class="card">
      <h2><?= h($title) ?> <small>(<?= count($rows) ?>)</small></h2>
      <table class="list compact">
        <thead><tr><?php foreach ($cols as $f): ?><th><?= h(field_def($table, $f)['label']) ?></th><?php endforeach; ?></tr></thead>
        <tbody>
        <?php foreach ($rows as $r): ?>
          <tr><?php foreach ($cols as $i => $f): ?>
            <td><?= $i === 0 ? '<a href="/form/' . h($table) . '/' . h($r['sys_id']) . '">' . h(display_value($table, $f, $r[$f]) ?: '(open)') . '</a>' : ref_link($table, $f, $r[$f]) ?></td>
          <?php endforeach; ?></tr>
        <?php endforeach; ?>
        <?php if (!$rows): ?><tr><td colspan="<?= count($cols) ?>" class="empty">None</td></tr><?php endif; ?>
        </tbody>
      </table>
    </section>
    <?php
}

function related_lists(string $table, string $id): void
{
    match ($table) {
        'u_pac_rule' => related_list('Requests', 'sc_req_item', "u_pac_rule=$id^ORu_target_rule=$id^ORDERBYDESCnumber"),
        'u_url_category' => related_list('URL rules', 'u_url_rule', "u_category=$id"),
        'u_url_rule' => related_list('Requests', 'sc_req_item', "u_url_rule=$id^ORu_target_url_rule=$id^ORDERBYDESCnumber"),
        'u_fwd_profile' => related_list('Forwarding rules', 'u_fwd_rule', "u_profile=$id"),
        'u_fwd_rule' => related_list('Requests', 'sc_req_item', "u_fwd_rule=$id^ORu_target_fwd_rule=$id^ORDERBYDESCnumber"),
        'sc_req_item' => related_list('Approvals', 'sysapproval_approver', "sysapproval=$id^ORDERBYorder"),
        'u_pac_region' => related_list('Deployments', 'u_pac_deployment', "u_region=$id^ORDERBYDESCsys_created_on"),
        'sys_user_group' => related_list('Members', 'sys_user_grmember', "group=$id"),
        'sys_user' => related_list('Groups', 'sys_user_grmember', "user=$id"),
        'change_request' => related_list('PAC rules', 'u_pac_rule', "u_change_request=$id"),
        default => null,
    };
}

function activity_stream(string $table, string $id): void
{
    [$rows] = rec_query('sys_audit', "documentkey=$id^ORDERBYDESCsys_created_on", 50); ?>
    <section class="card">
      <h2>Activity</h2>
      <table class="list compact">
        <thead><tr><th>When</th><th>Who</th><th>Field</th><th>Old</th><th>New</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $a): ?>
          <tr><td><?= h($a['sys_created_on']) ?></td><td><?= h($a['user']) ?></td>
            <td><?= h($a['fieldname'] === 'record' ? 'Record' : field_def($table, $a['fieldname'])['label']) ?></td>
            <td><?= h($a['fieldname'] === 'record' ? '' : display_value($table, $a['fieldname'], $a['oldvalue'])) ?></td>
            <td><?= h($a['fieldname'] === 'record' ? $a['newvalue'] : display_value($table, $a['fieldname'], $a['newvalue'])) ?></td></tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php if (is_admin(current_user())): ?>
        <form method="post" action="/admin/reset" onsubmit="return confirm('Delete all data and reload the demo data?')" class="reset">
          <button class="btn danger small">Reset demo data</button></form>
      <?php endif; ?>
    </section>
    <?php
}

function ui_route(string $path, string $method): void
{
    try {
        $seg = array_values(array_filter(explode('/', $path), 'strlen'));
        $r = $seg[0] ?? '';
        if ($r === '') {
            page_home();
        } elseif ($r === 'catalog' && ($seg[1] ?? '') === 'pac') {
            page_catalog_pac($method);
        } elseif ($r === 'catalog' && ($seg[1] ?? '') === 'url') {
            page_catalog_url($method);
        } elseif ($r === 'catalog' && ($seg[1] ?? '') === 'fwd') {
            page_catalog_url($method, 'u_fwd_rule');
        } elseif ($r === 'catalog') {
            page_catalog_index();
        } elseif ($r === 'requests') {
            page_requests();
        } elseif ($r === 'ritm' && isset($seg[1])) {
            page_ritm($seg[1]);
        } elseif ($r === 'approvals') {
            page_approvals($method, $seg[1] ?? null);
        } elseif ($r === 'list' && isset($seg[1])) {
            page_list($seg[1]);
        } elseif ($r === 'form' && isset($seg[1], $seg[2])) {
            page_form($seg[1], $seg[2], $method);
        } elseif ($r === 'pacsync') {
            page_pacsync();
        } elseif ($r === 'integration') {
            page_integration();
        } elseif ($r === 'impersonate' && $method === 'POST') {
            $u = user_by_name($_POST['user'] ?? '');
            if ($u && $u['web_service_access_only'] !== 'true') {
                $_SESSION['user'] = $u['user_name'];
                flash('info', "You are now acting as {$u['name']}.");
            }
            $back = $_POST['back'] ?? '/';
            redirect(str_starts_with($back, '/') && !str_starts_with($back, '//') ? $back : '/');
        } elseif ($r === 'admin' && ($seg[1] ?? '') === 'reset' && $method === 'POST' && is_admin(current_user())) {
            reset_database();
            session_destroy();
            redirect('/');
        } else {
            throw new NotFoundException('Page not found');
        }
    } catch (NotFoundException $e) {
        http_response_code(404);
        render_page('Not found', '<div class="card empty"><h1>Not found</h1><p>' . h($e->getMessage()) . '</p><a href="/">Home</a></div>');
    }
}
