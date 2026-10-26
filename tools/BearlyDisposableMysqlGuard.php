<?php

declare(strict_types=1);

namespace Bearly\Tools\Safety;

use RuntimeException;

/**
 * Pure pre-connection validation for a future disposable MySQL migration runner.
 *
 * This class does not bootstrap Laravel, resolve a connection, create PDO, or
 * perform network/database operations. Call it with Laravel's resolved config,
 * an environment snapshot captured before Laravel bootstrap, trusted target
 * policy, and observations from that same bootstrap.
 */
final class BearlyDisposableMysqlGuard
{
    private const APPROVED_APP_ENV = 'testing';

    private const OPT_IN_VARIABLE = 'BEARLY_DISPOSABLE_MYSQL_OPT_IN';

    private const FORBIDDEN_DATABASE = 'bearly_friend_snapshot_dev';

    private const FORBIDDEN_MYSQL_PORT = 3306;

    /**
     * Validate the effective Laravel configuration against explicit process
     * inputs and a trusted, exact-target allowlist.
     *
     * @param array<string, mixed> $effectiveLaravelConfig
     * @param array<string, mixed> $preBootstrapEnvironment Captured before Laravel loads .env.
     * @param array<string, mixed> $trustedPolicy Not sourced from Laravel .env or DB_* variables.
     * @param array<string, mixed> $bootstrapFacts Captured from this same Laravel bootstrap.
     * @return array{service_id: string, server_identity: string, host: string, port: int, database: string, username: string, config_cache_path: string}
     */
    public static function validate(
        array $effectiveLaravelConfig,
        array $preBootstrapEnvironment,
        array $trustedPolicy,
        array $bootstrapFacts
    ): array {
        $environment = self::normalizeEnvironment($preBootstrapEnvironment);

        self::assertRequiredExplicitValues($environment);
        self::assertNoConnectionUrlOverrides($environment, $effectiveLaravelConfig);
        self::assertApprovedEnvironment($environment, $effectiveLaravelConfig);
        self::assertFreshTemporaryConfigCache($environment, $trustedPolicy, $bootstrapFacts);

        $connection = self::resolvedMysqlConnection($effectiveLaravelConfig);
        $target = self::findExactApprovedTarget($trustedPolicy, $environment);

        self::assertEffectiveConfigurationMatches($connection, $environment, $target);

        $target['config_cache_path'] = $environment['APP_CONFIG_CACHE'];

        return $target;
    }

    /**
     * Recheck the resolved target in the same process before migration begins.
     * This method validates configuration only and never opens a connection.
     *
     * @param array<string, mixed> $effectiveLaravelConfig
     * @param array<string, mixed> $preBootstrapEnvironment
     * @param array<string, mixed> $trustedPolicy
     * @param array<string, mixed> $bootstrapFacts
     * @param array<string, mixed> $validatedTarget Result from validate().
     */
    public static function assertStillTargets(
        array $effectiveLaravelConfig,
        array $preBootstrapEnvironment,
        array $trustedPolicy,
        array $bootstrapFacts,
        array $validatedTarget
    ): void {
        $environment = self::normalizeEnvironment($preBootstrapEnvironment);

        self::assertRequiredExplicitValues($environment);
        self::assertNoConnectionUrlOverrides($environment, $effectiveLaravelConfig);
        self::assertApprovedEnvironment($environment, $effectiveLaravelConfig);
        self::assertFreshTemporaryConfigCache($environment, $trustedPolicy, $bootstrapFacts, false);

        if (! isset($validatedTarget['config_cache_path'])
            || ! is_string($validatedTarget['config_cache_path'])
            || ! self::samePath($validatedTarget['config_cache_path'], $environment['APP_CONFIG_CACHE'])) {
            self::reject('the validated Laravel configuration cache path changed');
        }

        $approvedTarget = self::findExactApprovedTarget($trustedPolicy, $environment);
        foreach (['service_id', 'server_identity', 'host', 'port', 'database', 'username'] as $key) {
            if (! array_key_exists($key, $validatedTarget) || $validatedTarget[$key] !== $approvedTarget[$key]) {
                self::reject('the retained approved target changed after preflight');
            }
        }

        $connection = self::resolvedMysqlConnection($effectiveLaravelConfig);
        self::assertEffectiveConfigurationMatches($connection, $environment, $approvedTarget);
    }

    /** @param array<string, mixed> $environment */
    private static function assertRequiredExplicitValues(array $environment): void
    {
        foreach ([
            'APP_ENV',
            self::OPT_IN_VARIABLE,
            'DB_CONNECTION',
            'DB_HOST',
            'DB_PORT',
            'DB_DATABASE',
            'DB_USERNAME',
            'DB_PASSWORD',
            'APP_CONFIG_CACHE',
        ] as $name) {
            $value = $environment[$name] ?? null;

            if (! is_string($value) || trim($value) === '') {
                self::reject('a required process value is missing or blank');
            }
        }

        foreach (['APP_ENV', self::OPT_IN_VARIABLE, 'DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'APP_CONFIG_CACHE'] as $name) {
            if (trim((string) $environment[$name]) !== $environment[$name]) {
                self::reject('a required process value contains surrounding whitespace');
            }
        }

        if ($environment[self::OPT_IN_VARIABLE] !== '1') {
            self::reject('the disposable MySQL opt-in is missing or not enabled');
        }

        if (! preg_match('/^[1-9][0-9]*$/D', $environment['DB_PORT'])) {
            self::reject('the MySQL port is malformed');
        }

        if (trim($environment['DB_PASSWORD']) === '') {
            self::reject('the explicit disposable database password is blank');
        }
    }

    /** @param array<string, mixed> $environment
     *  @param array<string, mixed> $effectiveLaravelConfig
     */
    private static function assertNoConnectionUrlOverrides(array $environment, array $effectiveLaravelConfig): void
    {
        foreach (['DB_URL', 'DATABASE_URL', 'MYSQL_URL', 'DATABASE_URI', 'DB_CONNECTION_URL'] as $name) {
            if (isset($environment[$name]) && (! is_string($environment[$name]) || trim($environment[$name]) !== '')) {
                self::reject('a connection URL override is present');
            }
        }

        if (isset($environment['DB_SOCKET']) && (! is_string($environment['DB_SOCKET']) || trim($environment['DB_SOCKET']) !== '')) {
            self::reject('a MySQL socket override is present');
        }

        $connections = $effectiveLaravelConfig['database']['connections'] ?? null;
        if (! is_array($connections)) {
            self::reject('resolved Laravel database connections are unavailable');
        }

        foreach ($connections as $config) {
            if (! is_array($config)) {
                self::reject('resolved Laravel database configuration is malformed');
            }

            if (array_key_exists('url', $config) && self::isNonBlank($config['url'])) {
                self::reject('a resolved Laravel connection URL override is present');
            }
        }
    }

    /** @param array<string, mixed> $environment
     *  @param array<string, mixed> $effectiveLaravelConfig
     */
    private static function assertApprovedEnvironment(array $environment, array $effectiveLaravelConfig): void
    {
        if ($environment['APP_ENV'] !== self::APPROVED_APP_ENV
            || ($effectiveLaravelConfig['app']['env'] ?? null) !== self::APPROVED_APP_ENV) {
            self::reject('the application environment is not the approved testing environment');
        }

        if ($environment['DB_CONNECTION'] !== 'mysql'
            || ($effectiveLaravelConfig['database']['default'] ?? null) !== 'mysql') {
            self::reject('the effective default database connection is not explicitly MySQL');
        }
    }

    /** @param array<string, mixed> $environment
     *  @param array<string, mixed> $trustedPolicy
     *  @param array<string, mixed> $bootstrapFacts
     */
    private static function assertFreshTemporaryConfigCache(
        array $environment,
        array $trustedPolicy,
        array $bootstrapFacts,
        bool $requireNoDatabaseConnections = true
    ): void
    {
        $cachePath = $environment['APP_CONFIG_CACHE'];
        $resolvedCachePath = $bootstrapFacts['config_cache_path'] ?? null;
        $tempDirectory = realpath(sys_get_temp_dir());

        if (! is_string($resolvedCachePath)
            || ! self::samePath($cachePath, $resolvedCachePath)
            || ($bootstrapFacts['configuration_cached'] ?? null) !== false
            || ($bootstrapFacts['cache_path_existed_before_bootstrap'] ?? null) !== false
            || ($requireNoDatabaseConnections && ($bootstrapFacts['database_connections_opened'] ?? null) !== false)
            || $tempDirectory === false
            || ! self::isAbsolutePath($cachePath)
            || ! self::pathIsDirectChildOf($cachePath, $tempDirectory)
            || ! preg_match('/^bearly-sync3-config-[a-f0-9]{24,64}\.php$/iD', basename($cachePath))
            || file_exists($cachePath)
            || is_link($cachePath)) {
            self::reject('Laravel configuration cache is not fresh, uncached, and isolated in the system temporary directory');
        }

        if (isset($trustedPolicy['temporary_directory'])) {
            $policyTempDirectory = $trustedPolicy['temporary_directory'];
            $realPolicyTempDirectory = is_string($policyTempDirectory) ? realpath($policyTempDirectory) : false;

            if ($realPolicyTempDirectory === false || ! self::samePath($tempDirectory, $realPolicyTempDirectory)) {
                self::reject('the configured temporary directory is not the system temporary directory');
            }
        }
    }

    /** @param array<string, mixed> $effectiveLaravelConfig
     *  @return array<string, mixed>
     */
    private static function resolvedMysqlConnection(array $effectiveLaravelConfig): array
    {
        $connection = $effectiveLaravelConfig['database']['connections']['mysql'] ?? null;

        if (! is_array($connection)) {
            self::reject('the resolved MySQL connection is missing');
        }

        foreach (['driver', 'host', 'port', 'database', 'username', 'password', 'url', 'unix_socket'] as $key) {
            if (! array_key_exists($key, $connection)) {
                self::reject('a required resolved MySQL configuration value is missing');
            }
        }

        if ($connection['driver'] !== 'mysql') {
            self::reject('the resolved MySQL connection has an unexpected driver');
        }

        return $connection;
    }

    /** @param array<string, mixed> $trustedPolicy
     *  @param array<string, mixed> $environment
     *  @return array{service_id: string, server_identity: string, host: string, port: int, database: string, username: string}
     */
    private static function findExactApprovedTarget(array $trustedPolicy, array $environment): array
    {
        $services = $trustedPolicy['isolated_services'] ?? null;
        $approvedTargets = $trustedPolicy['approved_targets'] ?? null;
        $applicationUsernames = $trustedPolicy['application_usernames'] ?? null;

        if (! is_array($services) || $services === []
            || ! is_array($approvedTargets) || $approvedTargets === []
            || ! is_array($applicationUsernames) || $applicationUsernames === []) {
            self::reject('trusted isolated-service, target, or application-account policy is missing');
        }

        $normalizedApplicationUsernames = [];
        foreach ($applicationUsernames as $applicationUsername) {
            if (! is_string($applicationUsername) || trim($applicationUsername) === '') {
                self::reject('trusted application-account policy is malformed');
            }

            $normalizedApplicationUsernames[] = strtolower($applicationUsername);
        }

        $serviceById = [];
        $seenEndpoints = [];
        foreach ($services as $service) {
            if (! is_array($service)
                || ! self::validPolicyIdentifier($service['id'] ?? null)
                || ! self::validHost($service['host'] ?? null)
                || ! self::validPort($service['port'] ?? null)
                || (int) $service['port'] === self::FORBIDDEN_MYSQL_PORT
                || ! self::validPolicyIdentifier($service['server_identity'] ?? null)) {
                self::reject('trusted isolated-service allowlist is malformed or unsafe');
            }

            $id = $service['id'];
            $endpoint = strtolower($service['host']).':'.(int) $service['port'];
            if (isset($serviceById[$id]) || isset($seenEndpoints[$endpoint])) {
                self::reject('trusted isolated-service allowlist is ambiguous');
            }

            $serviceById[$id] = $service;
            $seenEndpoints[$endpoint] = true;
        }

        $matches = [];
        $seenTargets = [];
        foreach ($approvedTargets as $approvedTarget) {
            if (! is_array($approvedTarget)
                || ! self::validPolicyIdentifier($approvedTarget['service_id'] ?? null)
                || ! isset($serviceById[$approvedTarget['service_id']])) {
                self::reject('trusted disposable database target policy is malformed or unsafe');
            }

            if (is_string($approvedTarget['database'] ?? null)
                && strtolower($approvedTarget['database']) === self::FORBIDDEN_DATABASE) {
                self::reject('the shared development database is forbidden');
            }

            if (! self::validDatabaseName($approvedTarget['database'] ?? null)
                || ! self::validDisposableUsername($approvedTarget['username'] ?? null)) {
                self::reject('trusted disposable database target policy is malformed or unsafe');
            }

            $service = $serviceById[$approvedTarget['service_id']];
            $tuple = strtolower($service['host']).':'.(int) $service['port'].'/'.strtolower($approvedTarget['database']).'/'.strtolower($approvedTarget['username']);
            if (isset($seenTargets[$tuple])) {
                self::reject('trusted disposable database target policy is ambiguous');
            }
            $seenTargets[$tuple] = true;

            if (strtolower($approvedTarget['database']) === self::FORBIDDEN_DATABASE
                || in_array(strtolower($approvedTarget['username']), $normalizedApplicationUsernames, true)
                || in_array(strtolower($approvedTarget['username']), ['root', 'admin', 'administrator'])) {
                self::reject('the approved target reuses a shared database or application account');
            }

            if ($service['host'] === $environment['DB_HOST']
                && (string) $service['port'] === $environment['DB_PORT']
                && $approvedTarget['database'] === $environment['DB_DATABASE']
                && $approvedTarget['username'] === $environment['DB_USERNAME']) {
                $matches[] = [
                    'service_id' => $service['id'],
                    'server_identity' => $service['server_identity'],
                    'host' => $service['host'],
                    'port' => (int) $service['port'],
                    'database' => $approvedTarget['database'],
                    'username' => $approvedTarget['username'],
                ];
            }
        }

        if (count($matches) !== 1) {
            self::reject('the requested MySQL target does not match exactly one approved disposable target');
        }

        return $matches[0];
    }

    /** @param array<string, mixed> $connection
     *  @param array<string, mixed> $environment
     *  @param array{service_id: string, server_identity: string, host: string, port: int, database: string, username: string} $target
     */
    private static function assertEffectiveConfigurationMatches(array $connection, array $environment, array $target): void
    {
        $configuredPort = self::portAsInt($connection['port']);

        if ($target['port'] === self::FORBIDDEN_MYSQL_PORT
            || $target['host'] !== $environment['DB_HOST']
            || $target['port'] !== (int) $environment['DB_PORT']
            || $target['database'] !== $environment['DB_DATABASE']
            || $target['username'] !== $environment['DB_USERNAME']) {
            self::reject('explicit process values do not match the approved isolated target');
        }

        if ($connection['host'] !== $target['host']
            || $configuredPort !== $target['port']
            || $connection['database'] !== $target['database']
            || $connection['username'] !== $target['username']
            || ! is_string($connection['password'])
            || ! hash_equals($environment['DB_PASSWORD'], $connection['password'])
            || self::isNonBlank($connection['url'])
            || self::isNonBlank($connection['unix_socket'])) {
            self::reject('effective Laravel MySQL configuration conflicts with explicit process values');
        }
    }

    /** @param array<string, mixed> $environment
     *  @return array<string, string>
     */
    private static function normalizeEnvironment(array $environment): array
    {
        $normalized = [];
        foreach ($environment as $key => $value) {
            if (! is_string($key)) {
                self::reject('the pre-bootstrap environment snapshot is malformed');
            }

            $normalizedKey = strtoupper($key);
            if (array_key_exists($normalizedKey, $normalized)) {
                self::reject('the pre-bootstrap environment snapshot contains ambiguous duplicate keys');
            }

            if (! is_string($value)) {
                self::reject('the pre-bootstrap environment snapshot contains a malformed value');
            }

            $normalized[$normalizedKey] = $value;
        }

        return $normalized;
    }

    private static function validPolicyIdentifier(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{2,95}$/D', $value) === 1;
    }

    private static function validHost(mixed $value): bool
    {
        if (! is_string($value)
            || trim($value) !== $value
            || $value === ''
            || strlen($value) > 253
            || strtolower($value) === 'localhost'
            || in_array($value, ['0.0.0.0', '::', '[::]'], true)
            || preg_match('/\s/', $value)) {
            return false;
        }

        $validIp = filter_var($value, FILTER_VALIDATE_IP) !== false;
        if ($validIp) {
            $packedIp = inet_pton($value);

            return is_string($packedIp) && trim($packedIp, "\0") !== '';
        }

        $validDnsName = preg_match(
            '/^(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?)(?:\.(?:[A-Za-z0-9](?:[A-Za-z0-9-]{0,61}[A-Za-z0-9])?))*$/D',
            $value
        ) === 1;

        return $validDnsName;
    }

    private static function validPort(mixed $value): bool
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value))) {
            return false;
        }

        return (int) $value >= 1 && (int) $value <= 65535;
    }

    private static function portAsInt(mixed $value): ?int
    {
        if (! is_int($value) && (! is_string($value) || ! preg_match('/^[1-9][0-9]*$/D', $value))) {
            return null;
        }

        $port = (int) $value;

        return $port >= 1 && $port <= 65535 ? $port : null;
    }

    private static function validDatabaseName(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^bearly_sync3_(?:fresh|populated)_[a-f0-9]{16,64}$/D', $value) === 1;
    }

    private static function validDisposableUsername(mixed $value): bool
    {
        return is_string($value)
            && preg_match('/^bearly_sync3_user_[a-f0-9]{16,64}$/D', $value) === 1;
    }

    private static function isNonBlank(mixed $value): bool
    {
        return $value !== null && (! is_string($value) || trim($value) !== '');
    }

    private static function isAbsolutePath(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if ($path[0] === '/' || $path[0] === '\\') {
            return true;
        }

        if (strlen($path) < 3 || $path[1] !== ':' || ($path[2] !== '/' && $path[2] !== '\\')) {
            return false;
        }

        return ($path[0] >= 'A' && $path[0] <= 'Z')
            || ($path[0] >= 'a' && $path[0] <= 'z');
    }

    private static function pathIsDirectChildOf(string $path, string $directory): bool
    {
        $normalizedPath = self::normalizePath($path);
        $normalizedDirectory = rtrim(self::normalizePath($directory), '/');
        $parent = dirname($normalizedPath);

        return self::samePath($parent, $normalizedDirectory)
            && $parent !== $normalizedPath;
    }

    private static function samePath(string $left, string $right): bool
    {
        $left = self::normalizePath($left);
        $right = self::normalizePath($right);

        return DIRECTORY_SEPARATOR === '\\'
            ? strtolower($left) === strtolower($right)
            : $left === $right;
    }

    private static function normalizePath(string $path): string
    {
        return rtrim(str_replace('\\', '/', $path), '/');
    }

    private static function reject(string $reason): never
    {
        throw new RuntimeException('Disposable MySQL guard rejected the target: '.$reason.'.');
    }
}
