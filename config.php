<?php
// ServiceNow mock configuration. Local development only: never expose this server on a network.
return [
    'instance_name' => 'pacdev (mock)',
    'db_path' => __DIR__ . '/data/snow.sqlite',

    // OAuth application registry (System OAuth > Application Registry in a real instance).
    // A client_credentials token acts as the mapped user.
    'oauth_clients' => [
        'pac-sync' => ['secret' => 'pac-sync-secret', 'user' => 'svc_pac_sync'],
    ],
    'token_ttl' => 1800,

    // Clients allowed besides loopback (127.0.0.0/8, ::1): IPv4 CIDRs or exact addresses. Default: loopback only.
    // Env SNOW_MOCK_ALLOWED_CLIENTS (comma-separated) adds more. Every other client gets 403.
    'allowed_clients' => [],

    // Seeded user passwords (sys_user.password is stored hashed).
    'seed_passwords' => [
        'admin' => 'admin',
        'svc_pac_sync' => 'svc_pac_sync_pw',
        'jdoe' => 'jdoe',
        'mmanager' => 'mmanager',
        'nsec' => 'nsec',
        'cab' => 'cab',
        'nadmin' => 'nadmin',
    ],
];
