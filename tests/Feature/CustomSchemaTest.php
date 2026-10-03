<?php

namespace Ahinest\LaravelPermissionManager\Tests\Feature;

use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\CustomRole;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Ahinest\LaravelPermissionManager\Tests\TestCase;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Schema;

class CustomSchemaTest extends TestCase
{
    protected function defineEnvironment($app)
    {
        parent::defineEnvironment($app);
        $app['config']->set('permission.models.role', CustomRole::class);
        foreach ($app['config']->get('permission.table_names') as $key => $value) {
            $app['config']->set('permission.table_names.'.$key, 'host_'.$value);
        }
        $app['config']->set('permission.column_names.role_pivot_key', 'host_role_id');
        $app['config']->set('permission.column_names.permission_pivot_key', 'host_permission_id');
        $app['config']->set('permission.column_names.model_morph_key', 'host_model_id');
    }

    public function test_custom_role_tables_pivot_keys_and_morph_map_work(): void
    {
        Relation::enforceMorphMap(['host-user' => User::class]);
        try {
            $admin = User::create(['name' => 'Admin']);
            $admin->assignRole(CustomRole::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
            $target = User::create(['name' => 'Target']);
            $role = app(RoleManagementService::class)->create($admin, ['name' => 'ordinary', 'level' => 7]);
            app(UserPermissionManagementService::class)->replaceRoles($admin, $target, [$role->id]);
            $this->assertInstanceOf(CustomRole::class, $role);
            $this->assertFalse(Schema::hasTable('roles'));
            $this->assertDatabaseHas('host_model_has_roles', ['host_role_id' => $role->id, 'model_type' => 'host-user', 'host_model_id' => $target->id]);
        } finally {
            Relation::morphMap([], false);
            Relation::requireMorphMap(false);
        }
    }
}
