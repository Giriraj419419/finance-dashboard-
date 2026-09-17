<?php
/**
 * PDO connection + reusable query helpers.
 *
 * Design principles:
 *   - Exactly one PDO instance per request (memoised in getDatabaseConnection()).
 *   - Prepared statements everywhere; ATTR_EMULATE_PREPARES disabled.
 *   - Exceptions on error; callers catch or let a global handler log them.
 *   - No credentials or SQL strings ever reach the user in production.
 */

require_once __DIR__ . '/functions.php';

/**
 * Return the shared PDO instance, opening it on first call.
 * Throws PDOException on failure — callers must catch and translate to a
 * generic error before rendering to the user.
 */
function getDatabaseConnection(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $cfg = app_config('database');
    if (empty($cfg['name']) || empty($cfg['username'])) {
        // Explicit, developer-friendly message — this never runs in prod.
        throw new RuntimeException(
            'Database is not configured. Set database.name / database.username in config.php.'
        );
    }

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=%s',
        $cfg['host'] ?? 'localhost',
        (int) ($cfg['port'] ?? 3306),
        $cfg['name'],
        $cfg['charset'] ?? 'utf8mb4'
    );

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_PERSISTENT         => false,
    ];

    $pdo = new PDO($dsn, $cfg['username'], (string) ($cfg['password'] ?? ''), $options);
    return $pdo;
}

/**
 * Prepare + execute a statement with bound parameters. Returns the PDOStatement.
 * Prefer fetchOne / fetchAll / insertRecord / updateRecord for common cases.
 */
function executeQuery(string $sql, array $params = []): PDOStatement
{
    $stmt = getDatabaseConnection()->prepare($sql);
    $stmt->execute($params);
    return $stmt;
}

/**
 * Fetch a single row (or null if none). Always uses prepared statements.
 */
function fetchOne(string $sql, array $params = []): ?array
{
    $row = executeQuery($sql, $params)->fetch();
    return $row === false ? null : $row;
}

/**
 * Fetch all rows. Prefer LIMIT clauses on large tables.
 */
function fetchAll(string $sql, array $params = []): array
{
    return executeQuery($sql, $params)->fetchAll();
}

/**
 * Insert a row from an associative array. Column names come from $data keys —
 * caller is responsible for whitelisting keys, values are bound.
 * Returns the new row id.
 */
function insertRecord(string $table, array $data): int
{
    if ($data === []) {
        throw new InvalidArgumentException('insertRecord() called with no data.');
    }
    _assertIdentifier($table);
    foreach (array_keys($data) as $col) {
        _assertIdentifier($col);
    }

    $columns = array_keys($data);
    $placeholders = array_map(static fn ($c) => ':' . $c, $columns);
    $sql = sprintf(
        'INSERT INTO %s (%s) VALUES (%s)',
        $table,
        implode(', ', $columns),
        implode(', ', $placeholders)
    );

    $params = [];
    foreach ($data as $k => $v) {
        $params[':' . $k] = $v;
    }

    executeQuery($sql, $params);
    return (int) getDatabaseConnection()->lastInsertId();
}

/**
 * Update rows matched by $where. Returns affected-row count.
 */
function updateRecord(string $table, array $data, array $where): int
{
    if ($data === [] || $where === []) {
        throw new InvalidArgumentException('updateRecord() requires data and where.');
    }
    _assertIdentifier($table);
    foreach (array_merge(array_keys($data), array_keys($where)) as $col) {
        _assertIdentifier($col);
    }

    $set   = [];
    $where_sql = [];
    $params = [];

    foreach ($data as $col => $val) {
        $ph = ':set_' . $col;
        $set[] = $col . ' = ' . $ph;
        $params[$ph] = $val;
    }
    foreach ($where as $col => $val) {
        $ph = ':w_' . $col;
        $where_sql[] = $col . ' = ' . $ph;
        $params[$ph] = $val;
    }

    $sql = sprintf(
        'UPDATE %s SET %s WHERE %s',
        $table,
        implode(', ', $set),
        implode(' AND ', $where_sql)
    );

    return executeQuery($sql, $params)->rowCount();
}

/**
 * Guard against SQL injection through identifier interpolation. Only
 * [A-Za-z0-9_] is allowed for table + column names bound into strings.
 */
function _assertIdentifier(string $identifier): void
{
    if ($identifier === '' || preg_match('/^[A-Za-z0-9_]+$/', $identifier) !== 1) {
        throw new InvalidArgumentException('Invalid SQL identifier: ' . $identifier);
    }
}

/**
 * Install a global exception handler that hides internal detail in production.
 * Call once, early — for example from a front controller in Phase 3.
 */
function install_db_error_handler(): void
{
    set_exception_handler(static function (Throwable $e): void {
        $debug = (bool) (app_config('app')['debug'] ?? false);
        error_log('[db-error] ' . $e->getMessage());
        http_response_code(500);
        if ($debug) {
            header('Content-Type: text/plain; charset=utf-8');
            echo 'Server error: ' . $e->getMessage();
        } else {
            header('Content-Type: text/html; charset=utf-8');
            echo '<h1>Server error</h1><p>Please try again later.</p>';
        }
        exit;
    });
}
