<?php
// Self-test: a rule number that could end the PAC comment "// <number> <ACTION>" is rejected on save.
// Run: php tests/validation_number_test.php (exit 0 = pass). Reads the mock database, writes nothing.
declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

$failures = 0;
function number_errors(string $number): string
{
    $rule = ['number' => $number, 'u_action' => 'direct', 'u_match_type' => 'host_exact', 'u_value' => 'number-test.example.com',
        'u_proxy_profile' => '', 'u_scope' => 'global', 'u_regions' => '', 'u_priority' => '500', 'u_state' => 'pending_approval',
        'u_owner_group' => 'x', 'u_justification' => 'x'];
    try {
        validate_pac_rule($rule, null);
        return '';
    } catch (BusinessRuleException $e) {
        return $e->getMessage();
    }
}
function check(bool $ok, string $what): void
{
    global $failures;
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    $failures += $ok ? 0 : 1;
}

foreach (["PACR1\nalert(1); //", "PACR1\r\nx", "PACR 1", "PACR1;", "A\n", str_repeat('A', 41), "PACR\u{e9}1"] as $bad) {
    check(str_contains(number_errors($bad), 'Number may only contain'), 'rejected: ' . json_encode($bad));
}
foreach (['PACR0001001', 'a_b-C9', str_repeat('A', 40)] as $good) {
    check(!str_contains(number_errors($good), 'Number may only contain'), 'accepted: ' . $good);
}
exit($failures ? 1 : 0);
