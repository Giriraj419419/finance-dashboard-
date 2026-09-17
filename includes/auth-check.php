<?php
/**
 * Guard for authenticated pages.
 * Include at the top of every page that requires a signed-in user.
 *
 * Phase 3: the demo-user shim from earlier phases is GONE. Anonymous
 * visitors are redirected to login.php with an intended URL memory.
 */

require_once __DIR__ . '/../auth.php';

start_session_once();
requireLogin();
