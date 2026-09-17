<?php
/**
 * SMTP mailer skeleton for cPanel hosting.
 *
 * Phase 1: the function signature and config plumbing are in place, but no
 * message is actually sent yet. A real SMTP dialogue over fsockopen (or the
 * project's chosen approach without Composer packages) lands in a later phase.
 */

require_once __DIR__ . '/functions.php';

function send_mail(string $to, string $subject, string $htmlBody, string $textBody = ''): bool
{
    $mail = app_config('mail');
    if (empty($mail['host'])) {
        return false;
    }

    // Phase 2 stub: log intent so developers can see the pipeline is reachable.
    // Actual SMTP dialogue over fsockopen lands in the auth/email phase.
    if (app_config('app')['debug'] ?? false) {
        error_log(sprintf(
            '[mailer:stub] to=%s subject=%s (SMTP send not implemented yet)',
            $to,
            $subject
        ));
    }

    unset($htmlBody, $textBody);
    return false;
}
