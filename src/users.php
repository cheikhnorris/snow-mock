<?php
// Users, groups, roles. The pseudo-user "system" (business rules, seed) has every right.

const ROLE_ADMIN = 'admin';
const ROLE_PAC_ADMIN = 'u_pac_admin';
const ROLE_INTEGRATION = 'u_pac_sync_integration';

function user_by_name(string $userName): ?array
{
    return rec_first('sys_user', 'user_name=' . $userName);
}

function user_groups(string $userName): array
{
    $u = user_by_name($userName);
    if (!$u) {
        return [];
    }
    return array_column(rec_query('sys_user_grmember', 'user=' . $u['sys_id'])[0], 'group');
}

function user_roles(string $userName): array
{
    static $cache = [];
    if ($userName === 'system') {
        return [ROLE_ADMIN];
    }
    if (isset($cache[$userName])) {
        return $cache[$userName];
    }
    $u = user_by_name($userName);
    if (!$u) {
        return [];
    }
    $roles = array_filter(array_map('trim', explode(',', $u['roles'])));
    foreach (user_groups($userName) as $gid) {
        $g = rec_get('sys_user_group', $gid);
        $roles = array_merge($roles, array_filter(array_map('trim', explode(',', $g['roles'] ?? ''))));
    }
    return $cache[$userName] = array_values(array_unique($roles));
}

function has_role(string $userName, string $role): bool
{
    $roles = user_roles($userName);
    return in_array(ROLE_ADMIN, $roles, true) || in_array($role, $roles, true);
}

function is_admin(string $userName): bool
{
    return in_array(ROLE_ADMIN, user_roles($userName), true);
}

function is_pac_admin(string $userName): bool
{
    return has_role($userName, ROLE_PAC_ADMIN);
}

function is_integration(string $userName): bool
{
    return in_array(ROLE_INTEGRATION, user_roles($userName), true) && !is_admin($userName);
}

function user_sys_id(string $userName): string
{
    return user_by_name($userName)['sys_id'] ?? '';
}
