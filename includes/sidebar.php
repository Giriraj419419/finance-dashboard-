<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';

$active = current_page();

/**
 * Nav definition. `roles` is a soft placeholder for Phase 1;
 * enforcement lands with real auth.
 */
$nav_main = [
    ['label' => 'Overview',        'href' => 'dashboard.php',        'icon' => 'grid'],
    ['label' => 'Transactions',    'href' => 'transactions.php',     'icon' => 'exchange'],
    ['label' => 'Budgets',         'href' => 'budgets.php',          'icon' => 'wallet'],
    ['label' => 'Goals',           'href' => 'goals.php',            'icon' => 'target'],
    ['label' => 'Payments',        'href' => 'payments.php',         'icon' => 'card'],
    ['label' => 'Purchase Orders', 'href' => 'purchase-orders.php',  'icon' => 'doc', 'roles' => ['admin', 'manager']],
    ['label' => 'Reminders',       'href' => 'reminders.php',        'icon' => 'bell'],
    ['label' => 'Reports',         'href' => 'reports.php',          'icon' => 'chart'],
];
$nav_account = [
    ['label' => 'Profile',  'href' => 'profile.php',  'icon' => 'user'],
    ['label' => 'Settings', 'href' => 'settings.php', 'icon' => 'gear', 'roles' => ['admin']],
];

function sidebar_icon(string $name): string
{
    $paths = [
        'grid'     => '<path d="M3 3h7v7H3zM14 3h7v7h-7zM3 14h7v7H3zM14 14h7v7h-7z"/>',
        'exchange' => '<path d="M7 7h13M7 7l4-4M7 7l4 4M17 17H4M17 17l-4 4M17 17l-4-4"/>',
        'wallet'   => '<path d="M3 7h15a3 3 0 0 1 3 3v7a3 3 0 0 1-3 3H6a3 3 0 0 1-3-3V7z"/><path d="M16 13h.01"/><path d="M3 7V6a2 2 0 0 1 2-2h11"/>',
        'target'   => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1.4"/>',
        'card'     => '<rect x="2.5" y="5.5" width="19" height="13" rx="2"/><path d="M2.5 10h19"/>',
        'doc'      => '<path d="M6 3h9l5 5v13a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1z"/><path d="M14 3v6h6"/>',
        'bell'     => '<path d="M6 8a6 6 0 1 1 12 0c0 5 2 6 2 8H4c0-2 2-3 2-8z"/><path d="M10 20a2 2 0 0 0 4 0"/>',
        'chart'    => '<path d="M4 20V10M10 20V4M16 20v-7M22 20H2"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
        'gear'     => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.7 1.7 0 0 0 .3 1.9l.1.1a2 2 0 1 1-2.8 2.8l-.1-.1a1.7 1.7 0 0 0-1.9-.3 1.7 1.7 0 0 0-1 1.5V21a2 2 0 1 1-4 0v-.1a1.7 1.7 0 0 0-1-1.5 1.7 1.7 0 0 0-1.9.3l-.1.1a2 2 0 1 1-2.8-2.8l.1-.1a1.7 1.7 0 0 0 .3-1.9 1.7 1.7 0 0 0-1.5-1H3a2 2 0 1 1 0-4h.1a1.7 1.7 0 0 0 1.5-1 1.7 1.7 0 0 0-.3-1.9l-.1-.1a2 2 0 1 1 2.8-2.8l.1.1a1.7 1.7 0 0 0 1.9.3H9a1.7 1.7 0 0 0 1-1.5V3a2 2 0 1 1 4 0v.1a1.7 1.7 0 0 0 1 1.5 1.7 1.7 0 0 0 1.9-.3l.1-.1a2 2 0 1 1 2.8 2.8l-.1.1a1.7 1.7 0 0 0-.3 1.9v.1a1.7 1.7 0 0 0 1.5 1H21a2 2 0 1 1 0 4h-.1a1.7 1.7 0 0 0-1.5 1z"/>',
    ];
    $d = $paths[$name] ?? '';
    return '<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $d . '</svg>';
}

$render_link = function (array $item) use ($active) {
    // Role gating is a UI hint only in Phase 1.
    if (!empty($item['roles']) && is_logged_in() && !has_role(...$item['roles'])) {
        return '';
    }
    $is_active = $active === $item['href'];
    $class = 'nav-link' . ($is_active ? ' is-active' : '');
    return '<li><a class="' . e($class) . '" href="' . e(base_url('/' . $item['href'])) . '">' .
           '<span class="nav-link__icon">' . sidebar_icon($item['icon']) . '</span>' .
           '<span>' . e($item['label']) . '</span>' .
           '</a></li>';
};
?>
<aside class="sidebar" data-sidebar aria-label="Primary">
    <div class="sidebar__brand">
        <span class="sidebar__logo" aria-hidden="true">F</span>
        <span class="sidebar__brand-name"><?= e(app_config('app')['name'] ?? 'Finance Dashboard') ?></span>
    </div>
    <nav class="sidebar__nav">
        <div class="sidebar__section-title">Main</div>
        <ul class="nav-list">
            <?php foreach ($nav_main as $item) { echo $render_link($item); } ?>
        </ul>
        <div class="sidebar__section-title">Account</div>
        <ul class="nav-list">
            <?php foreach ($nav_account as $item) { echo $render_link($item); } ?>
            <li>
                <form method="POST" action="<?= e(base_url('/logout.php')) ?>" class="nav-form">
                    <?= csrf_field() ?>
                    <button type="submit" class="nav-link nav-link--button">
                        <span class="nav-link__icon">
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>
                            </svg>
                        </span>
                        <span>Sign out</span>
                    </button>
                </form>
            </li>
        </ul>
    </nav>
    <div class="sidebar__footer">
        v0.1 · Phase 1 · UI shell
    </div>
</aside>
