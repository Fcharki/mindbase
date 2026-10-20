<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    /**
     * Boot the application, then refuse to let the suite touch a real database.
     *
     * The phpunit configs pin the connection, but Docker Compose exports
     * variables straight into $_SERVER and PHPUnit's forced <env> entries do
     * not override those. This guard turns a silent RefreshDatabase against the
     * application's own database into a loud failure instead.
     */
    public function createApplication()
    {
        $app = parent::createApplication();

        $connection = $app['config']->get('database.default');
        $database = $app['config']->get(
            "database.connections.{$connection}.database"
        );

        if ($connection !== 'sqlite' && ! str_ends_with((string) $database, '_testing')) {
            throw new RuntimeException(sprintf(
                'Refusing to run tests against the [%s] database [%s]. '
                .'Use sqlite or a dedicated *_testing database.',
                $connection,
                $database,
            ));
        }

        return $app;
    }
}
