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
        if ($connection === 'pgsql' && $database !== 'sistema_dez_test') {
            throw new \RuntimeException('Testes PostgreSQL permitidos somente em sistema_dez_test.');
        }

        return $app;
    }
}
