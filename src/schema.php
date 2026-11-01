<?php
// Table dictionary (sys_dictionary in a real instance). Every table also gets the sys_* fields.
// Field types: string, text, integer, boolean, datetime, choice, reference, glide_list, password.

const SYS_FIELDS = ['sys_id', 'sys_created_on', 'sys_created_by', 'sys_updated_on', 'sys_updated_by', 'sys_mod_count'];

function schema(): array
{
    static $schema = null;
    if ($schema !== null) {
        return $schema;
    }
    $schema = [
        'u_pac_rule' => [
            'label' => 'PAC Rule', 'display' => 'number', 'prefix' => 'PACR', 'group' => 'PAC Management',
            'list' => ['number', 'u_action', 'u_match_type', 'u_value', 'u_scope', 'u_priority', 'u_state', 'u_deploy_status', 'u_valid_to'],
            'fields' => [
                'number' => ['type' => 'string', 'label' => 'Number', 'readonly' => true],
                'short_description' => ['type' => 'string', 'label' => 'Short description'],
                'u_action' => ['type' => 'choice', 'label' => 'Action', 'mandatory' => true,
                    'choices' => ['direct' => 'Direct', 'proxy' => 'Proxy']],
                'u_match_type' => ['type' => 'choice', 'label' => 'Match type', 'mandatory' => true,
                    'choices' => ['host_exact' => 'Host (exact)', 'domain_suffix' => 'Domain and sub-domains',
                        'ip_cidr' => 'IP network (CIDR)', 'url_pattern' => 'URL pattern']],
                'u_value' => ['type' => 'string', 'label' => 'Value', 'mandatory' => true],
                'u_proxy_profile' => ['type' => 'reference', 'label' => 'Proxy profile', 'ref' => 'u_pac_proxy_profile'],
                'u_scope' => ['type' => 'choice', 'label' => 'Scope', 'mandatory' => true,
                    'choices' => ['global' => 'Global', 'regional' => 'Regional']],
                'u_regions' => ['type' => 'glide_list', 'label' => 'Regions', 'ref' => 'u_pac_region'],
                'u_priority' => ['type' => 'integer', 'label' => 'Priority', 'default' => '500'],
                'u_state' => ['type' => 'choice', 'label' => 'State', 'default' => 'draft',
                    'choices' => ['draft' => 'Draft', 'pending_approval' => 'Pending approval', 'approved' => 'Approved',
                        'deployed' => 'Deployed', 'rejected' => 'Rejected', 'retire_requested' => 'Retire requested',
                        'retired' => 'Retired']],
                'u_valid_from' => ['type' => 'datetime', 'label' => 'Valid from'],
                'u_valid_to' => ['type' => 'datetime', 'label' => 'Valid to'],
                'u_justification' => ['type' => 'text', 'label' => 'Business justification', 'mandatory' => true],
                'u_application' => ['type' => 'string', 'label' => 'Business service'],
                'u_owner_group' => ['type' => 'reference', 'label' => 'Owner group', 'ref' => 'sys_user_group', 'mandatory' => true],
                'u_change_request' => ['type' => 'reference', 'label' => 'Change request', 'ref' => 'change_request'],
                'u_requested_for' => ['type' => 'reference', 'label' => 'Requested for', 'ref' => 'sys_user'],
                'u_replaces' => ['type' => 'reference', 'label' => 'Replaces rule', 'ref' => 'u_pac_rule'],
                'u_deploy_status' => ['type' => 'choice', 'label' => 'Deploy status', 'default' => 'pending', 'integration' => true,
                    'choices' => ['pending' => 'Pending', 'staged' => 'Staged', 'deployed' => 'Deployed', 'failed' => 'Failed', 'removed' => 'Removed']],
                'u_deploy_version' => ['type' => 'string', 'label' => 'Deploy version', 'integration' => true],
                'u_deploy_message' => ['type' => 'text', 'label' => 'Deploy message', 'integration' => true],
                'u_deployed_on' => ['type' => 'datetime', 'label' => 'Deployed on', 'integration' => true],
            ],
        ],
        'u_pac_region' => [
            'label' => 'PAC Region', 'display' => 'u_code', 'group' => 'PAC Management',
            'list' => ['u_code', 'u_name', 'u_zia_pac_name', 'u_default_profile', 'u_active'],
            'fields' => [
                'u_code' => ['type' => 'string', 'label' => 'Code', 'mandatory' => true],
                'u_name' => ['type' => 'string', 'label' => 'Name', 'mandatory' => true],
                'u_zia_pac_name' => ['type' => 'string', 'label' => 'ZIA PAC file name', 'mandatory' => true],
                'u_default_profile' => ['type' => 'reference', 'label' => 'Default proxy profile', 'ref' => 'u_pac_proxy_profile', 'mandatory' => true],
                'u_active' => ['type' => 'boolean', 'label' => 'Active', 'default' => 'true'],
            ],
        ],
        'u_pac_proxy_profile' => [
            'label' => 'PAC Proxy Profile', 'display' => 'u_name', 'group' => 'PAC Management',
            'list' => ['u_name', 'u_proxy_chain', 'u_description', 'u_active'],
            'fields' => [
                'u_name' => ['type' => 'string', 'label' => 'Name', 'mandatory' => true],
                'u_proxy_chain' => ['type' => 'string', 'label' => 'Proxy chain', 'mandatory' => true],
                'u_description' => ['type' => 'string', 'label' => 'Description'],
                'u_active' => ['type' => 'boolean', 'label' => 'Active', 'default' => 'true'],
            ],
        ],
        'u_pac_deployment' => [
            'label' => 'PAC Deployment', 'display' => 'number', 'prefix' => 'PACD', 'group' => 'PAC Management',
            'list' => ['number', 'u_region', 'u_zia_version', 'u_status', 'u_rules_added', 'u_rules_removed', 'u_git_commit', 'sys_created_on'],
            'fields' => [
                'number' => ['type' => 'string', 'label' => 'Number', 'readonly' => true],
                'u_region' => ['type' => 'reference', 'label' => 'Region', 'ref' => 'u_pac_region'],
                'u_zia_version' => ['type' => 'integer', 'label' => 'ZIA version'],
                'u_status' => ['type' => 'choice', 'label' => 'Status',
                    'choices' => ['staged' => 'Staged', 'deployed' => 'Deployed', 'failed' => 'Failed', 'rolled_back' => 'Rolled back']],
                'u_git_commit' => ['type' => 'string', 'label' => 'Git commit'],
                'u_content_hash' => ['type' => 'string', 'label' => 'Content hash'],
                'u_rules_added' => ['type' => 'integer', 'label' => 'Rules added'],
                'u_rules_removed' => ['type' => 'integer', 'label' => 'Rules removed'],
                'u_summary' => ['type' => 'text', 'label' => 'Summary'],
                'u_started' => ['type' => 'datetime', 'label' => 'Started'],
                'u_finished' => ['type' => 'datetime', 'label' => 'Finished'],
            ],
        ],
        'sc_req_item' => [
            'label' => 'Requested Item', 'display' => 'number', 'prefix' => 'RITM', 'group' => 'Service Catalog',
            'list' => ['number', 'u_request_type', 'short_description', 'requested_for', 'approval', 'state', 'sys_created_on'],
            'fields' => [
                'number' => ['type' => 'string', 'label' => 'Number', 'readonly' => true],
                'cat_item' => ['type' => 'string', 'label' => 'Item', 'default' => 'Request PAC exception', 'readonly' => true],
                'short_description' => ['type' => 'string', 'label' => 'Short description'],
                'u_request_type' => ['type' => 'choice', 'label' => 'Request type',
                    'choices' => ['new' => 'New rule', 'modify' => 'Modify rule', 'extend' => 'Extend rule', 'retire' => 'Retire rule']],
                'requested_for' => ['type' => 'reference', 'label' => 'Requested for', 'ref' => 'sys_user'],
                'opened_by' => ['type' => 'reference', 'label' => 'Opened by', 'ref' => 'sys_user'],
                'approval' => ['type' => 'choice', 'label' => 'Approval', 'default' => 'requested',
                    'choices' => ['not requested' => 'Not yet requested', 'requested' => 'Requested', 'approved' => 'Approved', 'rejected' => 'Rejected']],
                'state' => ['type' => 'choice', 'label' => 'State', 'default' => 'open',
                    'choices' => ['open' => 'Open', 'work_in_progress' => 'Work in progress', 'closed_complete' => 'Closed complete', 'closed_incomplete' => 'Closed incomplete']],
                'u_pac_rule' => ['type' => 'reference', 'label' => 'PAC rule', 'ref' => 'u_pac_rule'],
                'u_target_rule' => ['type' => 'reference', 'label' => 'Target rule', 'ref' => 'u_pac_rule'],
                'variables' => ['type' => 'text', 'label' => 'Variables (JSON)', 'readonly' => true],
            ],
        ],
        'sysapproval_approver' => [
            'label' => 'Approval', 'display' => 'u_step', 'group' => 'Service Catalog',
            'list' => ['sysapproval', 'order', 'u_step', 'approver', 'u_approval_group', 'state', 'sys_updated_by', 'sys_updated_on'],
            'fields' => [
                'sysapproval' => ['type' => 'reference', 'label' => 'Approval for', 'ref' => 'sc_req_item'],
                'order' => ['type' => 'integer', 'label' => 'Order'],
                'u_step' => ['type' => 'string', 'label' => 'Step'],
                'approver' => ['type' => 'reference', 'label' => 'Approver', 'ref' => 'sys_user'],
                'u_approval_group' => ['type' => 'reference', 'label' => 'Approval group', 'ref' => 'sys_user_group'],
                'state' => ['type' => 'choice', 'label' => 'State', 'default' => 'not_yet_requested',
                    'choices' => ['not_yet_requested' => 'Not yet requested', 'requested' => 'Requested', 'approved' => 'Approved',
                        'rejected' => 'Rejected', 'not_required' => 'No longer required']],
                'comments' => ['type' => 'text', 'label' => 'Comments'],
                'u_approved_by' => ['type' => 'string', 'label' => 'Approved by'], // write-once, set by the business rule
            ],
        ],
        'change_request' => [
            'label' => 'Change Request', 'display' => 'number', 'prefix' => 'CHG', 'group' => 'Change',
            'list' => ['number', 'short_description', 'state', 'requested_by'],
            'fields' => [
                'number' => ['type' => 'string', 'label' => 'Number', 'readonly' => true],
                'short_description' => ['type' => 'string', 'label' => 'Short description', 'mandatory' => true],
                'state' => ['type' => 'choice', 'label' => 'State', 'default' => 'scheduled',
                    'choices' => ['new' => 'New', 'assess' => 'Assess', 'authorize' => 'Authorize', 'scheduled' => 'Scheduled',
                        'implement' => 'Implement', 'review' => 'Review', 'closed' => 'Closed']],
                'requested_by' => ['type' => 'reference', 'label' => 'Requested by', 'ref' => 'sys_user'],
            ],
        ],
        'sys_user' => [
            'label' => 'User', 'display' => 'name', 'group' => 'User Administration',
            'list' => ['user_name', 'name', 'email', 'roles', 'web_service_access_only', 'active'],
            'fields' => [
                'user_name' => ['type' => 'string', 'label' => 'User ID', 'mandatory' => true],
                'name' => ['type' => 'string', 'label' => 'Name', 'mandatory' => true],
                'email' => ['type' => 'string', 'label' => 'Email'],
                'roles' => ['type' => 'string', 'label' => 'Roles (comma separated)'],
                'password' => ['type' => 'password', 'label' => 'Password'],
                'web_service_access_only' => ['type' => 'boolean', 'label' => 'Web service access only', 'default' => 'false'],
                'active' => ['type' => 'boolean', 'label' => 'Active', 'default' => 'true'],
            ],
        ],
        'sys_user_group' => [
            'label' => 'Group', 'display' => 'name', 'group' => 'User Administration',
            'list' => ['name', 'manager', 'roles', 'description'],
            'fields' => [
                'name' => ['type' => 'string', 'label' => 'Name', 'mandatory' => true],
                'manager' => ['type' => 'reference', 'label' => 'Manager', 'ref' => 'sys_user'],
                'roles' => ['type' => 'string', 'label' => 'Roles (comma separated)'],
                'description' => ['type' => 'string', 'label' => 'Description'],
            ],
        ],
        'sys_user_grmember' => [
            'label' => 'Group Member', 'display' => 'user', 'group' => 'User Administration',
            'list' => ['group', 'user'],
            'fields' => [
                'group' => ['type' => 'reference', 'label' => 'Group', 'ref' => 'sys_user_group', 'mandatory' => true],
                'user' => ['type' => 'reference', 'label' => 'User', 'ref' => 'sys_user', 'mandatory' => true],
            ],
        ],
        'sys_audit' => [
            'label' => 'Audit', 'display' => 'fieldname', 'group' => 'System Logs', 'readonly' => true,
            'list' => ['sys_created_on', 'tablename', 'documentkey', 'fieldname', 'oldvalue', 'newvalue', 'user'],
            'fields' => [
                'tablename' => ['type' => 'string', 'label' => 'Table'],
                'documentkey' => ['type' => 'string', 'label' => 'Record'],
                'fieldname' => ['type' => 'string', 'label' => 'Field'],
                'oldvalue' => ['type' => 'text', 'label' => 'Old value'],
                'newvalue' => ['type' => 'text', 'label' => 'New value'],
                'user' => ['type' => 'string', 'label' => 'User'],
            ],
        ],
    ];
    return $schema;
}

function table_def(string $table): array
{
    $s = schema();
    if (!isset($s[$table])) {
        throw new NotFoundException("Invalid table $table");
    }
    return $s[$table];
}

function columns(string $table): array
{
    return array_merge(SYS_FIELDS, array_keys(table_def($table)['fields']));
}

function field_def(string $table, string $field): array
{
    $sys = [
        'sys_id' => ['type' => 'string', 'label' => 'Sys ID'],
        'sys_created_on' => ['type' => 'datetime', 'label' => 'Created'],
        'sys_created_by' => ['type' => 'string', 'label' => 'Created by'],
        'sys_updated_on' => ['type' => 'datetime', 'label' => 'Updated'],
        'sys_updated_by' => ['type' => 'string', 'label' => 'Updated by'],
        'sys_mod_count' => ['type' => 'integer', 'label' => 'Updates'],
    ];
    return table_def($table)['fields'][$field] ?? $sys[$field] ?? ['type' => 'string', 'label' => $field];
}
