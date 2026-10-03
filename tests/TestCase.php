<?php

namespace Ahinest\LaravelPermissionManager\Tests;

use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\PermissionManagerServiceProvider;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase as Orchestra;
use Spatie\Permission\PermissionServiceProvider;

class TestCase extends Orchestra
{
    protected function getPackageProviders($app)
    {
        return [PermissionServiceProvider::class, PermissionManagerServiceProvider::class];
    }

    protected function defineEnvironment($app)
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('permission.cache.store', 'array');
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]);
        $app['config']->set('auth.providers.users.model', User::class);
        $app['config']->set('permission.models.role', Role::class);
        $app['config']->set('permission-manager.user_model', User::class);
        $app['config']->set('permission-manager.admin_role', 'admin');
        $app['config']->set('permission-manager.sensitive_role_names', ['case-supervisor', 'case-support']);
    }

    protected function defineDatabaseMigrations()
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        (require __DIR__.'/../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        (require __DIR__.'/../database/migrations/2026_09_20_180000_create_permission_manager_tables.php')->up();
    }
}
