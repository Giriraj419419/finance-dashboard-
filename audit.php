<?php
/**
 * Audit log helper. Writes a row into `audit_logs` for significant actions.
 * Never stores passwords, tokens, or credentials — the caller is responsible
 * for scrubbing input before passing it in.
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

/**
 * @param string   $action       short slug: signup, login_success, login_failed, password_reset_requested,
 *                                           password_reset_completed, transaction_created, etc.
 * @param string   $entity_type  e.g. 'user', 'transaction', 'route'
 * @param int|null $entity_id
 * @param array    $details      arbitrary structured metadata (no secrets)
 */
function log_audit(string $action, string $entity_type, ?int $entity_id = null, array $details = []): void
{
    try {
        $user = currentUser();
        $ip = $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip !== null && strlen($ip) > 45) {
            $ip = substr($ip, 0, 45);
        }

        $json = null;
        if ($details !== []) {
            $json = json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $json = null;
            }
        }

        insertRecord('audit_logs', [
            'user_id'     => $user['id'] ?? null,
            'action'      => $action,
            'entity_type' => $entity_type,
            'entity_id'   => $entity_id,
            'details'     => $json,
            'ip_address'  => $ip,
        ]);
    } catch (Throwable $e) {
        // Never let audit failure interrupt the main flow.
        error_log('[audit] failed: ' . $e->getMessage());
    }
}
