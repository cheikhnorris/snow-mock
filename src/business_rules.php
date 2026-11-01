<?php
// Business rules: run before/after every insert and update, from the UI and the Table API alike.

const DEPLOY_FIELDS = ['u_deploy_status', 'u_deploy_version', 'u_deploy_message', 'u_deployed_on'];
const CONTENT_FIELDS = ['u_action', 'u_match_type', 'u_value', 'u_proxy_profile', 'u_scope', 'u_regions', 'u_priority',
    'u_valid_from', 'u_valid_to', 'u_change_request', 'u_owner_group', 'u_justification'];

// Allowed u_state transitions for people (PAC admins and the workflow bypass this through "system").
const RULE_TRANSITIONS = [
    'draft' => ['pending_approval'],
    'pending_approval' => ['approved', 'rejected'],
    'approved' => ['deployed', 'retire_requested', 'retired'],
    'deployed' => ['retire_requested', 'retired'],
    'retire_requested' => ['retired', 'deployed'],
    'rejected' => ['draft'],
    'retired' => [],
];
// What PAC Sync (role u_pac_sync_integration) may do with u_state.
const INTEGRATION_TRANSITIONS = [
    'approved' => ['deployed', 'retired'],
    'deployed' => ['retired'],
    'retire_requested' => ['retired'],
];

function br_before(string $table, string $op, array &$new, ?array $old, string $user): void
{
    match ($table) {
        'u_pac_rule' => br_pac_rule_before($op, $new, $old, $user),
        'u_pac_proxy_profile' => validate_proxy_profile($new),
        'sysapproval_approver' => br_approval_before($op, $new, $old, $user),
        'sys_user', 'sys_user_group', 'sys_user_grmember', 'u_pac_region', 'change_request'
            => br_admin_only($table, $user),
        'sys_audit' => throw new BusinessRuleException('sys_audit is read-only'),
        default => null,
    };
}

function br_after(string $table, string $op, array $new, ?array $old, string $user): void
{
    if ($table === 'u_pac_rule' && $old && $new['u_state'] !== $old['u_state']) {
        close_requests_for_rule($new);
    }
    if ($table === 'sysapproval_approver' && $old && $new['state'] !== $old['state']) {
        approval_state_changed($new);
    }
}

function br_admin_only(string $table, string $user): void
{
    if ($user !== 'system' && !is_admin($user) && !($table === 'change_request' && is_pac_admin($user))) {
        throw new BusinessRuleException("Only administrators can change $table");
    }
}

function br_pac_rule_before(string $op, array &$r, ?array $old, string $user): void
{
    $system = $user === 'system';
    $integration = is_integration($user);
    $pacAdmin = is_pac_admin($user);
    $changed = fn(string $f) => $old !== null && ($old[$f] ?? '') !== ($r[$f] ?? '');

    // V12: deployment fields belong to PAC Sync
    if (!$system && !$integration && !$pacAdmin) {
        foreach (DEPLOY_FIELDS as $f) {
            if ($op === 'insert') {
                $r[$f] = $f === 'u_deploy_status' ? 'pending' : '';
            } elseif ($changed($f)) {
                throw new BusinessRuleException("Field $f can only be set by PAC Sync");
            }
        }
    }

    if ($integration) {
        if ($op === 'insert') {
            throw new BusinessRuleException('The integration account cannot create PAC rules');
        }
        foreach (array_keys(table_def('u_pac_rule')['fields']) as $f) {
            if ($changed($f) && !in_array($f, [...DEPLOY_FIELDS, 'u_state'], true)) {
                throw new BusinessRuleException("The integration account cannot change $f");
            }
        }
        if ($changed('u_state') && !in_array($r['u_state'], INTEGRATION_TRANSITIONS[$old['u_state']] ?? [], true)) {
            throw new BusinessRuleException("The integration account cannot move a rule from {$old['u_state']} to {$r['u_state']}");
        }
        return; // no content change possible, nothing else to validate
    }

    if (!$system && !$pacAdmin) {
        if ($op === 'update') {
            throw new BusinessRuleException('PAC rules are changed through the catalog item "Request PAC exception"');
        }
    }
    if (!$system && $changed('u_state') && !in_array($r['u_state'], RULE_TRANSITIONS[$old['u_state']] ?? [], true)) {
        throw new BusinessRuleException("A rule cannot move from {$old['u_state']} to {$r['u_state']}");
    }

    $contentChanged = $op === 'insert';
    foreach (CONTENT_FIELDS as $f) {
        $contentChanged = $contentChanged || $changed($f);
    }
    $reactivated = $changed('u_state') && in_array($old['u_state'], ['draft', 'rejected'], true);
    if (($contentChanged || $reactivated) && !in_array($r['u_state'], ['retired', 'rejected'], true)) {
        validate_pac_rule($r, $old);
    }
    if ($r['short_description'] === '' && $r['u_value'] !== '') {
        $r['short_description'] = strtoupper($r['u_action']) . ' ' . $r['u_value'];
    }
}

function br_approval_before(string $op, array &$a, ?array $old, string $user): void
{
    if ($op === 'insert' || $user === 'system') {
        return;
    }
    foreach (['sysapproval', 'approver', 'u_approval_group', 'u_approved_by'] as $f) {
        if ($a[$f] !== $old[$f]) {
            throw new BusinessRuleException("$f cannot be changed after the approval is created");
        }
    }
    if ($old['state'] !== $a['state'] && $a['state'] === 'approved') {
        $a['u_approved_by'] = $user;
    }
    if ($old['state'] !== $a['state'] && $a['state'] === 'approved' && ($why = sod_violation($user, $a))) {
        throw new BusinessRuleException($why);
    }
    if (is_admin($user)) {
        return;
    }
    if ($old['state'] !== $a['state']) {
        if ($old['state'] !== 'requested' || !in_array($a['state'], ['approved', 'rejected'], true)) {
            throw new BusinessRuleException('Only a requested approval can be approved or rejected');
        }
        if (!can_act_on_approval($user, $a)) {
            throw new BusinessRuleException('You are not an approver for this step');
        }
        if ($a['state'] === 'rejected' && trim($a['comments']) === '') {
            throw new BusinessRuleException('A comment is required to reject');
        }
    }
}
