<?php
/**
 * Copy this file to config.php and fill in real values.
 * config.php is gitignored and must never be committed.
 */

return [
    'app' => [
        'name'      => 'Finance Dashboard',
        'env'       => 'development', // development | production
        'base_url'  => 'http://localhost/finance-dashboard',
        'timezone'  => 'UTC',
        'debug'     => true,
    ],
    'db' => [
        'host'    => 'localhost',
        'port'    => 3306,
        'name'    => 'finance_dashboard',
        'user'    => 'db_user',
        'pass'    => 'db_password',
        'charset' => 'utf8mb4',
    ],
    'session' => [
        'name'      => 'FIN_SESSION',
        'lifetime'  => 60 * 60 * 8, // 8h
        'secure'    => false, // set true when serving over HTTPS
        'httponly'  => true,
        'samesite'  => 'Lax',
    ],
    'smtp' => [
        'host'       => 'mail.example.com',
        'port'       => 587,
        'username'   => 'no-reply@example.com',
        'password'   => 'change_me',
        'encryption' => 'tls', // tls | ssl | none
        'from_email' => 'no-reply@example.com',
        'from_name'  => 'Finance Dashboard',
    ],
    'uploads' => [
        'dir'         => __DIR__ . '/uploads',
        'max_bytes'   => 5 * 1024 * 1024,
        'allowed_ext' => ['pdf', 'png', 'jpg', 'jpeg', 'csv', 'xlsx'],
    ],
    'security' => [
        'bcrypt_cost' => 12,
    ],
];
