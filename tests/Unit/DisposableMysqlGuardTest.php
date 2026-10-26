<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bearly\Tools\Safety\BearlyDisposableMysqlGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/tools/BearlyDisposableMysqlGuard.php';

final class DisposableMysqlGuardTest extends TestCase
{
    public function test_accepts_a_fully_explicit_synthetic_disposable_target(): void
    {
        [$config, $environment, $policy, $facts] = $this->validInputs();

        $target = BearlyDisposableMysqlGuard::validate($config, $environment, $policy, $facts);

        $this->assertSame('sync3-isolated-local', $target['service_id']);
        $this->assertSame('isolated-mysql-sync3-20261025', $target['server_identity']);
        $this->assertSame('127.0.0.1', $target['host']);
        $this->assertSame(3307, $target['port']);
        $this->assertSame($config['database']['connections']['mysql']['database'], $target['database']);
        $this->assertSame($config['database']['connections']['mysql']['username'], $target['username']);
        $this->assertSame($environment['APP_CONFIG_CACHE'], $target['config_cache_path']);
        $this->assertArrayNotHasKey('password', $target);
    }

    public function test_absolute_path_detection_accepts_windows_and_unix_paths(): void
    {
        $isAbsolutePath = new \ReflectionMethod(BearlyDisposableMysqlGuard::class, 'isAbsolutePath');

        $this->assertTrue($isAbsolutePath->invoke(null, 'C:\\Users\\test\\AppData\\Local\\Temp\\cache.php'));
        $this->assertTrue($isAbsolutePath->invoke(null, 'D:/Temp/cache.php'));
        $this->assertTrue($isAbsolutePath->invoke(null, '/tmp/cache.php'));
        $this->assertTrue($isAbsolutePath->invoke(null, '\\\\server\\share\\cache.php'));
        $this->assertFalse($isAbsolutePath->invoke(null, 'bearly-sync3-config-cache.php'));
        $this->assertFalse($isAbsolutePath->invoke(null, 'C:relative-cache.php'));
    }

    public function test_cache_path_containment_respects_directory_boundaries(): void
    {
        $isDirectChild = new \ReflectionMethod(BearlyDisposableMysqlGuard::class, 'pathIsDirectChildOf');

        $this->assertTrue($isDirectChild->invoke(null, 'C:\\Temp\\bearly-sync3-config-cache.php', 'C:\\Temp'));
        $this->assertTrue($isDirectChild->invoke(null, '/tmp/bearly-sync3-config-cache.php', '/tmp'));
        $this->assertFalse($isDirectChild->invoke(null, 'C:\\TempSibling\\bearly-sync3-config-cache.php', 'C:\\Temp'));
        $this->assertFalse($isDirectChild->invoke(null, '/tmp-sibling/bearly-sync3-config-cache.php', '/tmp'));
        $this->assertFalse($isDirectChild->invoke(null, '/tmp/../outside/bearly-sync3-config-cache.php', '/tmp'));
    }

    public function test_rejects_an_existing_synthetic_config_cache_file(): void
    {
        [$config, $environment, $policy, $facts] = $this->validInputs();
        $cachePath = $environment['APP_CONFIG_CACHE'];
        $handle = fopen($cachePath, 'x');

        $this->assertIsResource($handle);
        fclose($handle);

        try {
            try {
                BearlyDisposableMysqlGuard::validate($config, $environment, $policy, $facts);
                $this->fail('An existing temporary config-cache path should be rejected.');
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString(
                    'Laravel configuration cache is not fresh, uncached, and isolated',
                    $exception->getMessage()
                );
            }
        } finally {
            if (file_exists($cachePath) && ! unlink($cachePath)) {
                $this->fail('The synthetic temporary config-cache probe could not be removed.');
            }
        }
    }

    public function test_same_process_recheck_rejects_configuration_drift(): void
    {
        [$config, $environment, $policy, $facts] = $this->validInputs();
        $target = BearlyDisposableMysqlGuard::validate($config, $environment, $policy, $facts);

        BearlyDisposableMysqlGuard::assertStillTargets($config, $environment, $policy, $facts, $target);

        $config['database']['connections']['mysql']['host'] = 'changed-after-preflight.invalid';

        try {
            BearlyDisposableMysqlGuard::assertStillTargets($config, $environment, $policy, $facts, $target);
            $this->fail('The same-process recheck should reject changed effective configuration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString(
                'effective Laravel MySQL configuration conflicts with explicit process values',
                $exception->getMessage()
            );
        }
    }

    public function test_rejects_the_shared_database_name(): void
    {
        $this->assertRejected(function (array &$config, array &$environment, array &$policy): void {
            $this->replaceTarget($config, $environment, $policy, 'database', 'bearly_friend_snapshot_dev');
        });
    }

    public function test_rejects_missing_disposable_opt_in(): void
    {
        $this->assertRejected(function (array &$config, array &$environment): void {
            unset($environment['BEARLY_DISPOSABLE_MYSQL_OPT_IN']);
        });
    }

    public function test_rejects_the_default_mysql_port_even_when_configured_as_a_target(): void
    {
        $this->assertRejected(function (array &$config, array &$environment, array &$policy): void {
            $environment['DB_PORT'] = '3306';
            $config['database']['connections']['mysql']['port'] = '3306';
            $policy['isolated_services'][0]['port'] = 3306;
        });
    }

    public function test_rejects_an_unexpected_host(): void
    {
        $this->assertRejected(function (array &$config, array &$environment): void {
            $environment['DB_HOST'] = 'shared-db.internal';
            $config['database']['connections']['mysql']['host'] = 'shared-db.internal';
        });
    }

    public function test_rejects_a_username_that_is_also_the_application_account(): void
    {
        $this->assertRejected(function (array &$config, array &$environment, array &$policy): void {
            $username = $config['database']['connections']['mysql']['username'];
            $policy['application_usernames'][] = $username;
        });
    }

    public function test_rejects_an_empty_database_name(): void
    {
        $this->assertRejected(function (array &$config, array &$environment, array &$policy): void {
            $this->replaceTarget($config, $environment, $policy, 'database', '');
        });
    }

    public function test_rejects_a_database_name_without_the_disposable_prefix(): void
    {
        $this->assertRejected(function (array &$config, array &$environment, array &$policy): void {
            $this->replaceTarget($config, $environment, $policy, 'database', 'bearly_test_catalog');
        });
    }

    public function test_rejects_nonblank_db_url_and_equivalent_url_overrides(): void
    {
        foreach (['DB_URL', 'DATABASE_URL', 'MYSQL_URL', 'DATABASE_URI', 'DB_CONNECTION_URL'] as $name) {
            $this->assertRejected(function (array &$config, array &$environment) use ($name): void {
                $environment[$name] = 'mysql://must-not-be-used.invalid/disallowed';
            });
        }

        $this->assertRejected(function (array &$config): void {
            $config['database']['connections']['mysql']['url'] = 'mysql://must-not-be-used.invalid/disallowed';
        });
    }

    public function test_rejects_a_non_mysql_driver(): void
    {
        $this->assertRejected(function (array &$config, array &$environment): void {
            $environment['DB_CONNECTION'] = 'sqlite';
            $config['database']['default'] = 'sqlite';
        });

        $this->assertRejected(function (array &$config): void {
            $config['database']['connections']['mysql']['driver'] = 'mariadb';
        });
    }

    public function test_rejects_missing_or_nonisolated_temporary_config_cache(): void
    {
        $this->assertRejected(function (array &$config, array &$environment): void {
            unset($environment['APP_CONFIG_CACHE']);
        });

        $this->assertRejected(function (array &$config, array &$environment, array &$policy, array &$facts): void {
            $temporaryDirectory = $policy['temporary_directory'];
            $siblingDirectory = dirname($temporaryDirectory)
                .DIRECTORY_SEPARATOR.basename($temporaryDirectory).'-sibling';
            $cachePath = $siblingDirectory.DIRECTORY_SEPARATOR.'bearly-sync3-config-'.str_repeat('b', 24).'.php';
            $environment['APP_CONFIG_CACHE'] = $cachePath;
            $facts['config_cache_path'] = $cachePath;
        });

        $this->assertRejected(function (array &$config, array &$environment, array &$policy, array &$facts): void {
            $cachePath = 'relative'.DIRECTORY_SEPARATOR.'bearly-sync3-config-'.str_repeat('c', 24).'.php';
            $environment['APP_CONFIG_CACHE'] = $cachePath;
            $facts['config_cache_path'] = $cachePath;
        });

        $this->assertRejected(function (array &$config, array &$environment, array &$policy, array &$facts): void {
            $facts['configuration_cached'] = true;
        });

        $this->assertRejected(function (array &$config, array &$environment, array &$policy, array &$facts): void {
            $facts['cache_path_existed_before_bootstrap'] = true;
        });
    }

    public function test_rejects_conflicting_effective_laravel_configuration(): void
    {
        $this->assertRejected(function (array &$config): void {
            $config['database']['connections']['mysql']['database'] = 'bearly_sync3_fresh_9999999999999999';
        });

        $this->assertRejected(function (array &$config): void {
            $config['app']['env'] = 'production';
        });
    }

    public function test_rejects_missing_required_explicit_connection_parameters(): void
    {
        $this->assertRejected(function (array &$config, array &$environment): void {
            unset($environment['DB_HOST']);
        });

        $this->assertRejected(function (array &$config, array &$environment): void {
            unset($environment['DB_PASSWORD']);
        });

        $this->assertRejected(function (array &$config, array &$environment): void {
            $environment['DB_DATABASE'] = '';
        });
    }

    public function test_rejected_inputs_do_not_trigger_database_or_network_operations(): void
    {
        [$config, $environment, $policy, $facts] = $this->validInputs();
        unset($environment['BEARLY_DISPOSABLE_MYSQL_OPT_IN']);

        $source = file_get_contents(dirname(__DIR__, 2).'/tools/BearlyDisposableMysqlGuard.php');
        $this->assertIsString($source);
        foreach (['new PDO', 'PDO::', 'DB::connection', 'DB::select', 'mysqli_connect', 'curl_init', 'stream_socket_client', 'fsockopen('] as $connectionApi) {
            $this->assertStringNotContainsString($connectionApi, $source);
        }

        try {
            BearlyDisposableMysqlGuard::validate($config, $environment, $policy, $facts);
            $this->fail('The guard should reject missing opt-in.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('required process value', $exception->getMessage());
        }
    }

    /** @return array{array<string, mixed>, array<string, string>, array<string, mixed>, array<string, mixed>} */
    private function validInputs(): array
    {
        $token = str_repeat('a', 16);
        $database = 'bearly_sync3_fresh_'.$token;
        $username = 'bearly_sync3_user_'.$token;
        $systemTempDirectory = realpath(sys_get_temp_dir());

        if ($systemTempDirectory === false) {
            throw new RuntimeException('The system temporary directory is unavailable for the synthetic fixture.');
        }

        $cachePath = rtrim($systemTempDirectory, '\\/')
            .DIRECTORY_SEPARATOR.'bearly-sync3-config-'.bin2hex(random_bytes(12)).'.php';

        return [
            [
                'app' => ['env' => 'testing'],
                'database' => [
                    'default' => 'mysql',
                    'connections' => [
                        'mysql' => [
                            'driver' => 'mysql',
                            'host' => '127.0.0.1',
                            'port' => '3307',
                            'database' => $database,
                            'username' => $username,
                            'password' => 'synthetic-disposable-password',
                            'url' => '',
                            'unix_socket' => '',
                        ],
                    ],
                ],
            ],
            [
                'APP_ENV' => 'testing',
                'BEARLY_DISPOSABLE_MYSQL_OPT_IN' => '1',
                'DB_CONNECTION' => 'mysql',
                'DB_HOST' => '127.0.0.1',
                'DB_PORT' => '3307',
                'DB_DATABASE' => $database,
                'DB_USERNAME' => $username,
                'DB_PASSWORD' => 'synthetic-disposable-password',
                'APP_CONFIG_CACHE' => $cachePath,
                'DB_URL' => '',
            ],
            [
                'isolated_services' => [
                    [
                        'id' => 'sync3-isolated-local',
                        'host' => '127.0.0.1',
                        'port' => 3307,
                        'server_identity' => 'isolated-mysql-sync3-20261025',
                    ],
                ],
                'approved_targets' => [
                    [
                        'service_id' => 'sync3-isolated-local',
                        'database' => $database,
                        'username' => $username,
                    ],
                ],
                'application_usernames' => ['bearly_app_runtime'],
                'temporary_directory' => $systemTempDirectory,
            ],
            [
                'configuration_cached' => false,
                'config_cache_path' => $cachePath,
                'cache_path_existed_before_bootstrap' => false,
                'database_connections_opened' => false,
            ],
        ];
    }

    /** @param callable(array<string, mixed>&, array<string, string>&, array<string, mixed>&, array<string, mixed>&): void $mutate */
    private function assertRejected(callable $mutate): void
    {
        [$config, $environment, $policy, $facts] = $this->validInputs();
        $mutate($config, $environment, $policy, $facts);

        try {
            BearlyDisposableMysqlGuard::validate($config, $environment, $policy, $facts);
            $this->fail('The unsafe input should have been rejected.');
        } catch (RuntimeException $exception) {
            $this->assertStringStartsWith('Disposable MySQL guard rejected the target:', $exception->getMessage());
            $this->assertStringNotContainsString('synthetic-disposable-password', $exception->getMessage());
        }
    }

    /** @param array<string, mixed> $config
     *  @param array<string, string> $environment
     *  @param array<string, mixed> $policy
     */
    private function replaceTarget(array &$config, array &$environment, array &$policy, string $key, string $value): void
    {
        if ($key === 'database') {
            $environment['DB_DATABASE'] = $value;
            $config['database']['connections']['mysql']['database'] = $value;
            $policy['approved_targets'][0]['database'] = $value;
        }
    }
}
