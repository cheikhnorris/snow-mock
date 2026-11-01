<?php
// Self-test: separation of duties on approvals (no approving your own request, no approving two steps of one request).
// Run: php tests/sod_test.php (exit 0 = pass). Works inside a transaction that is rolled back: writes nothing.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$failures = 0;
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
}
function approve(string $user, array $approval): string
{
    try {
        rec_update('sysapproval_approver', $approval['sys_id'], ['state' => 'approved'], $user);
        return '';
    } catch (BusinessRuleException $e) {
        return $e->getMessage();
    }
}
function request_by(string $user): array
{
    $ritm = rec_insert('sc_req_item', ['short_description' => 'sod test', 'requested_for' => user_sys_id($user),
        'opened_by' => user_sys_id($user), 'approval' => 'requested', 'state' => 'open'], 'system');
    $steps = [];
    foreach ([['Owner group manager', 'requested'], ['Network security', 'not_yet_requested'], ['CAB', 'not_yet_requested']] as $i => [$step, $state]) {
        $steps[] = rec_insert('sysapproval_approver', ['sysapproval' => $ritm['sys_id'], 'order' => (string)($i + 1),
            'u_step' => $step, 'approver' => user_sys_id('mmanager'), 'state' => $state], 'system');
    }
    return $steps;
}

db()->beginTransaction();
try {
    $steps = request_by('admin');
    check(approve('admin', $steps[0]) === 'You cannot approve your own request', 'requester (even admin) cannot approve');
    check(!in_array($steps[0]['sys_id'], array_column(pending_approvals_for('admin'), 'sys_id'), true), 'not listed for the requester');
    check(approve('mmanager', $steps[0]) === '', 'another approver can');

    $steps = request_by('jdoe');
    check(approve('admin', $steps[0]) === '', 'admin approves step 1');
    rec_update('sysapproval_approver', $steps[1]['sys_id'], ['state' => 'requested'], 'system');
    check(str_starts_with(approve('admin', rec_get('sysapproval_approver', $steps[1]['sys_id'])), 'You already approved'),
        'same person cannot approve step 2');

    // Bypass (a): a later write by someone else (comments) must not hide who approved step 1.
    $steps = request_by('jdoe');
    approve('admin', $steps[0]);
    rec_update('sysapproval_approver', $steps[0]['sys_id'], ['comments' => 'edited'], 'mmanager');
    rec_update('sysapproval_approver', $steps[1]['sys_id'], ['state' => 'requested'], 'system');
    check(str_starts_with(approve('admin', rec_get('sysapproval_approver', $steps[1]['sys_id'])), 'You already approved'),
        'comment edit by another user does not free the approver');
    // Bypass (b): moving the approval to another request after approving is refused.
    $other = request_by('jdoe');
    try {
        rec_update('sysapproval_approver', $steps[0]['sys_id'], ['sysapproval' => $other[0]['sysapproval']], 'admin');
        $moved = true;
    } catch (BusinessRuleException $e) {
        $moved = false;
    }
    check(!$moved, 'sysapproval cannot be changed after insert');
} finally {
    db()->rollBack();
}
exit($failures ? 1 : 0);
