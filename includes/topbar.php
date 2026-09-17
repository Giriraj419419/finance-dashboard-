<?php
require_once __DIR__ . '/../functions.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../csrf.php';

if (!isset($page_title)) {
    $page_title = 'Dashboard';
}

// Phase 1 placeholder user shown when nobody is logged in.
$user = current_user() ?? [
    'name'  => 'Guest User',
    'email' => 'guest@example.com',
    'role'  => 'employee',
];
$initials = strtoupper(mb_substr((string) ($user['name'] ?? 'U'), 0, 1));
?>
<main class="main">
    <header class="topbar">
        <button type="button" class="topbar__toggle" data-sidebar-toggle aria-label="Toggle navigation" aria-expanded="false">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M3 6h18M3 12h18M3 18h18"/>
            </svg>
        </button>
        <div class="topbar__title"><?= e($page_title) ?></div>
        <div class="topbar__search">
            <span class="topbar__search-icon" aria-hidden="true">
                <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <circle cx="11" cy="11" r="7"/><path d="M20 20l-3.5-3.5"/>
                </svg>
            </span>
            <input type="search" placeholder="Search transactions, budgets, goals…" aria-label="Search">
        </div>
        <div class="topbar__spacer"></div>
        <div class="topbar__actions">
            <button type="button" class="topbar__icon-btn" aria-label="Notifications">
                <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M6 8a6 6 0 1 1 12 0c0 5 2 6 2 8H4c0-2 2-3 2-8z"/><path d="M10 20a2 2 0 0 0 4 0"/>
                </svg>
                <span class="dot" aria-hidden="true"></span>
            </button>
            <div class="user-menu">
                <button type="button" class="user-menu__trigger" data-user-menu-trigger aria-haspopup="menu" aria-expanded="false">
                    <span class="user-menu__avatar" aria-hidden="true"><?= e($initials) ?></span>
                    <span class="user-menu__name"><?= e($user['name']) ?></span>
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 9l6 6 6-6"/></svg>
                </button>
                <div class="user-menu__panel" data-user-menu-panel role="menu">
                    <a href="<?= e(base_url('/profile.php')) ?>" role="menuitem">Profile</a>
                    <a href="<?= e(base_url('/settings.php')) ?>" role="menuitem">Settings</a>
                    <form method="POST" action="<?= e(base_url('/logout.php')) ?>" class="user-menu__logout">
                        <?= csrf_field() ?>
                        <button type="submit" role="menuitem">Sign out</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <?php require __DIR__ . '/flash-messages.php'; ?>
