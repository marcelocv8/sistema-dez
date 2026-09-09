<?php

namespace Tests;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication(): Application
    {
        $app = parent::createApplication();
        $connection = $app['config']->get('database.default');
        $database = $app['config']->get("database.connections.{$connection}.database");
        $allowed = ['sistema_dez_test'];
        $override = getenv('E2E_DB_NAME');
        if (is_string($override) && str_starts_with($override, 'sistema_dez_test')) {
            $allowed[] = $override;
        }
        if ($connection === 'pgsql' && ! in_array($database, $allowed, true)) {
            throw new \RuntimeException('Testes PostgreSQL permitidos somente em sistema_dez_test.');
        }

        return $app;
    }
}
