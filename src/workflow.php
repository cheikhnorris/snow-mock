<?php
// Catalog item "Request PAC exception" and its approval flow:
//   1. Owner group manager   2. Network security (PAC Approvers)   3. CAB (global rules only)

function can_act_on_approval(string $userName, array $approval): bool
{
    if (is_admin($userName)) {
        return true;
    }
    if ($approval['approver'] !== '' && $approval['approver'] === user_sys_id($userName)) {
        return true;
    }
    return $approval['u_approval_group'] !== '' && in_array($approval['u_approval_group'], user_groups($userName), true);
}

function group_id(string $name): string
{
    return rec_first('sys_user_group', 'name=' . $name)['sys_id'] ?? '';
}

/** Rules a request can target for modify / extend / retire. */
function targetable_rules(): array
{
    return rec_query('u_pac_rule', 'u_stateINapproved,deployed^ORDERBYnumber')[0];
}

/**
 * Submit the catalog item. $form holds the catalog variables. Returns the RITM record.
 * Throws BusinessRuleException with user-facing messages.
 */
function submit_pac_request(array $form, string $user): array
{
    $type = $form['request_type'] ?? '';
    if (!in_array($type, ['new', 'modify', 'extend', 'retire'], true)) {
        throw new BusinessRuleException('Choose a request type');
    }
    if (trim($form['u_justification'] ?? '') === '') {
        throw new BusinessRuleException('Business justification is mandatory');
    }
    $me = user_sys_id($user);

    return in_transaction(function () use ($form, $type, $user, $me) {
        $target = null;
        if ($type !== 'new') {
            $target = rec_get('u_pac_rule', $form['target_rule'] ?? '');
            if (!$target || !in_array($target['u_state'], ['approved', 'deployed'], true)) {
                throw new BusinessRuleException('Select an approved or deployed rule to ' . $type);
            }
            [$open] = rec_query('sc_req_item', "u_target_rule={$target['sys_id']}^stateINopen,work_in_progress");
            if ($open) {
                throw new BusinessRuleException("{$target['number']} already has an open request: {$open[0]['number']}");
            }
        }

        $rule = null;
        if ($type === 'retire') {
            $desc = "Retire {$target['number']}: {$target['u_action']} {$target['u_value']}";
            $scope = $target['u_scope'];
        } else {
            $fields = ['u_action', 'u_match_type', 'u_value', 'u_proxy_profile', 'u_scope', 'u_regions', 'u_priority',
                'u_valid_from', 'u_valid_to', 'u_application', 'u_owner_group', 'u_change_request'];
            $data = [];
            foreach ($fields as $f) {
                $data[$f] = $form[$f] ?? '';
            }
            if (is_array($form['u_regions'] ?? null)) {
                $data['u_regions'] = implode(',', $form['u_regions']);
            }
            if ($type === 'extend') { // only the end date changes
                $data = array_intersect_key($target, array_flip($fields));
                $data['u_valid_to'] = $form['u_valid_to'] ?? '';
                if ($data['u_valid_to'] === '') {
                    throw new BusinessRuleException('Enter the new valid-to date');
                }
            }
            $data += ['u_justification' => $form['u_justification'], 'u_requested_for' => $me,
                'u_state' => 'pending_approval', 'u_replaces' => $target['sys_id'] ?? ''];
            $data['u_justification'] = $form['u_justification'];
            $rule = rec_insert('u_pac_rule', $data, 'system');
            $verb = $type === 'new' ? 'New' : ucfirst($type) . " {$target['number']}:";
            $desc = "$verb {$rule['u_action']} {$rule['u_value']}";
            $scope = $rule['u_scope'];
        }

        $ritm = rec_insert('sc_req_item', [
            'short_description' => $desc,
            'u_request_type' => $type,
            'requested_for' => $me,
            'opened_by' => $me,
            'approval' => 'requested',
            'state' => 'open',
            'u_pac_rule' => $rule['sys_id'] ?? '',
            'u_target_rule' => $target['sys_id'] ?? '',
            'variables' => json_encode($form, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
        ], $user);

        $ownerGroup = rec_get('sys_user_group', ($rule ?? $target)['u_owner_group']);
        $steps = [
            ['u_step' => 'Owner group manager', 'approver' => $ownerGroup['manager'] ?? '', 'u_approval_group' => $ownerGroup['manager'] ? '' : ($ownerGroup['sys_id'] ?? '')],
            ['u_step' => 'Network security', 'u_approval_group' => group_id('PAC Approvers')],
        ];
        if ($scope === 'global' && $type !== 'retire') {
            $steps[] = ['u_step' => 'CAB', 'u_approval_group' => group_id('CAB')];
        }
        foreach ($steps as $i => $s) {
            rec_insert('sysapproval_approver', $s + [
                'sysapproval' => $ritm['sys_id'],
                'order' => (string)(($i + 1) * 100),
                'state' => $i === 0 ? 'requested' : 'not_yet_requested',
            ], 'system');
        }
        return $ritm;
    });
}

/** Called after an approval changes state: advance to the next step or finish the request. */
function approval_state_changed(array $approval): void
{
    $ritmId = $approval['sysapproval'];
    if ($approval['state'] === 'rejected') {
        foreach (rec_query('sysapproval_approver', "sysapproval=$ritmId^stateINrequested,not_yet_requested")[0] as $a) {
            rec_update('sysapproval_approver', $a['sys_id'], ['state' => 'not_required'], 'system');
        }
        finish_request($ritmId, false);
        return;
    }
    if ($approval['state'] !== 'approved') {
        return;
    }
    $next = rec_first('sysapproval_approver', "sysapproval=$ritmId^state=not_yet_requested^ORDERBYorder");
    if ($next) {
        rec_update('sysapproval_approver', $next['sys_id'], ['state' => 'requested'], 'system');
    } elseif (!rec_first('sysapproval_approver', "sysapproval=$ritmId^state=requested")) {
        finish_request($ritmId, true);
    }
}

function finish_request(string $ritmId, bool $approved): void
{
    $ritm = rec_get('sc_req_item', $ritmId);
    $type = $ritm['u_request_type'];
    if (!$approved) {
        if ($ritm['u_pac_rule']) {
            rec_update('u_pac_rule', $ritm['u_pac_rule'], ['u_state' => 'rejected'], 'system');
        }
        rec_update('sc_req_item', $ritmId, ['approval' => 'rejected', 'state' => 'closed_incomplete'], 'system');
        return;
    }
    rec_update('sc_req_item', $ritmId, ['approval' => 'approved', 'state' => 'work_in_progress'], 'system');
    if ($type === 'retire') {
        rec_update('u_pac_rule', $ritm['u_target_rule'], ['u_state' => 'retire_requested'], 'system');
        return;
    }
    rec_update('u_pac_rule', $ritm['u_pac_rule'], ['u_state' => 'approved'], 'system');
    if (in_array($type, ['modify', 'extend'], true) && $ritm['u_target_rule']) {
        // PAC Sync swaps both in the same run: the new rule goes in, the old one comes out.
        rec_update('u_pac_rule', $ritm['u_target_rule'], ['u_state' => 'retire_requested'], 'system');
    }
}

/** A request is complete when PAC Sync has deployed (or removed) the rule it asked for. */
function close_requests_for_rule(array $rule): void
{
    $id = $rule['sys_id'];
    $done = [];
    if ($rule['u_state'] === 'deployed') {
        $done = rec_query('sc_req_item', "u_pac_rule=$id^state=work_in_progress")[0];
    } elseif ($rule['u_state'] === 'retired') {
        $done = rec_query('sc_req_item', "u_target_rule=$id^u_request_type=retire^state=work_in_progress")[0];
    }
    foreach ($done as $ritm) {
        rec_update('sc_req_item', $ritm['sys_id'], ['state' => 'closed_complete'], 'system');
    }
}
