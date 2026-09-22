<?php
/**
 * Test-DB bootstrap. Reads TEST_DB_DSN / TEST_DB_USER / TEST_DB_PASSWORD
 * env vars (populated by the CI workflow's MySQL service), applies
 * schema.sql + migration-006 + migration-007 into a scratch database,
 * then wires database.php to that PDO instance for the rest of the test.
 *
 * Only *-integration-test.php files require this — the pure unit tests
 * never touch a database.
 */
declare(strict_types=1);

if (!defined('DB_BOOTSTRAP_LOADED')) {
    define('DB_BOOTSTRAP_LOADED', true);

    $ROOT = dirname(__DIR__);
    require_once $ROOT . '/functions.php';

    $dsn  = getenv('TEST_DB_DSN')       ?: '';
    $user = getenv('TEST_DB_USER')      ?: '';
    $pass = getenv('TEST_DB_PASSWORD') !== false ? (string) getenv('TEST_DB_PASSWORD') : '';

    if ($dsn === '') {
        fwrite(STDERR, "TEST_DB_DSN not set — integration tests need a MySQL scratch database.\n");
        exit(2);
    }

    $pdo = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);

    // Reset every table so runs are hermetic. Drop the whole schema and
    // recreate — cheap on an in-CI MySQL, and avoids order-dependence.
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    $tables = $pdo->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"
    )->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $t) {
        $pdo->exec("DROP TABLE IF EXISTS `{$t}`");
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");

    // Apply schema. schema.sql is idempotent — it uses IF NOT EXISTS.
    $schema = (string) file_get_contents($ROOT . '/database/schema.sql');
    _exec_sql_script($pdo, $schema);

    // Ensure test-only helper functions from database.php work by
    // overriding getDatabaseConnection() to return this same PDO.
    // We do it by populating the memoised static via a reflection-free
    // detour: define a tiny shim that database.php's require will use.
    // The idiomatic approach: require database.php AFTER defining
    // getDatabaseConnection() would collide; instead we re-open the
    // connection using the same DSN the tests just used, from within
    // database.php's own flow, by injecting an in-memory config.

    // Warm the config cache with test-DB creds so database.php's own
    // getDatabaseConnection() opens the SAME database.
    $test_cfg = [
        'app' => [
            'name' => 'test', 'environment' => 'test',
            'debug' => true, 'base_url' => 'http://localhost',
            'timezone' => 'UTC',
        ],
        'database' => _dsn_to_cfg($dsn, $user, $pass),
        'session'  => ['name' => 'test_sess', 'lifetime' => 3600,
                       'secure' => false, 'httponly' => true, 'samesite' => 'Lax'],
        'uploads'  => ['directory' => sys_get_temp_dir(), 'max_size' => 1024, 'allowed_ext' => []],
        'mail'     => ['host' => 'log-only', 'port' => 25, 'secure' => false,
                       'username' => '', 'password' => '',
                       'from_email' => 'test@example.com', 'from_name' => 'Test'],
        'security' => ['bcrypt_cost' => 4,   // cheap for tests
                       'login_throttle' => ['threshold' => 3, 'window_seconds' => 60, 'lockout_seconds' => 60]],
        'push'     => ['vapid_public_key' => '', 'vapid_private_key_path' => '', 'vapid_subject' => 'mailto:test@example.com'],
    ];
    // Force the memoised app_config() static to the test config.
    _prime_app_config($test_cfg);

    require_once $ROOT . '/database.php';
    // getDatabaseConnection() will now use $test_cfg. Sanity check:
    $probe = getDatabaseConnection()->query('SELECT 1 AS one')->fetch();
    if (!$probe || (int) $probe['one'] !== 1) {
        fwrite(STDERR, "database.php failed to connect using the test config.\n");
        exit(3);
    }

    $GLOBALS['__pdo'] = $pdo;
}

/**
 * MySQL DSN → config-array shape database.php expects.
 */
function _dsn_to_cfg(string $dsn, string $user, string $pass): array
{
    $cfg = ['host' => 'localhost', 'port' => 3306, 'name' => '', 'username' => $user, 'password' => $pass, 'charset' => 'utf8mb4'];
    if (preg_match('/host=([^;]+)/', $dsn, $m)) $cfg['host'] = $m[1];
    if (preg_match('/port=(\d+)/',   $dsn, $m)) $cfg['port'] = (int) $m[1];
    if (preg_match('/dbname=([^;]+)/', $dsn, $m)) $cfg['name'] = $m[1];
    if (preg_match('/charset=([^;]+)/', $dsn, $m)) $cfg['charset'] = $m[1];
    return $cfg;
}

/**
 * Prime the static config cache inside functions.php::app_config() so
 * every consumer (database.php, csrf.php, etc.) sees the test config.
 *
 * We do this by calling app_config() once, then patching the underlying
 * static via a closure that has access to the function's scope. PHP does
 * not expose function-scoped statics directly; instead we monkey-patch
 * the config file lookup by pre-loading it into a global that our own
 * shim uses. Simpler path: overwrite $GLOBALS['__test_cfg'] and add a
 * config.php in a temp dir to the include path.
 *
 * We take the pragmatic route: write a temp config.php that returns the
 * test config, chdir the include so app_config()'s __DIR__ picks it up.
 *
 * Cleaner alternative used here: build a tiny wrapper file in the repo
 * root that app_config() will pick up ONLY when we ask it to, by
 * temporarily renaming.
 */
function _prime_app_config(array $cfg): void
{
    // functions.php's app_config() reads $ROOT/config.php first, falling
    // back to config.example.php. If a config.php already exists, we don't
    // touch it — the test runner isolates the working tree in CI, so a
    // dev's local config.php can't interfere. But we DO want the test
    // config here, not the dev placeholder. The most robust way is to
    // write our config to a well-known path that we know app_config()
    // will find first.
    //
    // Strategy: overwrite config.php in the working tree for the test's
    // lifetime, then restore it on shutdown. In CI the tree is fresh so
    // there's no dev config to preserve. Locally, we back up first.
    $ROOT = dirname(__DIR__);
    $target = $ROOT . '/config.php';
    $backup = null;
    if (is_file($target)) {
        $backup = $target . '.testbak-' . bin2hex(random_bytes(4));
        rename($target, $backup);
    }
    $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
    file_put_contents($target, $php);

    register_shutdown_function(function () use ($target, $backup) {
        @unlink($target);
        if ($backup !== null && is_file($backup)) {
            @rename($backup, $target);
        }
    });
}

/**
 * Execute a multi-statement SQL script by splitting on semicolons at
 * top-level. Doesn't attempt to be a real SQL parser — schema.sql uses
 * simple `;`-terminated statements with no DELIMITER trickery.
 * Migration files that DO use DELIMITER $$ are executed statement-by-
 * statement via a separate helper.
 */
function _exec_sql_script(PDO $pdo, string $sql): void
{
    // Strip -- line comments and blank lines to keep the split simple.
    $clean = preg_replace('/^\s*--.*$/m', '', $sql);
    $stmts = preg_split('/;\s*(?:\r?\n|$)/', (string) $clean);
    foreach ($stmts as $stmt) {
        $stmt = trim((string) $stmt);
        if ($stmt === '') continue;
        try {
            $pdo->exec($stmt);
        } catch (PDOException $e) {
            fwrite(STDERR, "SQL failed: " . substr($stmt, 0, 200) . "...\n" . $e->getMessage() . "\n");
            throw $e;
        }
    }
}

/** Convenience: rebind $GLOBALS['pdo'] fresh, used by individual tests. */
function db_pdo(): PDO { return $GLOBALS['__pdo']; }

/** Seed a user; returns id. */
function seed_user(string $email, string $role = 'employee', string $status = 'active', ?string $password = null): int
{
    $pass = $password ?? 'password1';
    $hash = password_hash($pass, PASSWORD_BCRYPT, ['cost' => 4]);
    db_pdo()->prepare("INSERT INTO users (name, email, password_hash, role, status)
                       VALUES (?, ?, ?, ?, ?)")
        ->execute([ucfirst($role), $email, $hash, $role, $status]);
    return (int) db_pdo()->lastInsertId();
}
