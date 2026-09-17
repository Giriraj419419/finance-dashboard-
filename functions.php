<?php
/**
 * Shared utility helpers used across pages and includes.
 */

/**
 * Load and cache the application config.
 * Returns the whole config array, or a section when $section is given.
 */
function app_config(?string $section = null)
{
    static $config = null;
    if ($config === null) {
        $path = __DIR__ . '/config.php';
        if (!is_file($path)) {
            $path = __DIR__ . '/config.example.php';
        }
        $config = require $path;
        if (!is_array($config)) {
            $config = [];
        }
    }
    if ($section === null) {
        return $config;
    }
    return $config[$section] ?? [];
}

/**
 * Escape output for HTML context.
 */
function e($value): string
{
    if ($value === null) {
        return '';
    }
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Base URL, from config; falls back to script-derived value.
 */
function base_url(string $path = ''): string
{
    $base = app_config('app')['base_url'] ?? '';
    if ($base === '') {
        $base = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
    }
    $base = rtrim($base, '/');
    $path = '/' . ltrim($path, '/');
    return $base . $path;
}

/**
 * The current relative page name (e.g. "dashboard.php"), used for active nav state.
 */
function current_page(): string
{
    $script = $_SERVER['SCRIPT_NAME'] ?? '';
    return basename($script);
}

/**
 * Store a flash message for the next request.
 */
function flash(string $type, string $message): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $_SESSION['_flash'][] = ['type' => $type, 'message' => $message];
}

/**
 * Pull and clear pending flash messages.
 */
function pull_flashes(): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return [];
    }
    $flashes = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $flashes;
}

/**
 * Formatted currency. USD-only for Phase 1; localised in a later phase.
 */
function money($amount, string $symbol = '$'): string
{
    return $symbol . number_format((float) $amount, 2, '.', ',');
}
