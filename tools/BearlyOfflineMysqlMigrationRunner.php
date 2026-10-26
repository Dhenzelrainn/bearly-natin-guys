<?php

declare(strict_types=1);

namespace Bearly\Tools\Safety;

use RuntimeException;

require_once __DIR__.'/BearlyDisposableMysqlGuard.php';

/**
 * Supplies effective Laravel configuration snapshots to the offline
 * coordinator. A future bootstrap adapter is intentionally not included.
 */
interface EffectiveLaravelConfigurationSnapshotSource
{
    /** @return array<string, mixed> */
    public function snapshot(): array;
}

/**
 * Supplies synthetic identity, marker, and grant evidence.
 *
 * No MySQL-backed implementation is included in this phase.
 */
interface OfflineMysqlEvidenceSource
{
    /** @return array<string, mixed> */
    public function connectionIdentity(): array;

    /** @return array<string, mixed> */
    public function schemaMarker(): array;

    /** @return array<int, array<string, mixed>> */
    public function grants(): array;
}

/**
 * Explicit operator approval for one exact disposable server and schema.
 */
final class DisposableMigrationApproval
{
    /**
     * @param array<string, mixed> $target
     */
    public function __construct(
        public readonly string $approvalId,
        public readonly string $approvedBy,
        public readonly bool $approved,
        public readonly array $target,
    ) {
    }
}

/**
 * A deliberately inert action which can only record an in-memory simulation.
 */
final class InMemoryMigrationSimulation
{
    /** @var array<int, array{action: string, target: array<string, mixed>}> */
    private array $invocations = [];

    /** @param array<string, mixed> $target */
    public function recordSimulation(array $target): void
    {
        $this->invocations[] = [
            'action' => 'simulated_migration',
            'target' => [
                'service_id' => $target['service_id'],
                'server_identity' => $target['server_identity'],
                'host' => $target['host'],
                'port' => $target['port'],
                'database' => $target['database'],
                'username' => $target['username'],
            ],
        ];
    }

    /** @return array<int, array{action: string, target: array<string, mixed>}> */
    public function invocations(): array
    {
        return $this->invocations;
    }
}

/**
 * Offline-only orchestration for modeling the future migration preflight.
 *
 * It validates supplied snapshots and synthetic evidence, then records an
 * in-memory simulation. It does not bootstrap Laravel, connect to MySQL, or
 * launch migration code.
 */
final class BearlyOfflineMysqlMigrationRunner
{
    /**
     * @param array<string, mixed> $preBootstrapEnvironment Captured before Laravel bootstrap.
     * @param array<string, mixed> $trustedPolicy Supplied out of band, never from .env.
     * @param array<string, mixed> $bootstrapFacts From the same bootstrap as the supplied config.
     * @return array{
     *   target: array<string, mixed>,
     *   completed_stages: array<int, string>
     * }
     */
    public function simulate(
        EffectiveLaravelConfigurationSnapshotSource $configurationSource,
        array $preBootstrapEnvironment,
        array $trustedPolicy,
        array $bootstrapFacts,
        ?DisposableMigrationApproval $approval,
        OfflineMysqlEvidenceSource $evidenceSource,
        InMemoryMigrationSimulation $simulation,
    ): array {
        $completedStages = ['pre_bootstrap_environment_snapshot_received'];

        $effectiveConfiguration = $configurationSource->snapshot();
        $target = BearlyDisposableMysqlGuard::validate(
            $effectiveConfiguration,
            $preBootstrapEnvironment,
            $trustedPolicy,
            $bootstrapFacts,
        );
        $completedStages[] = 'guard_validation';

        $identityPolicy = $this->trustedIdentityPolicy($target, $trustedPolicy);
        $this->assertExplicitApproval($approval, $target, $identityPolicy);
        $completedStages[] = 'exact_target_approval';

        $initialIdentity = $this->assertConnectionIdentity(
            $evidenceSource->connectionIdentity(),
            $target,
            $identityPolicy,
        );
        $completedStages[] = 'connected_identity';

        $this->assertSchemaMarker($evidenceSource->schemaMarker(), $target, $identityPolicy);
        $completedStages[] = 'schema_marker';

        $this->assertRestrictedGrants($evidenceSource->grants(), $target);
        $completedStages[] = 'restricted_grants';

        $latestConfiguration = $configurationSource->snapshot();
        BearlyDisposableMysqlGuard::assertStillTargets(
            $latestConfiguration,
            $preBootstrapEnvironment,
            $trustedPolicy,
            $bootstrapFacts,
            $target,
        );
        $completedStages[] = 'configuration_recheck';

        $latestIdentity = $this->assertConnectionIdentity(
            $evidenceSource->connectionIdentity(),
            $target,
            $identityPolicy,
        );
        if ($latestIdentity !== $initialIdentity) {
            self::reject('connected server identity changed before simulated execution');
        }
        $completedStages[] = 'connection_identity_recheck';

        $simulation->recordSimulation($target);
        $completedStages[] = 'in_memory_simulation';

        return [
            'target' => $target,
            'completed_stages' => $completedStages,
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $trustedPolicy
     * @return array{
     *   service_id: string,
     *   host: string,
     *   port: int,
     *   server_identity: string,
     *   server_uuid: string,
     *   mysql_version: string,
     *   schema_marker: array{database: string, marker_id: string}
     * }
     */
    private function trustedIdentityPolicy(array $target, array $trustedPolicy): array
    {
        $services = $trustedPolicy['isolated_services'] ?? null;
        $serviceMatches = [];
        if (is_array($services)) {
            foreach ($services as $service) {
                if (is_array($service) && ($service['id'] ?? null) === $target['service_id']) {
                    $serviceMatches[] = $service;
                }
            }
        }

        if (count($serviceMatches) !== 1) {
            self::reject('trusted server identity policy is missing or ambiguous');
        }

        $service = $serviceMatches[0];
        $serverUuid = $service['server_uuid'] ?? null;
        $mysqlVersion = $service['mysql_version'] ?? null;
        $servicePort = $service['port'] ?? null;
        $servicePortIsValid = is_int($servicePort)
            || (is_string($servicePort) && preg_match('/^[1-9][0-9]*$/D', $servicePort) === 1);
        if (($service['host'] ?? null) !== $target['host']
            || ! $servicePortIsValid
            || (int) $servicePort !== $target['port']
            || ($service['dedicated_isolated_server'] ?? null) !== true
            || ($service['server_identity'] ?? null) !== $target['server_identity']
            || ! is_string($serverUuid)
            || preg_match('~^[a-f0-9]{8}(?:-[a-f0-9]{4}){3}-[a-f0-9]{12}$~iD', $serverUuid) !== 1
            || ! is_string($mysqlVersion)
            || trim($mysqlVersion) === ''
            || trim($mysqlVersion) !== $mysqlVersion) {
            self::reject('trusted server UUID, version, or endpoint policy is malformed');
        }

        $approvedTargets = $trustedPolicy['approved_targets'] ?? null;
        $approvedTargetMatches = [];
        if (is_array($approvedTargets)) {
            foreach ($approvedTargets as $approvedTarget) {
                if (is_array($approvedTarget)
                    && ($approvedTarget['service_id'] ?? null) === $target['service_id']
                    && ($approvedTarget['database'] ?? null) === $target['database']
                    && ($approvedTarget['username'] ?? null) === $target['username']) {
                    $approvedTargetMatches[] = $approvedTarget;
                }
            }
        }

        if (count($approvedTargetMatches) !== 1) {
            self::reject('trusted disposable schema policy is missing or ambiguous');
        }

        $schemaMarker = $approvedTargetMatches[0]['schema_marker'] ?? null;
        if (! is_array($schemaMarker)
            || ($schemaMarker['database'] ?? null) !== $target['database']
            || ! is_string($schemaMarker['marker_id'] ?? null)
            || trim($schemaMarker['marker_id']) === ''
            || trim($schemaMarker['marker_id']) !== $schemaMarker['marker_id']) {
            self::reject('trusted schema marker policy is missing or malformed');
        }

        return [
            'service_id' => $target['service_id'],
            'host' => $target['host'],
            'port' => $target['port'],
            'server_identity' => $target['server_identity'],
            'server_uuid' => strtolower($serverUuid),
            'mysql_version' => $mysqlVersion,
            'schema_marker' => [
                'database' => $target['database'],
                'marker_id' => $schemaMarker['marker_id'],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $target
     * @param array<string, mixed> $identityPolicy
     */
    private function assertExplicitApproval(
        ?DisposableMigrationApproval $approval,
        array $target,
        array $identityPolicy,
    ): void {
        if ($approval === null
            || $approval->approved !== true
            || trim($approval->approvalId) === ''
            || trim($approval->approvedBy) === '') {
            self::reject('explicit operator approval is missing or invalid');
        }

        $expected = [
            'service_id' => $target['service_id'],
            'host' => $target['host'],
            'port' => $target['port'],
            'database' => $target['database'],
            'username' => $target['username'],
            'server_identity' => $target['server_identity'],
            'server_uuid' => $identityPolicy['server_uuid'],
            'mysql_version' => $identityPolicy['mysql_version'],
        ];

        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $approval->target) || $approval->target[$key] !== $value) {
                self::reject('approval does not match the exact disposable target');
            }
        }
    }

    /**
     * @param array<string, mixed> $identity
     * @param array<string, mixed> $target
     * @param array<string, mixed> $identityPolicy
     * @return array<string, mixed>
     */
    private function assertConnectionIdentity(array $identity, array $target, array $identityPolicy): array
    {
        $expected = [
            'host' => $target['host'],
            'port' => $target['port'],
            'schema' => $target['database'],
            'username' => $target['username'],
            'server_uuid' => $identityPolicy['server_uuid'],
            'server_identity' => $target['server_identity'],
            'mysql_version' => $identityPolicy['mysql_version'],
        ];

        $matchedIdentity = [];
        foreach ($expected as $key => $value) {
            if (! array_key_exists($key, $identity) || $identity[$key] !== $value) {
                self::reject('connected MySQL identity does not match the approved target');
            }

            $matchedIdentity[$key] = $identity[$key];
        }

        return $matchedIdentity;
    }

    /**
     * @param array<string, mixed> $marker
     * @param array<string, mixed> $target
     * @param array<string, mixed> $identityPolicy
     */
    private function assertSchemaMarker(array $marker, array $target, array $identityPolicy): void
    {
        $expectedMarker = $identityPolicy['schema_marker'];
        if (($marker['exists'] ?? null) !== true
            || ($marker['database'] ?? null) !== $target['database']
            || ($marker['marker_id'] ?? null) !== $expectedMarker['marker_id']) {
            self::reject('the expected disposable schema marker is missing or does not match');
        }
    }

    /**
     * @param array<int, array<string, mixed>> $grants
     * @param array<string, mixed> $target
     */
    private function assertRestrictedGrants(array $grants, array $target): void
    {
        if ($grants === []) {
            self::reject('no database-scoped privilege evidence was supplied');
        }

        foreach ($grants as $grant) {
            if (! is_array($grant)) {
                self::reject('grant evidence is malformed');
            }

            $privileges = $grant['privileges'] ?? null;
            if (($grant['scope'] ?? null) !== 'database'
                || ($grant['database'] ?? null) !== $target['database']
                || ! is_array($privileges)
                || $privileges === []) {
                self::reject('a grant is global, shared, malformed, or outside the approved schema');
            }

            foreach ($privileges as $privilege) {
                if (! is_string($privilege) || trim($privilege) === '') {
                    self::reject('grant privilege evidence is malformed');
                }
            }
        }
    }

    private static function reject(string $reason): never
    {
        throw new RuntimeException('Offline disposable migration simulation rejected: '.$reason.'.');
    }
}
