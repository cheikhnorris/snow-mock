<?php
// Self-test: traffic forwarding (u_fwd_profile / u_fwd_rule): gateway validation, profile and rule checks,
// integration account limits and the request workflow. Run: php tests/fwd_rule_test.php (exit 0 = pass).
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

foreach (['PARTNER-EGRESS-GW', 'a', 'gw with spaces', "~!#$%&'()*+,-./:;<=>?@[]^_`{|}", str_repeat('a', 128)] as $good) {
    check(fwd_validate_gateway($good) === null, 'gateway accepted: ' . json_encode($good));
}
foreach (['', str_repeat('a', 129), 'a"b', 'a\\b', "a\nb", "a\tb", 'gwé', "a\x7f"] as $bad) {
    check(fwd_validate_gateway($bad) !== null, 'gateway rejected: ' . json_encode($bad));
}

db()->beginTransaction();
try {
    $direct = rec_insert('u_fwd_profile', ['u_name' => 'fwdtest-direct', 'u_forward_method' => 'direct'], 'admin');
    $chain = rec_insert('u_fwd_profile', ['u_name' => 'fwdtest-chain', 'u_forward_method' => 'proxychain', 'u_proxy_gateway' => 'GW-1'], 'admin');
    $trimmed = rec_insert('u_fwd_profile', ['u_name' => 'fwdtest-trim', 'u_forward_method' => 'proxychain', 'u_proxy_gateway' => ' GW-2 '], 'admin');
    check($trimmed['u_proxy_gateway'] === 'GW-2', 'gateway is trimmed before it is stored');
    foreach ([
        'proxychain without gateway' => ['u_name' => 'fwdtest-a', 'u_forward_method' => 'proxychain'],
        'proxychain with bad gateway' => ['u_name' => 'fwdtest-a', 'u_forward_method' => 'proxychain', 'u_proxy_gateway' => 'a"b'],
        'direct with a gateway' => ['u_name' => 'fwdtest-a', 'u_forward_method' => 'direct', 'u_proxy_gateway' => 'GW-1'],
        'bad method' => ['u_name' => 'fwdtest-a', 'u_forward_method' => 'bypass'],
        'bad name' => ['u_name' => 'Bad Name', 'u_forward_method' => 'direct'],
        'duplicate name' => ['u_name' => 'fwdtest-direct', 'u_forward_method' => 'direct'],
    ] as $what => $data) {
        check(br_error(fn() => rec_insert('u_fwd_profile', $data, 'admin')) !== '', "profile rejected: $what");
    }

    $new = fn(string $mt, string $value, array $extra = []) => rec_insert('u_fwd_rule', ['u_profile' => $direct['sys_id'], 'u_match_type' => $mt,
        'u_value' => $value, 'u_state' => 'pending_approval', 'u_owner_group' => 'x', 'u_justification' => 'x'] + $extra, 'system');
    foreach ([['domain_suffix', 'example.com'], ['host_exact', '*.example.com'], ['host_exact', 'a.example.com:80'], ['ip_cidr', '203.0.113.1/24'],
                 ['ip_cidr', '10.0.0.0/7'], ['url_pattern', 'https://a.example.com/*']] as [$mt, $value]) {
        check(br_error(fn() => $new($mt, $value)) !== '', "rule rejected: $mt $value");
    }
    check(br_error(fn() => rec_insert('u_fwd_rule', ['u_match_type' => 'host_exact', 'u_value' => 'a.example.com', 'u_state' => 'pending_approval'],
        'system')) !== '', 'rule without profile/owner/justification rejected');
    $rule = $new('host_exact', '  Portal.Example.COM ');
    check($rule['u_value'] === 'portal.example.com' && str_starts_with($rule['number'], 'FWDR'), 'V1 and number prefix FWDR');
    check(str_contains(br_error(fn() => $new('host_exact', 'portal.example.com')), 'Duplicate of'), 'same profile: duplicate');
    check(str_contains(br_error(fn() => rec_insert('u_fwd_rule', ['u_profile' => $chain['sys_id'], 'u_match_type' => 'host_exact',
        'u_value' => 'portal.example.com', 'u_state' => 'pending_approval', 'u_owner_group' => 'x', 'u_justification' => 'x'], 'system')),
        'Conflicts with'), 'other profile: conflict');
    check(br_error(fn() => $new('ip_cidr', '203.0.113.0/24')) === '', 'valid CIDR accepted');

    // Integration account.
    rec_update('u_fwd_rule', $rule['sys_id'], ['u_state' => 'approved'], 'system');
    check(br_error(fn() => rec_update('u_fwd_rule', $rule['sys_id'], ['u_value' => 'other.example.com'], 'svc_pac_sync')) !== '',
        'integration cannot change the value');
    check(br_error(fn() => rec_update('u_fwd_rule', $rule['sys_id'], ['u_state' => 'rejected'], 'svc_pac_sync')) !== '',
        'integration cannot move approved to rejected');
    check(br_error(fn() => rec_update('u_fwd_rule', $rule['sys_id'], ['u_deploy_status' => 'deployed', 'u_deploy_version' => 'x:v1',
        'u_state' => 'deployed'], 'svc_pac_sync')) === '', 'integration may set deploy fields and approved to deployed');
    check(api_acl('write', 'u_fwd_rule', 'svc_pac_sync') && !api_acl('write', 'u_fwd_profile', 'svc_pac_sync')
        && api_acl('read', 'u_fwd_profile', 'svc_pac_sync'), 'integration ACLs');
    check(br_error(fn() => rec_update('u_fwd_rule', $rule['sys_id'], ['u_justification' => 'y'], 'jdoe')) !== '',
        'plain user cannot edit a rule directly');

    // Request workflow.
    $grp = rec_insert('sys_user_group', ['name' => 'fwdtest-grp', 'manager' => user_sys_id('mmanager')], 'system');
    $form = ['request_type' => 'new', 'u_profile' => $chain['sys_id'], 'u_match_type' => 'ip_cidr', 'u_value' => '198.51.100.0/24',
        'u_owner_group' => $grp['sys_id'], 'u_justification' => 'test'];
    check(br_error(fn() => submit_fwd_request(['u_value' => '198.51.100.7/24'] + $form, 'jdoe')) !== '', 'bad value rejected on submit');
    $ritm = submit_fwd_request($form, 'jdoe');
    check($ritm['cat_item'] === 'Request forwarding exception', 'catalog item name');
    $fr = rec_get('u_fwd_rule', $ritm['u_fwd_rule']);
    check($fr['u_state'] === 'pending_approval', 'submit creates a pending rule');
    $steps = rec_query('sysapproval_approver', "sysapproval={$ritm['sys_id']}^ORDERBYorder")[0];
    check(count($steps) === 2, 'two approval steps');
    rec_update('sysapproval_approver', $steps[0]['sys_id'], ['state' => 'approved'], 'mmanager');
    rec_update('sysapproval_approver', $steps[1]['sys_id'], ['state' => 'approved'], 'nsec');
    check(rec_get('u_fwd_rule', $fr['sys_id'])['u_state'] === 'approved', 'approved after both steps');
    rec_update('u_fwd_rule', $fr['sys_id'], ['u_deploy_status' => 'deployed', 'u_state' => 'deployed'], 'svc_pac_sync');
    check(rec_get('sc_req_item', $ritm['sys_id'])['state'] === 'closed_complete', 'deployed closes the request');

    $ret = submit_fwd_request(['request_type' => 'retire', 'target_rule' => $fr['sys_id'], 'u_justification' => 'gone'], 'jdoe');
    foreach (rec_query('sysapproval_approver', "sysapproval={$ret['sys_id']}^ORDERBYorder")[0] as $i => $s) {
        rec_update('sysapproval_approver', $s['sys_id'], ['state' => 'approved'], $i === 0 ? 'mmanager' : 'nsec');
    }
    check(rec_get('u_fwd_rule', $fr['sys_id'])['u_state'] === 'retire_requested', 'approved retire sets retire_requested');
    rec_update('u_fwd_rule', $fr['sys_id'], ['u_state' => 'retired'], 'svc_pac_sync');
    check(rec_get('sc_req_item', $ret['sys_id'])['state'] === 'closed_complete', 'retired closes the retire request');
} finally {
    db()->rollBack();
}
exit($failures ? 1 : 0);
