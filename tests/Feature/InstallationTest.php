<?php

use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\PermissionManagerServiceProvider;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Support\Models;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

it('installs empty extensions without inventing roles permissions or users', function () {
    foreach (['roles', 'permissions', 'users', 'lpm_delegation_policies', 'lpm_policy_permission'] as $table) {
        $this->assertDatabaseCount($table, 0);
    }
    $this->assertDatabaseHas('lpm_write_locks', ['id' => 1]);
    expect(DelegationPolicy::create(['name' => 'Policy', 'slug' => 'policy'])->fresh()->active)->toBeFalse();
});

it('preserves existing Spatie data through migration up down and a second up', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_09_20_180000_create_permission_manager_tables.php';
    $migration->down();
    $user = User::create(['name' => 'Existing']);
    $role = Role::create(['name' => 'existing', 'guard_name' => 'web']);
    $permission = Permission::create(['name' => 'existing', 'guard_name' => 'web']);
    $user->assignRole($role);
    $role->givePermissionTo($permission);
    $user->givePermissionTo($permission);
    $tables = ['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions'];
    $before = collect($tables)->mapWithKeys(fn ($name) => [$name => DB::table($name)->get()->toArray()])->all();
    $migration->up();
    expect($role->fresh()->level)->toBeNull()->and($role->fresh()->delegation_policy_id)->toBeNull();
    $migration->down();
    foreach ($tables as $name) {
        expect(DB::table($name)->get()->toArray())->toEqual($before[$name]);
    }
    $migration->up();
});

it('keeps host-owned descriptions and levels when rolling back', function () {
    $migration = require __DIR__.'/../../database/migrations/2026_09_20_180000_create_permission_manager_tables.php';
    $migration->down();
    Schema::table('roles', function (Blueprint $table) {
        $table->string('description')->nullable();
        $table->integer('level')->nullable();
    });
    Schema::table('permissions', fn (Blueprint $table) => $table->text('description')->nullable());
    $role = Role::create(['name' => 'host', 'guard_name' => 'web', 'description' => 'Keep', 'level' => 7]);
    $migration->up();
    $migration->down();
    expect($role->fresh()->description)->toBe('Keep')->and($role->fresh()->level)->toBe(7)
        ->and(Schema::hasColumn('permissions', 'description'))->toBeTrue();
});

it('publishes configuration without overwriting the host Spatie configuration', function () {
    $before = config('permission');
    $paths = PermissionManagerServiceProvider::pathsToPublish(PermissionManagerServiceProvider::class, 'permission-manager-config');
    expect($paths)->toHaveCount(1);
    foreach ($paths as $source => $destination) {
        expect(is_file($source))->toBeTrue()->and(basename($destination))->toBe('permission-manager.php');
    }
    $this->artisan('vendor:publish', ['--tag' => 'permission-manager-config', '--force' => true])->assertExitCode(0);
    expect(config('permission'))->toBe($before);
});

it('registers no administrative HTTP routes or global functional bypass', function () {
    $actor = User::create(['name' => 'Admin']);
    $actor->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    Permission::create(['name' => 'host.feature', 'guard_name' => 'web']);
    expect(app(PermissionDelegationService::class)->isAdmin($actor))->toBeTrue()
        ->and(Gate::forUser($actor)->allows('host.feature'))->toBeFalse();
    $routes = collect(app('router')->getRoutes())->filter(fn ($route) => str_contains($route->getActionName(), 'Ahinest\\') || str_contains($route->uri(), 'permission-manager'));
    expect($routes)->toBeEmpty();
});

it('fails closed for unsupported Spatie features and missing host model', function ($key, $value) {
    config([$key => $value]);
    expect(fn () => Models::user())->toThrow(LogicException::class);
})->with([
    ['permission.teams', true], ['permission.enable_wildcard_permission', true],
    ['permission-manager.guard', ''], ['permission-manager.user_model', null],
]);

it('does not make a merely reserved identity into a policy manager', function () {
    config(['permission-manager.reserved_role_names' => ['reserved']]);
    $actor = User::create(['name' => 'Reserved']);
    $actor->assignRole(Role::create(['name' => 'reserved', 'guard_name' => 'web', 'level' => 1]));
    expect(app(PermissionDelegationService::class)->isPolicyManager($actor))->toBeFalse()
        ->and(app(PermissionDelegationService::class)->snapshot($actor)->policyManager)->toBeFalse();
});
