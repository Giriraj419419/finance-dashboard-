<?php
require_once __DIR__ . '/../functions.php';
$flashes = function_exists('pull_flashes') ? pull_flashes() : [];
if (empty($flashes)) {
    return;
}
?>
<div class="flash-area" aria-live="polite">
    <?php foreach ($flashes as $f):
        $type = in_array(($f['type'] ?? ''), ['success', 'danger', 'warning', 'info'], true) ? $f['type'] : 'info';
    ?>
        <div class="flash flash--<?= e($type) ?>" data-flash role="status">
            <span><?= e($f['message'] ?? '') ?></span>
            <button type="button" class="flash__close" data-flash-close aria-label="Dismiss">
                <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M6 6l12 12M18 6L6 18"/></svg>
            </button>
        </div>
    <?php endforeach; ?>
</div>
