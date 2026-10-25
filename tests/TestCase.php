<?php

namespace Tests;

use Illuminate\Broadcasting\Broadcasters\NullBroadcaster;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Cache\ArrayStore;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Mail\Transport\ArrayTransport;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Facades\Facade;
use PDO;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Establish and verify isolated infrastructure before test traits can run
     * migrations or a test can execute a workflow with external side effects.
     */
    public function createApplication()
    {
        $this->prepareIsolatedTestEnvironment();

        $app = parent::createApplication();
        $config = $app['config'];

        foreach ([
            'APP_ENV' => 'testing',
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
        ] as $key => $expected) {
            if (getenv($key) !== $expected) {
                throw new RuntimeException("Automated tests require {$key}={$expected}; refusing to run.");
            }
        }

        $configCachePath = getenv('APP_CONFIG_CACHE');
        $temporaryPrefix = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, sys_get_temp_dir()), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalizedConfigCachePath = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $configCachePath);
        if ($app->configurationIsCached()
            || $configCachePath === false
            || $configCachePath === ''
            || file_exists($configCachePath)
            || ! str_starts_with(strtolower($normalizedConfigCachePath), strtolower($temporaryPrefix))) {
            throw new RuntimeException('Automated tests require uncached configuration and a fresh temporary config path; refusing to run.');
        }

        $sqlite = $config->get('database.connections.sqlite', []);
        if ($config->get('app.env') !== 'testing'
            || $config->get('database.default') !== 'sqlite'
            || ($sqlite['database'] ?? null) !== ':memory:'
            || filled($sqlite['url'] ?? null)) {
            throw new RuntimeException('Automated tests require effective SQLite :memory: configuration; refusing to run.');
        }

        $connection = $app['db']->connection();
        if ($connection->getDriverName() !== 'sqlite'
            || $connection->getDatabaseName() !== ':memory:'
            || $connection->getPdo()->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new RuntimeException('Automated tests could not verify in-memory PDO SQLite; refusing to run.');
        }

        if ($config->get('cache.default') !== 'array'
            || ! ($app->make('cache')->store()->getStore() instanceof ArrayStore)) {
            throw new RuntimeException('Automated tests require the nonpersistent array cache; refusing to run.');
        }

        if ($config->get('queue.default') !== 'sync'
            || ($config->get('queue.connections.sync.driver') !== 'sync')
            || ! ($app->make('queue')->connection() instanceof SyncQueue)) {
            throw new RuntimeException('Automated tests require the synchronous, nonpersistent queue; refusing to run.');
        }

        if (! in_array($config->get('broadcasting.default'), [null, 'null'], true)
            || $config->get('broadcasting.connections.null.driver') !== 'null'
            || ! ($app->make(BroadcastManager::class)->connection() instanceof NullBroadcaster)) {
            throw new RuntimeException('Automated tests require the null broadcast transport; refusing to run.');
        }

        $mailerConfigs = $config->get('mail.mailers', []);
        if ($config->get('mail.default') !== 'array'
            || ! is_array($mailerConfigs)
            || $mailerConfigs === []
            || ! array_key_exists('array', $mailerConfigs)) {
            throw new RuntimeException('Automated tests require the in-memory array mailer; refusing to run.');
        }

        foreach ($mailerConfigs as $name => $mailerConfig) {
            if (($mailerConfig['transport'] ?? null) !== 'array') {
                throw new RuntimeException("Automated tests detected a non-array mail transport ({$name}); refusing to run.");
            }
        }

        Facade::clearResolvedInstance('mail.manager');
        $app->forgetInstance('mail.manager');
        $mailManager = $app->make('mail.manager');
        $mailManager->forgetMailers();
        foreach (array_keys($mailerConfigs) as $name) {
            if (! ($mailManager->mailer($name)->getSymfonyTransport() instanceof ArrayTransport)) {
                throw new RuntimeException("Automated tests detected a network-capable mail transport ({$name}); refusing to run.");
            }
        }

        return $app;
    }

    /**
     * Override inherited shell and PHPUnit settings before Laravel loads any
     * configuration. The isolated config path also prevents stale config cache
     * values from replacing these test-only settings.
     */
    protected function prepareIsolatedTestEnvironment(): void
    {
        $configCachePath = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR)
            .DIRECTORY_SEPARATOR.'bearly-phpunit-config-'.bin2hex(random_bytes(16)).'.php';
        $environment = [
            'APP_ENV' => 'testing',
            'APP_CONFIG_CACHE' => $configCachePath,
            'DB_CONNECTION' => 'sqlite',
            'DB_DATABASE' => ':memory:',
            'DB_URL' => '',
            'MAIL_MAILER' => 'array',
            'QUEUE_CONNECTION' => 'sync',
            'CACHE_STORE' => 'array',
            'SESSION_DRIVER' => 'array',
            'BROADCAST_CONNECTION' => 'null',
        ];

        foreach ($environment as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }
}
