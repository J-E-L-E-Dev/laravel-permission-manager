<?php

use Ahinest\LaravelPermissionManager\Authorization\RbacMutationAuthorization;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\DelegationPolicyService;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

function writeState(): array
{
    return collect(['users', 'roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'lpm_delegation_policies', 'lpm_policy_permission'])
        ->mapWithKeys(fn ($table) => [$table => DB::table($table)->orderByRaw('1')->get()->toArray()])->all();
}

beforeEach(function () {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $this->inside = Permission::create(['name' => 'inside', 'guard_name' => 'web']);
    $this->outside = Permission::create(['name' => 'outside', 'guard_name' => 'web']);
    $entry = Permission::create(['name' => 'host.access.manage', 'guard_name' => 'web']);
    config(['permission-manager.abilities' => array_fill_keys(['roles.create', 'roles.update', 'roles.permissions', 'users.roles', 'users.permissions', 'accounts.control'], [$entry->name])]);
    $this->scope = DelegationPolicy::create(['name' => 'Scope', 'slug' => 'scope', 'active' => true]);
    $this->scope->permissions()->attach([$this->inside->id]);
    $this->operatorRole = Role::create(['name' => 'manager', 'guard_name' => 'web', 'level' => 2, 'delegation_policy_id' => $this->scope->id]);
    $this->actor = User::create(['name' => 'Operator']);
    $this->actor->assignRole($this->operatorRole);
    $this->actor->givePermissionTo($entry);
    $this->admin = User::create(['name' => 'Admin']);
    $this->admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    $this->target = User::create(['name' => 'Target']);
    $this->role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 3]);
    $this->target->assignRole($this->role);
    $this->users = app(UserPermissionManagementService::class);
    $this->roles = app(RoleManagementService::class);
    $this->policies = app(DelegationPolicyService::class);
});

it('assigns and revokes multiple existing roles without modifying the host account or catalog', function () {
    $second = $this->roles->create($this->actor, ['name' => 'second', 'level' => 5]);
    $before = $this->target->fresh()->getAttributes();
    $catalog = Permission::all()->toArray();
    $this->users->replaceRoles($this->actor, $this->target, [$this->role->id, $second->id]);
    expect($this->target->fresh()->roles->modelKeys())->toEqualCanonicalizing([$this->role->id, $second->id]);
    $this->users->replaceRoles($this->actor, $this->target, []);
    expect($this->target->fresh()->roles)->toHaveCount(0)
        ->and($this->target->fresh()->getAttributes())->toBe($before)->and(Permission::all()->toArray())->toBe($catalog);
});

it('requires functional entry independently of D and policy manager status', function ($operation) {
    $this->actor->syncPermissions([]);
    $before = writeState();
    $call = match ($operation) {
        'roles' => fn () => $this->users->replaceRoles($this->actor, $this->target, []),
        'permissions' => fn () => $this->users->replaceDirectPermissions($this->actor, $this->target, [$this->inside->id]),
        'create' => fn () => $this->roles->create($this->actor, ['name' => 'new', 'level' => 4]),
        'update' => fn () => $this->roles->update($this->actor, $this->role, ['level' => 4]),
        'sync' => fn () => $this->roles->syncPermissions($this->actor, $this->role, [$this->inside->id]),
    };
    expect($call)->toThrow(AuthorizationException::class)->and(writeState())->toEqual($before);
})->with(['roles', 'permissions', 'create', 'update', 'sync']);

it('checks removed direct permission and role authority before any mutation', function ($kind) {
    if ($kind === 'direct') {
        $this->target->givePermissionTo($this->outside);
    } else {
        $this->role->givePermissionTo($this->outside);
    }
    $before = writeState();
    expect(fn () => $this->users->replaceDirectPermissions($this->actor, $this->target, []))->toThrow(AuthorizationException::class)
        ->and(fn () => $this->users->replaceRoles($this->actor, $this->target, []))->toThrow(AuthorizationException::class)
        ->and(writeState())->toEqual($before);
})->with(['direct', 'role']);

it('does not turn inherited permissions into direct permissions', function () {
    $this->role->givePermissionTo($this->inside);
    $this->users->replaceDirectPermissions($this->actor, $this->target, [$this->inside->id]);
    $this->users->replaceDirectPermissions($this->actor, $this->target, []);
    expect($this->target->fresh()->permissions)->toBeEmpty()->and($this->target->fresh()->hasPermissionTo($this->inside))->toBeTrue();
});

it('cannot strip a sensitive identity and subsequently control its account', function () {
    $this->target->assignRole($this->operatorRole);
    $before = writeState();
    expect(fn () => $this->users->replaceRoles($this->actor, $this->target, [$this->role->id]))->toThrow(AuthorizationException::class);
    expect(fn () => app(RbacMutationAuthorization::class)->accountControl($this->actor, $this->target))->toThrow(AuthorizationException::class);
    expect(writeState())->toEqual($before);
});

it('checks every member before changing a shared role', function ($operation, $kind) {
    if ($kind === 'admin') {
        $this->admin->assignRole($this->role);
    } else {
        $this->target->givePermissionTo($this->outside);
    }
    $before = writeState();
    $call = $operation === 'level'
        ? fn () => $this->roles->update($this->actor, $this->role, ['level' => 4])
        : fn () => $this->roles->syncPermissions($this->actor, $this->role, [$this->inside->id]);
    expect($call)->toThrow(AuthorizationException::class)->and(writeState())->toEqual($before);
})->with(['level', 'permissions'])->with(['admin', 'outside']);

it('prevents owned role level growth with same-role management enabled', function () {
    config(['permission-manager.levels.allow_same_owned_role' => true]);
    $before = writeState();
    expect(fn () => $this->roles->update($this->actor, $this->operatorRole, ['level' => 1]))->toThrow(AuthorizationException::class)
        ->and(writeState())->toEqual($before);
});

it('denies unknown and foreign permission IDs even for admin', function ($id) {
    $permission = $id === 'foreign' ? Permission::create(['name' => 'foreign', 'guard_name' => 'other'])->id : 999999;
    $before = writeState();
    expect(fn () => $this->users->replaceDirectPermissions($this->admin, $this->target, [$permission]))->toThrow(ValidationException::class)
        ->and(writeState())->toEqual($before);
})->with(['foreign', 'missing']);

it('revalidates loaded authority after scope revocation', function () {
    $this->actor->load('roles.delegationPolicy.permissions', 'permissions');
    $this->scope->permissions()->detach();
    $before = writeState();
    expect(fn () => $this->users->replaceDirectPermissions($this->actor, $this->target, [$this->inside->id]))->toThrow(AuthorizationException::class)
        ->and(writeState())->toEqual($before);
});

it('rolls back a partial role permission sync after persistence fails', function () {
    $this->role->givePermissionTo($this->inside);
    $before = writeState();
    DB::unprepared("CREATE TRIGGER reject_role_permission BEFORE INSERT ON role_has_permissions BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    try {
        expect(fn () => $this->roles->syncPermissions($this->admin, $this->role, [$this->outside->id]))->toThrow(QueryException::class);
        expect(writeState())->toEqual($before);
    } finally {
        DB::unprepared('DROP TRIGGER reject_role_permission');
    }
});

it('rolls back metadata and activation when saving fails', function () {
    $before = writeState();
    Event::listen('eloquent.saved: '.DelegationPolicy::class, fn () => throw new RuntimeException('fixture failure'));
    try {
        expect(fn () => $this->policies->update($this->admin, $this->scope, ['name' => 'Changed', 'active' => false]))->toThrow(RuntimeException::class);
        expect(writeState())->toEqual($before);
    } finally {
        Event::forget('eloquent.saved: '.DelegationPolicy::class);
    }
});

it('requires the coordination row and never silently recreates it', function () {
    DB::table('lpm_write_locks')->delete();
    $before = writeState();
    expect(fn () => $this->users->replaceRoles($this->admin, $this->target, []))->toThrow(LogicException::class)
        ->and(writeState())->toEqual($before);
});

it('protects last admin removal but allows removal with another eligible admin', function () {
    expect(fn () => $this->users->replaceRoles($this->admin, $this->admin, []))->toThrow(ValidationException::class);
    $this->users->replaceRoles($this->admin, $this->target, [$this->admin->roles()->first()->id]);
    $this->users->replaceRoles($this->admin, $this->admin, []);
    expect(app(PermissionDelegationService::class)->isAdmin($this->admin))->toBeFalse()
        ->and(app(PermissionDelegationService::class)->isAdmin($this->target))->toBeTrue();
});

it('does not permit renaming the global admin identity', function () {
    expect(fn () => $this->roles->update($this->admin, $this->admin->roles()->first(), ['name' => 'ordinary']))->toThrow(ValidationException::class);
});

it('blocks authority laundering through sequential role and policy operations', function () {
    $initial = app(PermissionDelegationService::class)->delegablePermissionIds($this->actor);
    $role = $this->roles->create($this->actor, ['name' => 'bridge', 'level' => 4]);
    $this->roles->syncPermissions($this->actor, $role, [$this->inside->id]);
    $policy = $this->policies->create($this->actor, ['name' => 'Bridge', 'slug' => 'bridge']);
    expect($policy->active)->toBeFalse();
    $this->policies->syncPermissions($this->actor, $policy, [$this->inside->id]);
    $this->policies->update($this->actor, $policy, ['active' => true]);
    $this->policies->assignToRole($this->actor, $role, $policy->id);
    $this->users->replaceRoles($this->actor, $this->target, [$role->id]);
    $before = writeState();
    expect(fn () => $this->policies->syncPermissions($this->actor, $policy, [$this->inside->id, $this->outside->id]))->toThrow(AuthorizationException::class);
    expect(fn () => $this->policies->assignToRole($this->actor, $this->operatorRole, $policy->id))->toThrow(AuthorizationException::class);
    expect(fn () => $this->users->replaceRoles($this->actor, $this->actor, [$role->id]))->toThrow(AuthorizationException::class);
    expect(writeState())->toEqual($before)->and(app(PermissionDelegationService::class)->delegablePermissionIds($this->actor))->toBe($initial);
});

it('rechecks new consumers before policy changes and keeps dormant contents protected', function ($operation) {
    $policy = $this->policies->create($this->actor, ['name' => 'Shared', 'slug' => 'shared', 'active' => true, 'permissions' => [$this->inside->id]]);
    $this->policies->assignToRole($this->actor, $this->role, $policy->id);
    $this->admin->assignRole($this->role);
    $before = writeState();
    $call = match ($operation) {
        'sync' => fn () => $this->policies->syncPermissions($this->actor, $policy, []),
        'active' => fn () => $this->policies->update($this->actor, $policy, ['active' => false]),
        'unlink' => fn () => $this->policies->assignToRole($this->actor, $this->role, null),
    };
    expect($call)->toThrow(AuthorizationException::class)->and(writeState())->toEqual($before);
})->with(['sync', 'active', 'unlink']);

it('allows metadata alone but denies activation and erasure of dormant outside authority', function () {
    $policy = $this->policies->create($this->admin, ['name' => 'Dormant', 'slug' => 'dormant', 'permissions' => [$this->outside->id]]);
    $this->policies->update($this->actor, $policy, ['name' => 'Label']);
    $before = writeState();
    expect(fn () => $this->policies->update($this->actor, $policy, ['active' => true]))->toThrow(AuthorizationException::class);
    expect(fn () => $this->policies->syncPermissions($this->actor, $policy, []))->toThrow(AuthorizationException::class);
    expect(fn () => $this->policies->assignToRole($this->admin, $this->role, $policy->id))->toThrow(ValidationException::class);
    expect(writeState())->toEqual($before);
});

it('rejects reserved identities before provisioning and input aliases', function ($name) {
    config(['permission-manager.reserved_role_names' => ['future-owner']]);
    expect(fn () => $this->roles->create($this->actor, ['name' => $name, 'level' => 4]))->toThrow(in_array($name, ['Admin', ' admin ']) ? ValidationException::class : AuthorizationException::class);
    expect(Role::where('name', $name)->count())->toBe($name === 'admin' ? 1 : 0);
})->with(['future-owner', 'Admin', ' admin ']);
