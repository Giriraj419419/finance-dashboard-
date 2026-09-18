<?php
/**
 * Layout header. Included at the top of every authenticated page.
 * Expects an optional $page_title variable to be set beforehand.
 */

if (!isset($page_title)) {
    $page_title = 'Dashboard';
}

require_once __DIR__ . '/../functions.php';

$app = app_config('app');
$app_name = $app['name'] ?? 'Finance Dashboard';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#2563eb">
    <title><?= e($page_title . ' — ' . $app_name) ?></title>
    <link rel="stylesheet" href="<?= e(base_url('/ui/css/style.css')) ?>">
</head>
<body>
<div class="app">
    <div class="sidebar__backdrop" data-sidebar-backdrop></div>
