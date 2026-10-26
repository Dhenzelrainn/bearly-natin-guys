<?php

declare(strict_types=1);

namespace Tests\Unit;

use Bearly\Tools\Safety\BearlyOfflineMysqlMigrationRunner;
use Bearly\Tools\Safety\DisposableMigrationApproval;
use Bearly\Tools\Safety\EffectiveLaravelConfigurationSnapshotSource;
use Bearly\Tools\Safety\InMemoryMigrationSimulation;
use Bearly\Tools\Safety\OfflineMysqlEvidenceSource;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once dirname(__DIR__, 2).'/tools/BearlyOfflineMysqlMigrationRunner.php';

final class BearlyOfflineMysqlMigrationRunnerTest extends TestCase
{
    public function test_accepts_synthetic_target_and_runs_validation_in_order_before_simulation(): void
    {
        $fixture = $this->fixture();

        $result = $this->simulate($fixture);

        $this->assertSame([
            'pre_bootstrap_environment_snapshot_received',
            'guard_validation',
            'exact_target_approval',
            'connected_identity',
            'schema_marker',
            'restricted_grants',
            'configuration_recheck',
            'connection_identity_recheck',
            'in_memory_simulation',
        ], $result['completed_stages']);
        $this->assertSame([
            'configuration_snapshot',
            'connection_identity',
            'schema_marker',
            'grants',
            'configuration_snapshot',
            'connection_identity',
        ], $fixture['trace']->events);
        $this->assertCount(1, $fixture['simulation']->invocations());
        $this->assertSame('simulated_migration', $fixture['simulation']->invocations()[0]['action']);
        $this->assertArrayNotHasKey('password', $fixture['simulation']->invocations()[0]['target']);
    }

    public function test_rejects_wrong_server_uuid(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[0]['server_uuid'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_wrong_server_identity(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[0]['server_identity'] = 'another-server';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_wrong_mysql_version(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[0]['mysql_version'] = '8.0.37';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_wrong_connected_schema(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[0]['schema'] = 'bearly_sync3_fresh_9999999999999999';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_wrong_authenticated_user(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[0]['username'] = 'bearly_app_runtime';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_wrong_connected_host(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[0]['host'] = '198.51.100.21';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_wrong_connected_port(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[0]['port'] = 3306;

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_a_service_without_dedicated_isolation_attestation(): void
    {
        $fixture = $this->fixture();
        $fixture['policy']['isolated_services'][0]['dedicated_isolated_server'] = false;

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_missing_schema_marker(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->markerEvidence['exists'] = false;

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_incorrect_schema_marker(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->markerEvidence['marker_id'] = 'sync3-marker-wrong';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_global_or_out_of_schema_privileges(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->grantEvidence = [
            ['scope' => 'global', 'database' => null, 'privileges' => ['ALL PRIVILEGES']],
        ];

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_the_shared_application_database(): void
    {
        $fixture = $this->fixture();
        $sharedDatabase = 'bearly_friend_snapshot_dev';

        foreach ($fixture['configurationSource']->snapshots as &$snapshot) {
            $snapshot['database']['connections']['mysql']['database'] = $sharedDatabase;
        }
        unset($snapshot);

        $fixture['environment']['DB_DATABASE'] = $sharedDatabase;
        $fixture['policy']['approved_targets'][0]['database'] = $sharedDatabase;

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_missing_explicit_execution_approval(): void
    {
        $fixture = $this->fixture();
        $fixture['approval'] = null;

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_an_explicitly_unapproved_target(): void
    {
        $fixture = $this->fixture();
        $fixture['approval'] = new DisposableMigrationApproval(
            $fixture['approval']->approvalId,
            $fixture['approval']->approvedBy,
            false,
            $fixture['approval']->target,
        );

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_approval_for_a_different_target(): void
    {
        $fixture = $this->fixture();
        $approvalTarget = $fixture['approval']->target;
        $approvalTarget['database'] = 'bearly_sync3_fresh_9999999999999999';
        $fixture['approval'] = new DisposableMigrationApproval(
            $fixture['approval']->approvalId,
            $fixture['approval']->approvedBy,
            true,
            $approvalTarget,
        );

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_effective_configuration_drift_after_initial_validation(): void
    {
        $fixture = $this->fixture();
        $driftedConfiguration = $fixture['configurationSource']->snapshots[0];
        $driftedConfiguration['database']['connections']['mysql']['host'] = '198.51.100.22';
        $fixture['configurationSource']->snapshots[1] = $driftedConfiguration;

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_rejects_connection_identity_drift_before_simulation(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->connectionSnapshots[1]['server_uuid'] = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee';

        $this->assertRejectedWithoutSimulation($fixture);
    }

    public function test_failed_guard_validation_never_invokes_simulation(): void
    {
        $fixture = $this->fixture();
        unset($fixture['environment']['BEARLY_DISPOSABLE_MYSQL_OPT_IN']);

        $this->assertRejectedWithoutSimulation($fixture);
        $this->assertSame(['configuration_snapshot'], $fixture['trace']->events);
    }

    public function test_simulation_is_not_attempted_before_every_check_passes(): void
    {
        $fixture = $this->fixture();
        $fixture['evidence']->grantEvidence = [
            ['scope' => 'database', 'database' => 'bearly_friend_snapshot_dev', 'privileges' => ['SELECT']],
        ];

        $this->assertSame(['simulate'], get_class_methods($fixture['runner']));
        $this->assertRejectedWithoutSimulation($fixture);
        $this->assertSame([
            'configuration_snapshot',
            'connection_identity',
            'schema_marker',
            'grants',
        ], $fixture['trace']->events);
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $databaseSuffix = str_repeat('a', 16);
        $database = 'bearly_sync3_fresh_'.$databaseSuffix;
        $username = 'bearly_sync3_user_'.$databaseSuffix;
        $serverUuid = '11111111-2222-4333-8444-555555555555';
        $serverIdentity = 'sync3-dedicated-offline-fixture';
        $mysqlVersion = '8.0.36';
        $schemaMarkerId = 'sync3-marker-'.str_repeat('e', 16);
        $temporaryDirectory = realpath(sys_get_temp_dir());

        if ($temporaryDirectory === false) {
            throw new RuntimeException('The system temporary directory is unavailable for the offline fixture.');
        }

        $cachePath = rtrim($temporaryDirectory, '\\/')
            .DIRECTORY_SEPARATOR.'bearly-sync3-config-'.bin2hex(random_bytes(12)).'.php';

        $configuration = [
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
                        'password' => 'synthetic-offline-password',
                        'url' => '',
                        'unix_socket' => '',
                    ],
                ],
            ],
        ];

        $environment = [
            'APP_ENV' => 'testing',
            'BEARLY_DISPOSABLE_MYSQL_OPT_IN' => '1',
            'DB_CONNECTION' => 'mysql',
            'DB_HOST' => '127.0.0.1',
            'DB_PORT' => '3307',
            'DB_DATABASE' => $database,
            'DB_USERNAME' => $username,
            'DB_PASSWORD' => 'synthetic-offline-password',
            'APP_CONFIG_CACHE' => $cachePath,
            'DB_URL' => '',
        ];

        $policy = [
            'isolated_services' => [
                [
                    'id' => 'sync3-isolated-offline',
                    'host' => '127.0.0.1',
                    'port' => 3307,
                    'dedicated_isolated_server' => true,
                    'server_identity' => $serverIdentity,
                    'server_uuid' => $serverUuid,
                    'mysql_version' => $mysqlVersion,
                ],
            ],
            'approved_targets' => [
                [
                    'service_id' => 'sync3-isolated-offline',
                    'database' => $database,
                    'username' => $username,
                    'schema_marker' => [
                        'database' => $database,
                        'marker_id' => $schemaMarkerId,
                    ],
                ],
            ],
            'application_usernames' => ['bearly_app_runtime'],
            'temporary_directory' => $temporaryDirectory,
        ];

        $bootstrapFacts = [
            'configuration_cached' => false,
            'config_cache_path' => $cachePath,
            'cache_path_existed_before_bootstrap' => false,
            'database_connections_opened' => false,
        ];

        $identity = [
            'host' => '127.0.0.1',
            'port' => 3307,
            'schema' => $database,
            'username' => $username,
            'server_uuid' => $serverUuid,
            'server_identity' => $serverIdentity,
            'mysql_version' => $mysqlVersion,
        ];

        $trace = new OfflineRunnerTestTrace();
        $approval = new DisposableMigrationApproval(
            'approval-offline-fixture-01',
            'synthetic-operator',
            true,
            [
                'service_id' => 'sync3-isolated-offline',
                'host' => '127.0.0.1',
                'port' => 3307,
                'database' => $database,
                'username' => $username,
                'server_identity' => $serverIdentity,
                'server_uuid' => $serverUuid,
                'mysql_version' => $mysqlVersion,
            ],
        );

        return [
            'runner' => new BearlyOfflineMysqlMigrationRunner(),
            'configurationSource' => new OfflineRunnerTestConfigurationSource(
                [$configuration, $configuration],
                $trace,
            ),
            'environment' => $environment,
            'policy' => $policy,
            'bootstrapFacts' => $bootstrapFacts,
            'approval' => $approval,
            'evidence' => new OfflineRunnerTestEvidenceSource(
                [$identity, $identity],
                ['exists' => true, 'database' => $database, 'marker_id' => $schemaMarkerId],
                [['scope' => 'database', 'database' => $database, 'privileges' => ['SELECT', 'CREATE', 'ALTER']]],
                $trace,
            ),
            'simulation' => new InMemoryMigrationSimulation(),
            'trace' => $trace,
        ];
    }

    /** @param array<string, mixed> $fixture */
    private function simulate(array $fixture): array
    {
        return $fixture['runner']->simulate(
            $fixture['configurationSource'],
            $fixture['environment'],
            $fixture['policy'],
            $fixture['bootstrapFacts'],
            $fixture['approval'],
            $fixture['evidence'],
            $fixture['simulation'],
        );
    }

    /** @param array<string, mixed> $fixture */
    private function assertRejectedWithoutSimulation(array $fixture): void
    {
        try {
            $this->simulate($fixture);
            $this->fail('The offline migration simulation should have rejected this input.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('rejected', strtolower($exception->getMessage()));
        }

        $this->assertSame([], $fixture['simulation']->invocations());
    }
}

final class OfflineRunnerTestTrace
{
    /** @var array<int, string> */
    public array $events = [];
}

final class OfflineRunnerTestConfigurationSource implements EffectiveLaravelConfigurationSnapshotSource
{
    private int $snapshotIndex = 0;

    /** @param array<int, array<string, mixed>> $snapshots */
    public function __construct(
        public array $snapshots,
        private readonly OfflineRunnerTestTrace $trace,
    ) {
    }

    public function snapshot(): array
    {
        $this->trace->events[] = 'configuration_snapshot';
        $index = min($this->snapshotIndex, count($this->snapshots) - 1);
        $this->snapshotIndex++;

        return $this->snapshots[$index];
    }
}

final class OfflineRunnerTestEvidenceSource implements OfflineMysqlEvidenceSource
{
    private int $identityIndex = 0;

    /**
     * @param array<int, array<string, mixed>> $connectionSnapshots
     * @param array<string, mixed> $markerEvidence
     * @param array<int, array<string, mixed>> $grantEvidence
     */
    public function __construct(
        public array $connectionSnapshots,
        public array $markerEvidence,
        public array $grantEvidence,
        private readonly OfflineRunnerTestTrace $trace,
    ) {
    }

    public function connectionIdentity(): array
    {
        $this->trace->events[] = 'connection_identity';
        $index = min($this->identityIndex, count($this->connectionSnapshots) - 1);
        $this->identityIndex++;

        return $this->connectionSnapshots[$index];
    }

    public function schemaMarker(): array
    {
        $this->trace->events[] = 'schema_marker';

        return $this->markerEvidence;
    }

    public function grants(): array
    {
        $this->trace->events[] = 'grants';

        return $this->grantEvidence;
    }
}
