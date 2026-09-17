<?php
require_once __DIR__ . '/auth.php';
start_session_once();
header('Location: ' . base_url(is_logged_in() ? '/dashboard.php' : '/login.php'));
exit;
