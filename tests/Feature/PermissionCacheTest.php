<?php

use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\DelegationPolicyService;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\RecordingAuditStore;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Database\Connection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

it('discards a permission cache populated by an observer before rollback', function (bool $grant) {
    $admin = User::create(['name' => 'Admin']);
    $admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    $role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 20]);
    $user = User::create(['name' => 'Member']);
    $user->assignRole($role);
    $permission = Permission::create(['name' => 'example.read', 'guard_name' => 'web']);
    if (! $grant) {
        $role->givePermissionTo($permission);
    }
    expect($user->fresh()->hasPermissionTo('example.read'))->toBe(! $grant);
    Event::listen(AuthorityAudit::class, function () use ($user, $grant) {
        expect($user->fresh()->hasPermissionTo('example.read'))->toBe($grant);
        throw new RuntimeException('observer rollback');
    });
    expect(fn () => app(RoleManagementService::class)->syncPermissions($admin, $role, $grant ? [$permission->id] : []))
        ->toThrow(RuntimeException::class, 'observer rollback');
    expect($role->permissions()->exists())->toBe(! $grant);
    expect($user->fresh()->hasPermissionTo('example.read'))->toBe(! $grant);
    app(PermissionRegistrar::class)->clearPermissionsCollection();
    expect($user->fresh()->hasPermissionTo('example.read'))->toBe(! $grant);
})->with([true, false]);

it('discards uncommitted permission cache when the mandatory audit adapter fails', function () {
    Schema::create('test_authority_audits', function (Blueprint $table) {
        $table->id();
        $table->text('payload');
    });
    $admin = User::create(['name' => 'Admin']);
    $admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    $role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 20]);
    $user = User::create(['name' => 'Member']);
    $user->assignRole($role);
    $permission = Permission::create(['name' => 'example.read', 'guard_name' => 'web']);
    $store = new class($user) extends RecordingAuditStore
    {
        public function __construct(private User $user) {}

        public function record(AuthorityAudit $event, Connection $connection): void
        {
            parent::record($event, $connection);
            expect($this->user->fresh()->hasPermissionTo('example.read'))->toBeTrue();
            throw new RuntimeException('mandatory audit rollback');
        }
    };
    app()->instance(TransactionalAuditStore::class, $store);
    config(['permission-manager.audit.required' => true]);
    expect(fn () => app(RoleManagementService::class)->syncPermissions($admin, $role, [$permission->id]))->toThrow(RuntimeException::class, 'mandatory audit rollback');
    expect(DB::table('test_authority_audits')->count())->toBe(0);
    expect($user->fresh()->hasPermissionTo('example.read'))->toBeFalse();
});

it('keeps persisted authority and fresh cache coherent after membership and policy writes', function (string $kind, bool $rollback) {
    $admin = User::create(['name' => 'Admin']);
    $admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    $role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 20]);
    $user = User::create(['name' => 'Member']);
    $permission = Permission::create(['name' => 'example.read', 'guard_name' => 'web']);
    $policy = DelegationPolicy::create(['name' => 'Scope', 'slug' => 'scope', 'active' => true]);
    $role->delegationPolicy()->associate($policy);
    $role->save();
    if ($kind === 'membership') {
        $role->givePermissionTo($permission);
    } else {
        $user->assignRole($role);
    }
    expect($user->fresh()->hasPermissionTo('example.read'))->toBeFalse();
    Event::listen(AuthorityAudit::class, function () use ($user, $rollback) {
        $user->fresh()->hasPermissionTo('example.read');
        if ($rollback) {
            throw new RuntimeException('rollback');
        }
    });
    $operation = fn () => match ($kind) {
        'membership' => app(UserPermissionManagementService::class)->assignRole($admin, $user, $role->id),
        'direct' => app(UserPermissionManagementService::class)->grantDirectPermission($admin, $user, $permission->id),
        'role' => app(RoleManagementService::class)->syncPermissions($admin, $role, [$permission->id]),
        'policy' => app(DelegationPolicyService::class)->syncPermissions($admin, $policy, [$permission->id]),
    };
    if ($rollback) {
        expect($operation)->toThrow(RuntimeException::class, 'rollback');
    } else {
        $operation();
    }
    expect($user->fresh()->hasPermissionTo('example.read'))->toBe(! $rollback && $kind !== 'policy');
    $delegable = app(PermissionDelegationService::class)->delegablePermissionIds($user);
    expect($delegable)->toBe(! $rollback && $kind === 'policy' ? [$permission->id] : []);
})->with(['membership', 'direct', 'role', 'policy'])->with([true, false]);
