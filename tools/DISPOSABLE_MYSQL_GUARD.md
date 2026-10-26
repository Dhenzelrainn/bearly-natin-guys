# Disposable MySQL guard contract

`BearlyDisposableMysqlGuard` is a pure pre-connection validator for a future SYNC.3 runner. It accepts Laravel's already-resolved configuration, a process-environment snapshot captured **before** Laravel bootstrap can load `.env`, a trusted isolated-target policy, and facts captured during that same bootstrap. It does not bootstrap Laravel, resolve a database connection, create PDO, connect to a server, or launch a migration.

## Required inputs

- `APP_ENV=testing` and the effective `app.env` is also `testing`.
- `BEARLY_DISPOSABLE_MYSQL_OPT_IN=1` is explicitly present in the pre-bootstrap process environment.
- `DB_CONNECTION=mysql`, and effective `database.default` and `database.connections.mysql.driver` are `mysql`.
- `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and nonblank `DB_PASSWORD` are explicitly present before Laravel bootstrap and exactly match the resolved Laravel `mysql` connection.
- The selected host and port match one exact `isolated_services` entry. Port 3306 is always rejected. The corresponding `approved_targets` entry must name the exact schema and user.
- The schema matches `bearly_sync3_fresh_<16-64 lowercase hex>` or `bearly_sync3_populated_<16-64 lowercase hex>`, and can never be `bearly_friend_snapshot_dev`.
- The user matches `bearly_sync3_user_<16-64 lowercase hex>`, is listed in the exact approved target, and is not in the required `application_usernames` policy list. The policy must come from a trusted operator/deployment inventory, not `.env`, a Laravel config cache, or the connection variables being validated. If the current application account is unknown, validation must not run.
- `DB_URL`, `DATABASE_URL`, `MYSQL_URL`, `DATABASE_URI`, `DB_CONNECTION_URL`, and `DB_SOCKET` are absent or blank in the pre-bootstrap environment. All resolved Laravel connection `url` values and the selected MySQL socket are also blank.
- `APP_CONFIG_CACHE` is an explicit, unique `bearly-sync3-config-<24-64 hex>.php` file directly under PHP's system temporary directory. The path is absent before bootstrap and still absent after bootstrap, Laravel reports that configuration was not loaded from cache, and Laravel's resolved cache path is the same path.
- No Laravel database connection has been resolved before pre-connection validation; the runner reports `database_connections_opened=false` from the database manager's existing connection list.

The isolated service list is an exact allowlist of host/port pairs with a nonempty stable `server_identity`. The target list binds each database and disposable-only username to one service ID. Missing or ambiguous policy entries are rejected. These allowlists and `application_usernames` are trusted policy inputs; do not derive them from the app's `.env` or from the same unchecked connection values.

The unit tests use synthetic arrays and temporary paths. The stale-cache rejection test briefly creates and removes a uniquely named file under PHP's system temporary directory. They do not boot Laravel or invoke PDO/network/database APIs.

## Same-process future runner sequence

The eventual migration runner must set a unique temporary config-cache path and capture the process environment before requiring Laravel's bootstrap. It must confirm that path is absent, bootstrap Laravel in the same PHP process, and call the guard with `$app['config']->all()`, the original environment snapshot, a trusted policy, and facts from that bootstrap. It must not resolve `DB::connection()` before the guard. The initial guard requires `database_connections_opened=false`; a runner can obtain this fact by checking the database manager's already-created connection list, which does not itself open a connection.

Example integration shape for a future runner (illustrative only; it is not an executable migration command):

```php
use Bearly\Tools\Safety\BearlyDisposableMysqlGuard;
use Illuminate\Contracts\Console\Kernel;

$cachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
    .DIRECTORY_SEPARATOR.'bearly-sync3-config-'.bin2hex(random_bytes(16)).'.php';
if (file_exists($cachePath)) {
    throw new RuntimeException('Temporary config-cache path already exists.');
}
putenv('APP_CONFIG_CACHE='.$cachePath);
$_ENV['APP_CONFIG_CACHE'] = $cachePath;
$_SERVER['APP_CONFIG_CACHE'] = $cachePath;
$environmentBeforeLaravel = getenv(); // capture before Laravel bootstrap
$cacheWasAbsent = is_string($cachePath) && ! file_exists($cachePath);

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$facts = [
    'configuration_cached' => $app->configurationIsCached(),
    'config_cache_path' => $app->getCachedConfigPath(),
    'cache_path_existed_before_bootstrap' => ! $cacheWasAbsent,
    'database_connections_opened' => $app->make('db')->getConnections() !== [],
];

$target = BearlyDisposableMysqlGuard::validate(
    $app['config']->all(),
    $environmentBeforeLaravel,
    $trustedPolicy, // supplied by protected operator policy, never by .env
    $facts,
);

// Repeat the pre-connection check at the point where this same process is
// about to resolve its first connection.
$target = BearlyDisposableMysqlGuard::validate(
    $app['config']->all(),
    $environmentBeforeLaravel,
    $trustedPolicy,
    $facts,
);

// Only now may the future runner open the approved connection. It must verify
// DATABASE(), CURRENT_USER(), server hostname/port/version/UUID, explicit
// server identity, a run-specific schema marker, and restricted grants.
// Immediately before migration, reread Laravel config and call:
BearlyDisposableMysqlGuard::assertStillTargets(
    $app['config']->all(),
    $environmentBeforeLaravel,
    $trustedPolicy,
    $facts,
    $target,
);
// Permit migrations only after both guard calls and every server identity check.
```

In real code, verify the generated path is under `sys_get_temp_dir()` and has the required unique name before checking existence. A production-ready runner must also account for the exact `getenv()` key representation on its PHP runtime.

Only after pre-connection validation may that runner connect to the approved target. Before any migration, it must verify the actual `SELECT DATABASE()`, `CURRENT_USER()`, `@@hostname`, `@@port`, `@@version`, and `@@server_uuid` where supported against the approved target and explicit server identity. It must check a run-specific schema marker, inspect `SHOW GRANTS FOR CURRENT_USER`, and confirm the account has privileges only on the approved disposable schema. A global grant, a grant on another schema, a missing marker, an unsupported/unverifiable server identity, or any mismatch means abort before migrations. The account must have no privileges on `bearly_friend_snapshot_dev`; never connect to that database to test this.

The sequence is therefore:

1. Capture the original process environment and temporary-cache absence.
2. Bootstrap Laravel in the runner process and read its effective config without resolving a connection.
3. Run this validator; reject on any missing, inherited, cached, or conflicting value.
4. Revalidate that the effective DB target is unchanged immediately before connecting and again before migration.
5. Connect only to the approved disposable target and verify server, account, schema, marker, and grants.
6. Permit the runner's migration command only after every identity check passes.

The guard itself never starts step 5 or 6.

## SYNC.3B.2A offline coordinator

tools/BearlyOfflineMysqlMigrationRunner.php adds an offline coordinator that reuses BearlyDisposableMysqlGuard. Its only orchestration method is simulate(). It accepts a pre-bootstrap process-environment snapshot, a source of effective configuration snapshots, an out-of-band trusted policy, bootstrap facts, an explicit approval value, synthetic verification evidence, and an InMemoryMigrationSimulation.

The coordinator runs these stages in order:

1. Receive the process-environment snapshot that the future entry point must capture before Laravel bootstrap.
2. Read the effective configuration and call BearlyDisposableMysqlGuard::validate().
3. Require an explicit approval ID, approver, and exact target tuple: service ID, host, port, schema, account, server identity, server UUID, and expected MySQL version.
4. Compare the supplied connection identity with the trusted service and schema policy.
5. Verify a present schema marker bound to the approved schema and marker ID.
6. Reject empty, global, malformed, shared-schema, or other out-of-schema grant evidence. This offline model accepts only normalized database-scoped grants for the one approved schema.
7. Read effective Laravel configuration again and call assertStillTargets().
8. Read connection identity again and require it to match the initial identity and trusted policy.
9. Record a simulated migration in memory.

The policy extends the existing isolated_services entry with an explicit dedicated_isolated_server=true attestation, an out-of-band server_uuid, and exact mysql_version. An alternate port alone is insufficient. Each corresponding approved_targets entry includes a schema_marker object with its exact database and marker_id. The coordinator rejects missing or ambiguous records. The approval is a value object, not an environment flag. In this offline phase it models exact-target approval only; it does not authenticate the approver, enforce a role, or persist an audit record.

The coordinator has no real MySQL adapter, PDO construction, Laravel bootstrap, Artisan call, shell process, or migration execution method. InMemoryMigrationSimulation only appends a small target summary to a PHP array; it cannot invoke a callback or run SQL. The interfaces are contracts for synthetic evidence and snapshots, not proof of a live server. A successful test proves only that the offline state machine accepts internally consistent synthetic inputs.

The test adapters represent wrong UUID, schema, account, host, and port; missing and mismatched markers; global/shared grants; missing or wrong-target approvals; configuration drift; identity drift; and failed guard validation. Each rejected run verifies that the in-memory action remains uncalled. The success test checks that the action is the final stage.

## Requirements before any real database operation

- Use a dedicated isolated MySQL server. An alternate port on a shared server is not evidence of isolation.
- Obtain the server UUID, stable infrastructure identity, host, port, and exact version from a trusted operator or infrastructure inventory. Do not derive them from Laravel configuration, .env, or the connection being checked.
- Provision a disposable-only account and schema through a separately authorized process. Grant access only to the approved schema; reject global grants and any access to bearly_friend_snapshot_dev.
- Define and provision a schema marker format that binds a unique marker ID to the approved schema. The offline schema_marker structure is a contract only; this phase does not create a marker table or marker record.
- Keep credentials out of source, command-line arguments, shell history, test output, and logs. Supply them from an approved secret store to the isolated process. Do not edit .env or .env.testing. If Laravel bootstrap requires APP_KEY, use an isolated disposable key and never copy, rotate, or expose the live application's key.
- Capture the process environment before bootstrap, set a unique absent config-cache path in the system temporary directory, and verify cache and database-connection facts from that same bootstrap.
- A future real verification adapter must query the connected server's DATABASE(), CURRENT_USER(), @@hostname, @@port, @@version, and @@server_uuid; bind those results to the trusted infrastructure identity; read and validate the schema marker; and parse SHOW GRANTS FOR CURRENT_USER() to reject global or out-of-schema access. It must never connect to the shared application schema to test whether access is denied.
- Implementing a real connection adapter, schema provisioning, marker creation, grant verification, backups, migration execution, rollback, and cleanup requires a separately approved phase. No fresh or populated migrations can begin based on this offline simulation.
