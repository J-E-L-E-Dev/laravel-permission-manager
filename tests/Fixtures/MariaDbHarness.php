<?php

namespace Ahinest\LaravelPermissionManager\Tests\Fixtures;

use Ahinest\LaravelPermissionManager\Contracts\UserAccountWriter;
use Ahinest\LaravelPermissionManager\Tests\TestCase;

class MariaDbHarness extends TestCase
{
    public function bootRuntime(): void
    {
        parent::setUp();
    }

    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $database = getenv('LPM_TEST_DATABASE');
        if (getenv('LPM_DISPOSABLE_DATABASE') !== '1' || ! preg_match('/^lpm_test_[a-f0-9]{16}$/', (string) $database)) {
            throw new \LogicException('A uniquely named, explicitly disposable LPM database is required.');
        }
        $app['config']->set('database.default', 'lpm_mariadb');
        $app['config']->set('database.connections.lpm_mariadb', [
            'driver' => 'mysql', 'host' => getenv('LPM_TEST_HOST') ?: '127.0.0.1',
            'port' => getenv('LPM_TEST_PORT') ?: '3306', 'database' => $database,
            'username' => getenv('LPM_TEST_USERNAME'), 'password' => getenv('LPM_TEST_PASSWORD'),
            'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'prefix' => '', 'strict' => true,
        ]);
        $app['config']->set('permission-manager.policy_manager_roles', ['manager']);
        $app['config']->set('permission-manager.abilities', array_fill_keys(['users.permissions', 'users.roles', 'users.create', 'users.suspend'], ['entry']));
        $app->bind(UserAccountWriter::class, AccountWriter::class);
    }

    protected function defineDatabaseMigrations() {}
}
