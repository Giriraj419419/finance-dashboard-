<?php
/**
 * CSRF token helpers.
 *
 * Every POST form must include csrf_field(); every state-changing handler
 * must call csrf_verify() (or csrf_check_or_die()) before touching data.
 */

require_once __DIR__ . '/auth.php';

// Ensure a session exists before ANY output so csrf_field() can emit safely
// from within HTML.
start_session_once();

function csrf_token(): string
{
    start_session_once();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string
{
    $t = e(csrf_token());
    return '<input type="hidden" name="_csrf" value="' . $t . '">';
}

function csrf_verify(?string $token): bool
{
    start_session_once();
    $expected = $_SESSION['_csrf'] ?? '';
    if ($expected === '' || !is_string($token) || $token === '') {
        return false;
    }
    return hash_equals($expected, $token);
}

/**
 * Rotate the CSRF token — call after login and after high-value writes.
 */
function csrf_rotate(): void
{
    start_session_once();
    unset($_SESSION['_csrf']);
    csrf_token();
}

/**
 * Verify the CSRF token from the current POST body, or 400 out.
 */
function csrf_check_or_die(): void
{
    $token = $_POST['_csrf'] ?? null;
    if (!csrf_verify(is_string($token) ? $token : null)) {
        http_response_code(400);
        header('Content-Type: text/html; charset=utf-8');
        echo '<h1>Bad request</h1><p>The security token was missing or invalid. Please refresh and try again.</p>';
        exit;
    }
}
