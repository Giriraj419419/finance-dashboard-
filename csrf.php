<?php
/**
 * CSRF token helpers. Every POST form should include csrf_field() and
 * server-side handlers should call csrf_verify() before mutating state.
 * Wiring into forms happens in later phases.
 */

require_once __DIR__ . '/auth.php';

// A session must exist before any output is sent so csrf_field() can safely
// emit the token from within HTML.
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
