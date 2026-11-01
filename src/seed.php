<?php
// Demo data: users, groups, regions, proxy profiles, a change request, PAC rules in every state,
// and one open catalog request waiting for its first approval.

function seed_demo_data(): void
{
    $pw = cfg()['seed_passwords'];
    $sys = ['skip_br' => true];
    $at = fn(string $rel) => gmdate('Y-m-d H:i:s', strtotime($rel));

    $u = [];
    foreach ([
        ['admin', 'System Administrator', 'admin', 'false'],
        ['svc_pac_sync', 'PAC Sync Integration', ROLE_INTEGRATION, 'true'],
        ['jdoe', 'Jane Doe', '', 'false'],
        ['mmanager', 'Mark Manager', '', 'false'],
        ['nsec', 'Nora Security', '', 'false'],
        ['cab', 'Carl Board', '', 'false'],
        ['nadmin', 'Nina Network', '', 'false'],
    ] as [$login, $name, $roles, $wsOnly]) {
        $u[$login] = rec_insert('sys_user', ['user_name' => $login, 'name' => $name, 'email' => "$login@example.com",
            'roles' => $roles, 'password' => $pw[$login], 'web_service_access_only' => $wsOnly, 'active' => 'true'], 'system', $sys)['sys_id'];
    }

    $g = [];
    foreach ([
        ['App Team A', 'mmanager', '', 'Owns the partner portal integrations'],
        ['Workplace Team', 'mmanager', '', 'Collaboration and office tools'],
        ['PAC Approvers', '', '', 'Network security review of PAC exceptions'],
        ['CAB', '', '', 'Change Advisory Board'],
        ['Network Team', '', ROLE_PAC_ADMIN, 'Owns the PAC files and proxy profiles'],
    ] as [$name, $manager, $roles, $desc]) {
        $g[$name] = rec_insert('sys_user_group', ['name' => $name, 'manager' => $manager ? $u[$manager] : '',
            'roles' => $roles, 'description' => $desc], 'system', $sys)['sys_id'];
    }
    foreach ([['App Team A', 'jdoe'], ['App Team A', 'mmanager'], ['Workplace Team', 'mmanager'],
                 ['PAC Approvers', 'nsec'], ['CAB', 'cab'], ['Network Team', 'nadmin']] as [$group, $user]) {
        rec_insert('sys_user_grmember', ['group' => $g[$group], 'user' => $u[$user]], 'system', $sys);
    }

    $p = [];
    foreach ([
        ['ZS-DEFAULT', 'PROXY ${GATEWAY}:80; PROXY ${SECONDARY_GATEWAY}:80; DIRECT', 'Zscaler primary and secondary gateway'],
        ['PARTNER-EGRESS', 'PROXY partner-proxy.corp.local:8080; DIRECT', 'Dedicated egress for partner IP allow-lists'],
    ] as [$name, $chain, $desc]) {
        $p[$name] = rec_insert('u_pac_proxy_profile', ['u_name' => $name, 'u_proxy_chain' => $chain,
            'u_description' => $desc, 'u_active' => 'true'], 'system', $sys)['sys_id'];
    }

    $r = [];
    foreach ([['EMEA', 'Europe, Middle East, Africa', 'emea-proxy.pac'], ['NA', 'North America', 'na-proxy.pac'],
                 ['APAC', 'Asia Pacific', 'apac-proxy.pac']] as [$code, $name, $pac]) {
        $r[$code] = rec_insert('u_pac_region', ['u_code' => $code, 'u_name' => $name, 'u_zia_pac_name' => $pac,
            'u_default_profile' => $p['ZS-DEFAULT'], 'u_active' => 'true'], 'system', $sys)['sys_id'];
    }

    $chg = rec_insert('change_request', ['short_description' => 'Global PAC exceptions Q4 2026', 'state' => 'scheduled',
        'requested_by' => $u['nadmin']], 'system', $sys)['sys_id'];

    $rules = [
        ['direct', 'domain_suffix', 'corp.local', '', 'global', '', 100, 'deployed', '', '', 'Internal domain never goes through the proxy', 'Network Team', $chg, 'deployed'],
        ['direct', 'ip_cidr', '10.20.0.0/16', '', 'regional', 'EMEA', 200, 'approved', '', '', 'EMEA datacenter range', 'Network Team', '', 'pending'],
        ['proxy', 'host_exact', 'portal.partner-example.com', 'PARTNER-EGRESS', 'regional', 'EMEA,NA', 300, 'approved', '', '+180 days', 'Partner allow-lists our partner egress IP', 'App Team A', '', 'pending'],
        ['proxy', 'url_pattern', 'https://sso.partner-example.com/*', 'PARTNER-EGRESS', 'regional', 'NA', 310, 'approved', '', '', 'Partner SSO requires the egress IP', 'App Team A', '', 'pending'],
        ['direct', 'host_exact', 'teams-media.example.net', '', 'regional', 'APAC', 400, 'approved', '+30 days', '', 'Media optimisation pilot starting next month', 'Workplace Team', '', 'pending'],
        ['direct', 'host_exact', 'old-vendor.example.org', '', 'global', '', 500, 'deployed', '', '-10 days', 'Vendor migration (temporary)', 'Workplace Team', $chg, 'deployed'],
        ['direct', 'domain_suffix', 'legacy-app.example.com', '', 'global', '', 500, 'retire_requested', '', '', 'Legacy application, decommissioned', 'App Team A', $chg, 'deployed'],
    ];
    foreach ($rules as $i => [$action, $mt, $value, $prof, $scope, $regions, $prio, $state, $from, $to, $why, $group, $change, $deploy]) {
        $regionIds = $regions ? implode(',', array_map(fn($c) => $r[$c], explode(',', $regions))) : '';
        rec_insert('u_pac_rule', [
            'short_description' => strtoupper($action) . ' ' . $value,
            'u_action' => $action, 'u_match_type' => $mt, 'u_value' => $value,
            'u_proxy_profile' => $prof ? $p[$prof] : '', 'u_scope' => $scope, 'u_regions' => $regionIds,
            'u_priority' => (string)$prio, 'u_state' => $state,
            'u_valid_from' => $from ? $at($from) : '', 'u_valid_to' => $to ? $at($to) : '',
            'u_justification' => $why, 'u_owner_group' => $g[$group], 'u_change_request' => $change,
            'u_requested_for' => $u['jdoe'], 'u_deploy_status' => $deploy,
        ], 'system', $sys + ['created_on' => $at('-' . (40 - $i) . ' days')]);
    }

    // One open request, waiting for Mark Manager (step 1 of 2).
    submit_pac_request([
        'request_type' => 'new', 'u_action' => 'proxy', 'u_match_type' => 'host_exact',
        'u_value' => 'crm.partner-example.com', 'u_proxy_profile' => $p['PARTNER-EGRESS'],
        'u_scope' => 'regional', 'u_regions' => [$r['EMEA']], 'u_priority' => '320',
        'u_owner_group' => $g['App Team A'], 'u_application' => 'Partner CRM',
        'u_justification' => 'Partner CRM only accepts our partner egress IP',
    ], 'jdoe');
}
