<?php
/**
 * Finance Dashboard — configuration template.
 *
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored and must NEVER be committed.
 */

return [
    'app' => [
        'name'        => 'Finance Dashboard',
        'environment' => 'development', // 'development' | 'production'
        'base_url'    => 'https://your-finance-domain.com',
        'timezone'    => 'UTC',
        'debug'       => true, // set false in production
    ],

    'database' => [
        'host'     => 'localhost',
        'port'     => 3306,
        'name'     => '',
        'username' => '',
        'password' => '',
        'charset'  => 'utf8mb4',
    ],

    'session' => [
        'name'     => 'finance_dashboard_session',
        'lifetime' => 60 * 60 * 8, // 8h
        'secure'   => true,        // requires HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ],

    'uploads' => [
        'directory'   => __DIR__ . '/uploads',
        'max_size'    => 5 * 1024 * 1024, // 5 MB
        'allowed_ext' => ['pdf', 'png', 'jpg', 'jpeg', 'csv', 'xlsx'],
    ],

    'mail' => [
        'host'       => '',
        'port'       => 465,
        'secure'     => true, // true = SSL/TLS
        'username'   => '',
        'password'   => '',
        'from_email' => '',
        'from_name'  => 'Finance Dashboard',
    ],

    'security' => [
        'bcrypt_cost' => 12,
    ],
];
