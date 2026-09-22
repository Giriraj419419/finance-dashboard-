<?php
/**
 * Server-side CSV export for the finance modules.
 *
 * Query params:
 *   ?report=transactions | budgets | goals | payments | purchase_orders | reminders
 *   Plus optional filters that mirror the report page: from, to, type, status, category
 *
 * Always scoped to the authenticated user (+ admin/manager scope on purchase orders).
 * Uses prepared statements. Escapes values with a formula-injection guard:
 *   values starting with = + - @ tab or CR are prefixed with a single quote,
 *   preventing spreadsheet formula execution on open.
 */

require_once __DIR__ . '/includes/auth-check.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/audit.php';
require_once __DIR__ . '/csv-lib.php';

$me   = currentUser();
$uid  = (int) $me['id'];
$role = (string) ($me['role'] ?? 'employee');

$report = trim((string) ($_GET['report'] ?? ''));
$allowed_reports = ['transactions', 'budgets', 'goals', 'payments', 'purchase_orders', 'reminders'];
if (!in_array($report, $allowed_reports, true)) {
    http_response_code(400);
    header('Content-Type: text/plain');
    echo "Unknown report.\n";
    exit;
}

// csv_cell / csv_start / write_rows now live in csv-lib.php so the unit
// tests can exercise them without needing an authenticated session.

$date = date('Ymd-His');

try {
    switch ($report) {
        case 'transactions': {
            $where = ['user_id = :uid'];
            $params = [':uid' => $uid];
            if (in_array($_GET['type'] ?? '', ['income','expense'], true))                     { $where[] = 'type = :type';       $params[':type'] = $_GET['type']; }
            if (in_array($_GET['status'] ?? '', ['pending','completed','failed','cancelled'], true)) { $where[] = 'status = :status'; $params[':status'] = $_GET['status']; }
            if (!empty($_GET['category']) && mb_strlen((string) $_GET['category']) <= 120)     { $where[] = 'category = :category'; $params[':category'] = $_GET['category']; }
            if (!empty($_GET['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['from']))   { $where[] = 'transaction_date >= :from'; $params[':from'] = $_GET['from']; }
            if (!empty($_GET['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['to']))     { $where[] = 'transaction_date <= :to';   $params[':to']   = $_GET['to']; }
            $rows = fetchAll(
                'SELECT transaction_date, type, category, description, amount, payment_method, status
                 FROM transactions
                 WHERE ' . implode(' AND ', $where) . '
                 ORDER BY transaction_date DESC, id DESC
                 LIMIT 50000',
                $params
            );
            csv_start("transactions-$date.csv");
            write_rows(['Date','Type','Category','Description','Amount','Method','Status'], array_map(static function ($r) {
                return [
                    $r['transaction_date'],
                    $r['type'],
                    $r['category'],
                    $r['description'],
                    number_format((float) $r['amount'], 2, '.', ''),
                    str_replace('_', ' ', (string) $r['payment_method']),
                    $r['status'],
                ];
            }, $rows));
            break;
        }
        case 'budgets': {
            $rows = fetchAll(
                "SELECT b.name, b.category, b.budget_amount, b.start_date, b.end_date, b.status,
                        COALESCE((
                            SELECT SUM(t.amount) FROM transactions t
                            WHERE t.user_id = b.user_id AND t.type='expense' AND t.status='completed'
                              AND t.category = b.category AND t.transaction_date >= b.start_date
                              AND (b.end_date IS NULL OR t.transaction_date <= b.end_date)
                        ), 0) AS spent
                 FROM budgets b WHERE b.user_id = :u ORDER BY b.start_date DESC",
                [':u' => $uid]
            );
            csv_start("budgets-$date.csv");
            write_rows(['Name','Category','Planned','Spent','Remaining','Start','End','Status'], array_map(static function ($r) {
                $planned = (float) $r['budget_amount'];
                $spent = (float) $r['spent'];
                return [
                    $r['name'], $r['category'],
                    number_format($planned, 2, '.', ''),
                    number_format($spent, 2, '.', ''),
                    number_format(max(0, $planned - $spent), 2, '.', ''),
                    $r['start_date'], $r['end_date'], $r['status'],
                ];
            }, $rows));
            break;
        }
        case 'goals': {
            $rows = fetchAll(
                'SELECT name, target_amount, current_amount, target_date, status
                 FROM goals WHERE user_id = :u ORDER BY id DESC',
                [':u' => $uid]
            );
            csv_start("goals-$date.csv");
            write_rows(['Name','Target','Saved','Remaining','Target date','Status'], array_map(static function ($r) {
                $t = (float) $r['target_amount']; $c = (float) $r['current_amount'];
                return [$r['name'], number_format($t, 2, '.', ''), number_format($c, 2, '.', ''),
                        number_format(max(0, $t - $c), 2, '.', ''), $r['target_date'], $r['status']];
            }, $rows));
            break;
        }
        case 'payments': {
            $rows = fetchAll(
                'SELECT title, amount, due_date, payment_date, payment_method, status
                 FROM payments WHERE user_id = :u ORDER BY due_date DESC',
                [':u' => $uid]
            );
            csv_start("payments-$date.csv");
            write_rows(['Title','Amount','Due','Paid on','Method','Status'], array_map(static function ($r) {
                return [$r['title'], number_format((float) $r['amount'], 2, '.', ''),
                        $r['due_date'], $r['payment_date'], str_replace('_', ' ', (string) $r['payment_method']), $r['status']];
            }, $rows));
            break;
        }
        case 'purchase_orders': {
            $where = ['1=1']; $params = [];
            if (!in_array($role, ['admin','manager'], true)) { $where[] = 'user_id = :u'; $params[':u'] = $uid; }
            $rows = fetchAll(
                'SELECT order_number, supplier_name, order_date, expected_date, subtotal, tax_amount, total_amount, status
                 FROM purchase_orders WHERE ' . implode(' AND ', $where) . ' ORDER BY order_date DESC',
                $params
            );
            csv_start("purchase-orders-$date.csv");
            write_rows(['PO #','Supplier','Order date','Expected','Subtotal','Tax','Total','Status'], array_map(static function ($r) {
                return [$r['order_number'], $r['supplier_name'], $r['order_date'], $r['expected_date'],
                        number_format((float) $r['subtotal'], 2, '.', ''),
                        number_format((float) $r['tax_amount'], 2, '.', ''),
                        number_format((float) $r['total_amount'], 2, '.', ''), $r['status']];
            }, $rows));
            break;
        }
        case 'reminders': {
            $rows = fetchAll(
                'SELECT title, description, priority, reminder_date, recurrence_type, status
                 FROM reminders WHERE user_id = :u ORDER BY reminder_date DESC',
                [':u' => $uid]
            );
            csv_start("reminders-$date.csv");
            write_rows(['Title','Description','Priority','Due','Recurrence','Status'], array_map(static function ($r) {
                return [$r['title'], $r['description'], $r['priority'], $r['reminder_date'], $r['recurrence_type'], $r['status']];
            }, $rows));
            break;
        }
    }
    log_audit('csv_export', 'report', null, ['report' => $report]);
} catch (Throwable $e) {
    error_log('[export-csv] ' . $e->getMessage());
    http_response_code(500);
    header('Content-Type: text/plain');
    echo "Export failed.\n";
}
