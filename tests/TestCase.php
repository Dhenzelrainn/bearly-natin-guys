<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();

        $defaultConnection = $app['config']->get('database.default');
        $sqliteConnection = $app['config']->get('database.connections.sqlite', []);

        if (
            $defaultConnection !== 'sqlite'
            || ($sqliteConnection['driver'] ?? null) !== 'sqlite'
            || ($sqliteConnection['database'] ?? null) !== ':memory:'
            || filled($sqliteConnection['url'] ?? null)
        ) {
            throw new RuntimeException(
                'PHPUnit is blocked: expected the default database to be SQLite :memory: with no database URL before test setup.'
            );
        }

        return $app;
    }
}
