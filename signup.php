<?php
/**
 * Public signup is DISABLED on this deployment — this app runs in
 * single-user production mode. Every request to signup.php is redirected
 * to login.php with an explanatory flash message.
 *
 * The file is kept (rather than deleted) so bookmarks / old links land
 * gracefully instead of returning 404. It never touches the database and
 * never creates a session.
 */

require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/auth.php';

start_session_once();
flash('info', 'Public sign-up is disabled. Please sign in with your account.');
header('Location: ' . base_url('/login.php'));
exit;
