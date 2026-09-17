<?php
/**
 * Guard for authenticated pages.
 * Phase 1 leaves the check permissive so the UI shell is browsable end to end;
 * real enforcement lands with the auth phase (redirect to login.php on miss).
 */

require_once __DIR__ . '/../auth.php';

start_session_once();

// Placeholder demo user so topbar / role gating renders sensibly in Phase 1.
if (!is_logged_in()) {
    $_SESSION['user'] = [
        'id'    => 0,
        'name'  => 'Demo Admin',
        'email' => 'demo@example.com',
        'role'  => 'admin',
    ];
}

// NOTE: from Phase 2 onwards this file will actually redirect anonymous
// visitors to login.php and enforce role-based access.
