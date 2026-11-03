<?php
// Self-test: URL filtering (u_url_category / u_url_rule): entry and name validation, integration account limits,
// and the request workflow. Run: php tests/url_rule_test.php (exit 0 = pass).
// The record checks run inside a transaction that is rolled back: they write nothing.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
}
/** Error message of a business-rule failure, '' when the call succeeds. */
function br_error(callable $fn): string
{
    try {
        $fn();
        return '';
    } catch (BusinessRuleException $e) {
        return $e->getMessage();
    }
}

foreach (['a', 'a-b', 'partner-sites', str_repeat('a', 26), '0x'] as $good) {
    check(zia_validate_object_name($good) === null, 'name accepted: ' . $good);
}
foreach (['', '-a', 'A', 'a_b', 'a b', str_repeat('a', 27), "a\n", 'é'] as $bad) {
    check(zia_validate_object_name($bad) !== null, 'name rejected: ' . json_encode($bad));
}
foreach (['example.com', '.example.com', 'tools.example.net/upload', 'a.b.example.com/p/a-t_h.x~y%20', 'example.com/', 'x.ma'] as $good) {
    check(url_validate_entry($good) === null, 'entry accepted: ' . $good);
}
foreach (['', 'com', '.com', 'co.uk', 'localhost', '*.example.com', 'example.com:8080', 'https://example.com', 'example.com/a?b=1',
             'example.com/#x', 'example.com/A', 'example.com/a b', "example.com/a\n", '/path', '..example.com', 'a..com',
             '-a.example.com', 'exämple.com', 'example.com/é', str_repeat('a', 250) . '.com'] as $bad) {
    check(url_validate_entry($bad) !== null, 'entry rejected: ' . json_encode($bad));
}

db()->beginTransaction();
try {
    $cat = rec_insert('u_url_category', ['u_name' => 'urltest-cat', 'u_action' => 'block'], 'admin');
    check(br_error(fn() => rec_insert('u_url_category', ['u_name' => 'urltest-cat', 'u_action' => 'allow'], 'admin')) !== '',
        'duplicate category name rejected');
    check(br_error(fn() => rec_insert('u_url_category', ['u_name' => 'Bad Name', 'u_action' => 'allow'], 'admin')) !== '',
        'bad category name rejected');
    check(br_error(fn() => rec_insert('u_url_category', ['u_name' => 'urltest-x', 'u_action' => 'drop'], 'admin')) !== '',
        'bad category action rejected');

    $new = fn(string $value, array $extra = []) => rec_insert('u_url_rule', ['u_category' => $cat['sys_id'], 'u_value' => $value,
        'u_state' => 'pending_approval', 'u_owner_group' => 'x', 'u_justification' => 'x'] + $extra, 'system');
    check(br_error(fn() => $new('https://bad.example.com')) !== '', 'rule with scheme rejected');
    check(br_error(fn() => rec_insert('u_url_rule', ['u_value' => 'a.example.com', 'u_state' => 'pending_approval'], 'system'))
        !== '', 'rule without category/owner/justification rejected');
    $rule = $new('  Tools.Example.NET/Upload ');
    check($rule['u_value'] === 'tools.example.net/upload', 'value trimmed and lower-cased (V1)');
    check(str_starts_with($rule['number'], 'URLR'), 'number prefix URLR');
    check(str_contains(br_error(fn() => $new('tools.example.net/upload')), 'Duplicate of'), 'same category: duplicate');
    $other = rec_insert('u_url_category', ['u_name' => 'urltest-other', 'u_action' => 'allow'], 'admin');
    check(str_contains(br_error(fn() => rec_insert('u_url_rule', ['u_category' => $other['sys_id'], 'u_value' => 'tools.example.net/upload',
        'u_state' => 'pending_approval', 'u_owner_group' => 'x', 'u_justification' => 'x'], 'system')), 'Conflicts with'),
        'other category: conflict');
    check(br_error(fn() => $new('past.example.com', ['u_valid_to' => '2000-01-01 00:00:00'])) !== '', 'valid-to in the past rejected');

    // Integration account: deploy fields and allowed u_state transitions only.
    rec_update('u_url_rule', $rule['sys_id'], ['u_state' => 'approved'], 'system');
    check(br_error(fn() => rec_update('u_url_rule', $rule['sys_id'], ['u_value' => 'other.example.com'], 'svc_pac_sync')) !== '',
        'integration cannot change the URL');
    check(br_error(fn() => rec_update('u_url_rule', $rule['sys_id'], ['u_state' => 'rejected'], 'svc_pac_sync')) !== '',
        'integration cannot move approved to rejected');
    check(br_error(fn() => rec_insert('u_url_rule', ['u_category' => $cat['sys_id'], 'u_value' => 'n.example.com'], 'svc_pac_sync')) !== '',
        'integration cannot create rules');
    check(br_error(fn() => rec_update('u_url_rule', $rule['sys_id'], ['u_deploy_status' => 'deployed', 'u_deploy_version' => 'x:v1',
        'u_state' => 'deployed'], 'svc_pac_sync')) === '', 'integration may set deploy fields and approved to deployed');
    check(api_acl('write', 'u_url_rule', 'svc_pac_sync') && !api_acl('write', 'u_url_category', 'svc_pac_sync')
        && api_acl('read', 'u_url_category', 'svc_pac_sync') && api_acl('create', 'u_pac_deployment', 'svc_pac_sync'), 'integration ACLs');
    check(br_error(fn() => rec_update('u_url_rule', $rule['sys_id'], ['u_justification' => 'y'], 'jdoe')) !== '',
        'plain user cannot edit a rule directly');

    // Request workflow: submit, both approvals, rule approved; PAC Sync deploys, request closes.
    $grp = rec_insert('sys_user_group', ['name' => 'urltest-grp', 'manager' => user_sys_id('mmanager')], 'system');
    $form = ['request_type' => 'new', 'u_category' => $cat['sys_id'], 'u_value' => 'Docs.Example.com', 'u_owner_group' => $grp['sys_id'],
        'u_justification' => 'test'];
    check(br_error(fn() => submit_url_request(['u_value' => 'https://x.example.com'] + $form, 'jdoe')) !== '',
        'bad entry rejected on submit');
    $ritm = submit_url_request($form, 'jdoe');
    $url = rec_get('u_url_rule', $ritm['u_url_rule']);
    check($url['u_state'] === 'pending_approval' && $url['u_value'] === 'docs.example.com', 'submit creates a pending rule');
    $steps = rec_query('sysapproval_approver', "sysapproval={$ritm['sys_id']}^ORDERBYorder")[0];
    check(count($steps) === 2, 'two approval steps');
    rec_update('sysapproval_approver', $steps[0]['sys_id'], ['state' => 'approved'], 'mmanager');
    check(rec_get('u_url_rule', $url['sys_id'])['u_state'] === 'pending_approval', 'still pending after step 1');
    rec_update('sysapproval_approver', $steps[1]['sys_id'], ['state' => 'approved'], 'nsec');
    check(rec_get('u_url_rule', $url['sys_id'])['u_state'] === 'approved', 'approved after step 2');
    check(rec_get('sc_req_item', $ritm['sys_id'])['state'] === 'work_in_progress', 'request in progress');
    rec_update('u_url_rule', $url['sys_id'], ['u_deploy_status' => 'deployed', 'u_state' => 'deployed'], 'svc_pac_sync');
    check(rec_get('sc_req_item', $ritm['sys_id'])['state'] === 'closed_complete', 'deployed closes the request');

    // Retire request.
    $ret = submit_url_request(['request_type' => 'retire', 'target_rule' => $url['sys_id'], 'u_justification' => 'gone'], 'jdoe');
    foreach (rec_query('sysapproval_approver', "sysapproval={$ret['sys_id']}^ORDERBYorder")[0] as $i => $s) {
        rec_update('sysapproval_approver', $s['sys_id'], ['state' => 'approved'], $i === 0 ? 'mmanager' : 'nsec');
    }
    check(rec_get('u_url_rule', $url['sys_id'])['u_state'] === 'retire_requested', 'approved retire request sets retire_requested');
    rec_update('u_url_rule', $url['sys_id'], ['u_state' => 'retired'], 'svc_pac_sync');
    check(rec_get('sc_req_item', $ret['sys_id'])['state'] === 'closed_complete', 'retired closes the retire request');
} finally {
    db()->rollBack();
}
exit($failures ? 1 : 0);
