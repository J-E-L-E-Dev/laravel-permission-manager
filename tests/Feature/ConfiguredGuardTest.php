<?php

namespace Ahinest\LaravelPermissionManager\Tests\Feature;

use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\StaffUser;
use Ahinest\LaravelPermissionManager\Tests\TestCase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

class ConfiguredGuardTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('auth.defaults.guard', 'staff');
        $app['config']->set('auth.guards.staff', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.providers.users.model', StaffUser::class);
        $app['config']->set('permission-manager.user_model', StaffUser::class);
        $app['config']->set('permission-manager.guard', 'staff');
        $app['config']->set('permission-manager.admin_role', 'owner');
    }

    public function test_guard_and_admin_identity_are_configurable_without_cross_guard_grants(): void
    {
        $actor = StaffUser::create(['name' => 'Owner']);
        $target = StaffUser::create(['name' => 'Target']);
        $actor->assignRole(Role::create(['name' => 'owner', 'guard_name' => 'staff', 'level' => 1]));
        $permission = Permission::create(['name' => 'inside', 'guard_name' => 'staff']);
        $foreign = Permission::create(['name' => 'outside', 'guard_name' => 'web']);
        $this->assertSame([$permission->id], app(PermissionDelegationService::class)->delegablePermissionIds($actor));
        app(UserPermissionManagementService::class)->replaceDirectPermissions($actor, $target, [$permission->id]);
        $this->assertSame([$permission->id], $target->fresh()->permissions->modelKeys());
        $this->expectException(ValidationException::class);
        app(UserPermissionManagementService::class)->replaceDirectPermissions($actor, $target, [$foreign->id]);
    }
}
