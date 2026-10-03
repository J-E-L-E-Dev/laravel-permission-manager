<?php

use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

function delegationPolicyFixture(array $ids, bool $active = true): DelegationPolicy
{
    $policy = DelegationPolicy::create(['name' => 'Test policy', 'slug' => fake()->uuid(), 'active' => $active]);
    $policy->permissions()->attach($ids);

    return $policy;
}

function delegationRoleFixture(?DelegationPolicy $policy = null, array $usage = [], ?string $name = null, int $level = 3): Role
{
    $role = Role::create(['name' => $name ?? fake()->uuid(), 'guard_name' => 'web', 'level' => $level, 'delegation_policy_id' => $policy?->id]);
    $role->permissions()->attach($usage);

    return $role;
}

function delegationExpectDecision(Closure $operation, bool $allowed): void
{
    if ($allowed) {
        $operation();
        expect(true)->toBeTrue();
    } else {
        expect($operation)->toThrow(AuthorizationException::class);
    }
}

beforeEach(function () {
    $this->service = app(PermissionDelegationService::class);
    $this->actor = User::create(['name' => 'Fixture']);
    $this->a = Permission::findOrCreate('a', 'web')->id;
    $this->b = Permission::findOrCreate('b', 'web')->id;
    $this->c = Permission::findOrCreate('c', 'web')->id;
});

it('gives exact web admin all current and subsequently created permissions without physical grants', function () {
    $admin = delegationRoleFixture(name: 'admin');
    $this->actor->assignRole($admin);
    $foreign = Permission::findOrCreate('foreign', 'other');
    expect($this->service->isAdmin($this->actor))->toBeTrue()
        ->and($this->service->isPolicyManager($this->actor))->toBeTrue()
        ->and($admin->delegation_policy_id)->toBeNull()
        ->and($this->service->delegablePermissionIds($this->actor))->toEqualCanonicalizing([$this->a, $this->b, $this->c]);
    $new = Permission::findOrCreate('new', 'web');
    $this->service->assertCanReplacePermissions($this->actor, [], [$new->id]);
    expect($this->service->delegablePermissions($this->actor)->modelKeys())->toContain($new->id)->not->toContain($foreign->id);
    $this->assertDatabaseCount('model_has_permissions', 0);
    $this->assertDatabaseCount('role_has_permissions', 0);
    $this->assertDatabaseCount('lpm_delegation_policies', 0);
});

it('does not recognize foreign guard or similar admin and manager identities', function ($name, $guard) {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $role = Role::create(['name' => $name, 'guard_name' => $guard]);
    // Raw pivot deliberately reproduces accidental multi-guard data Spatie normally prevents.
    $this->actor->roles()->attach($role);
    expect($this->service->isAdmin($this->actor))->toBeFalse()
        ->and($this->service->isPolicyManager($this->actor))->toBeFalse()
        ->and($this->service->delegablePermissionIds($this->actor))->toBe([]);
})->with([['admin', 'other'], ['manager', 'other'], ['admin', 'WEB'], ['Admin', 'web'], ['manager-extra', 'web']]);

it('computes D as a deduplicated union without direct or functional grants', function () {
    expect($this->service->delegablePermissionIds($this->actor))->toBe([]);
    $null = delegationRoleFixture(usage: [$this->c]);
    $inactive = delegationRoleFixture(delegationPolicyFixture([$this->c], false));
    $this->actor->assignRole($null, $inactive);
    $this->actor->givePermissionTo($this->c);
    expect($this->service->delegablePermissionIds($this->actor))->toBe([]);
    $firstPolicy = delegationPolicyFixture([$this->a, $this->b]);
    $firstPolicy->permissions()->attach(Permission::findOrCreate('foreign', 'other'));
    $this->actor->assignRole(delegationRoleFixture($firstPolicy));
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->b])));
    expect($this->service->delegablePermissionIds($this->actor))->toEqualCanonicalizing([$this->a, $this->b]);
});

it('checks complete permission replacement including revocation and retained outside permissions', function ($current, $final, $allowed, $admin) {
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a]), name: $admin ? 'admin' : null));
    $map = fn ($names) => array_map(fn ($name) => $this->{$name}, $names);
    delegationExpectDecision(fn () => $this->service->assertCanReplacePermissions($this->actor, $map($current), $map($final)), $admin || $allowed);
})->with([
    'grant inside' => [[], ['a'], true],
    'grant outside' => [[], ['b'], false],
    'revoke inside' => [['a'], [], true],
    'revoke outside' => [['b'], [], false],
    'mixed removal' => [['a', 'b'], ['a'], false],
    'mixed addition' => [['a'], ['a', 'b'], false],
    'retained outside' => [['a', 'b'], ['a', 'b'], false],
])->with([false, true]);

it('keeps omitted permission and role relations distinct from explicit empty replacements', function () {
    $role = delegationRoleFixture(usage: [$this->b]);
    $this->service->assertCanReplacePermissions($this->actor, [$this->b], [], provided: false);
    $this->service->assertCanReplaceRoles($this->actor, [$role->id], [], provided: false);
    expect(fn () => $this->service->assertCanReplacePermissions($this->actor, [$this->b], []))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->assertCanReplaceRoles($this->actor, [$role->id], []))->toThrow(AuthorizationException::class);
});

it('rejects nonexistent malformed and foreign permission IDs even for admin', function ($kind) {
    $this->actor->assignRole(delegationRoleFixture(name: 'admin'));
    $id = match ($kind) {
        'missing' => 999999,
        'foreign' => Permission::findOrCreate('foreign', 'other')->id,
        'string' => (string) $this->a,
    };
    expect(fn () => $this->service->assertCanReplacePermissions($this->actor, [], [$id]))->toThrow(ValidationException::class);
})->with(['missing', 'foreign', 'string']);

it('checks both role P and active Q independently', function ($usage, $scope, $active, $allowed) {
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a])));
    $role = delegationRoleFixture($scope === null ? null : delegationPolicyFixture([$this->{$scope}], $active), $usage === null ? [] : [$this->{$usage}]);
    $member = User::create(['name' => 'Fixture']);
    $member->assignRole($role);
    $member->givePermissionTo($this->c);
    expect($this->service->roleUsagePermissions($role)->modelKeys())->toBe($usage === null ? [] : [$this->{$usage}]);
    expect($this->service->roleDelegablePermissions($role)->modelKeys())->toBe($scope !== null && $active ? [$this->{$scope}] : []);
    delegationExpectDecision(fn () => $this->service->assertCanAssignRole($this->actor, $role), $allowed);
})->with([
    ['a', 'a', true, true], ['b', 'a', true, false], ['a', 'b', true, false],
    [null, 'b', true, false], ['a', 'b', false, true], ['a', null, false, true],
]);

it('evaluates final and removed roles including multirol replacements', function ($current, $final, $allowed) {
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a])));
    $roles = [
        'good' => delegationRoleFixture(usage: [$this->a]),
        'other' => delegationRoleFixture(delegationPolicyFixture([$this->a])),
        'badP' => delegationRoleFixture(usage: [$this->b]),
        'badQ' => delegationRoleFixture(delegationPolicyFixture([$this->b])),
    ];
    $ids = fn ($names) => array_map(fn ($name) => $roles[$name]->id, $names);
    delegationExpectDecision(fn () => $this->service->assertCanReplaceRoles($this->actor, $ids($current), $ids($final)), $allowed);
})->with([
    [[], ['good'], true], [[], ['badP'], false], [['good'], [], true], [['badP'], [], false],
    [['good'], ['other'], true], [['badQ'], ['good'], false],
    [['good', 'badQ'], ['good', 'other'], false], [['good'], ['good', 'badQ'], false],
    [['badP'], ['badP'], false], [['good', 'other'], ['other'], true],
]);

it('recognizes configured managers without increasing authority or creating identities', function () {
    $role = delegationRoleFixture(usage: [$this->b], name: 'manager');
    $this->actor->assignRole($role);
    expect($this->service->isPolicyManager($this->actor))->toBeFalse();
    config(['permission-manager.policy_manager_roles' => ['manager', 'not-created']]);
    expect($this->service->isPolicyManager($this->actor))->toBeTrue()
        ->and($this->service->isAdmin($this->actor))->toBeFalse()
        ->and($this->service->delegablePermissionIds($this->actor))->toBe([]);
    $this->assertDatabaseMissing('roles', ['name' => 'not-created']);
    $this->assertDatabaseCount('lpm_delegation_policies', 0);
});

it('requires manager status and authority over stored and final policy contents even inactive', function ($current, $final, $active, $manager, $admin, $allowed) {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a, $this->b]), name: $admin ? 'admin' : ($manager ? 'manager' : 'ordinary'), level: 2));
    $map = fn ($names) => array_map(fn ($name) => $this->{$name}, $names);
    $policy = delegationPolicyFixture($map($current), $active);
    delegationExpectDecision(fn () => $this->service->assertCanManagePolicyPermissions($this->actor, $policy, $map($final)), $allowed);
    expect($policy->fresh()->permissions->modelKeys())->toEqualCanonicalizing($map($current));
})->with([
    [['a'], ['a', 'b'], true, true, false, true],
    [['a'], ['c'], true, true, false, false],
    [['c'], ['c'], false, true, false, false],
    [['c'], [], true, true, false, false],
    [['c'], [], false, true, false, false],
    [['a'], ['a'], true, false, false, false],
    [['c'], ['a', 'b', 'c'], false, false, true, true],
]);

it('checks every shared policy consumer through unchanged RoleHierarchy', function ($blocked) {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a]), name: 'manager', level: 2));
    $shared = delegationPolicyFixture([$this->a]);
    delegationRoleFixture($shared, level: 3);
    delegationRoleFixture($shared, level: $blocked ? 1 : 4);
    delegationExpectDecision(fn () => $this->service->assertCanManagePolicyPermissions($this->actor, $shared, []), ! $blocked);
})->with([false, true]);

it('uses immutable pre-write authority for candidate self changes', function ($before, $after, $allowed, $admin) {
    $map = fn ($names) => array_map(fn ($name) => $this->{$name}, $names);
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture($map($before)), name: $admin ? 'admin' : null));
    $snapshot = $this->service->snapshot($this->actor);
    delegationExpectDecision(fn () => $this->service->assertNoSelfEscalation($snapshot, $map($after)), $admin || $allowed);
    expect($this->service->snapshot($this->actor)->permissionIds)->toBe($snapshot->permissionIds);
})->with([
    [['a'], ['a'], true], [['a', 'b'], ['a'], true], [['a'], ['a', 'b'], false], [[], ['a'], false],
])->with([false, true]);

it('rejects indirect self growth through shared policy or another role without persisting candidates', function ($via) {
    $policy = delegationPolicyFixture([$this->a]);
    $owned = delegationRoleFixture($policy);
    $other = delegationRoleFixture($via === 'shared' ? $policy : delegationPolicyFixture([$this->b]));
    $this->actor->assignRole($owned);
    $before = $this->service->snapshot($this->actor);
    $candidate = $via === 'shared' ? [$this->a, $this->b] : array_merge($before->permissionIds, $this->service->roleDelegablePermissions($other)->modelKeys());
    expect(fn () => $this->service->assertNoSelfEscalation($before, $candidate))->toThrow(AuthorizationException::class);
    expect($policy->fresh()->permissions->modelKeys())->toBe([$this->a]);
    expect($this->actor->fresh()->roles->modelKeys())->toBe([$owned->id]);
})->with(['shared', 'another-role']);

it('never upgrades a pre-write non-admin snapshot using newly loaded admin identity', function () {
    $before = $this->service->snapshot($this->actor);
    $this->actor->assignRole(delegationRoleFixture(name: 'admin'));
    expect(fn () => $this->service->assertNoSelfEscalation($before, [$this->a]))->toThrow(AuthorizationException::class);
});

it('blocks every own role policy change except admin including same smaller or null', function ($candidateType, $admin) {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $policy = delegationPolicyFixture([$this->a, $this->b]);
    $owned = delegationRoleFixture($policy, name: $admin ? 'admin' : 'manager', level: 2);
    $this->actor->assignRole($owned);
    $candidate = match ($candidateType) {
        'same' => $policy,
        'equal' => delegationPolicyFixture([$this->a, $this->b]),
        'smaller' => delegationPolicyFixture([$this->a]),
        'null' => null,
    };
    delegationExpectDecision(fn () => $this->service->assertCanChangeRolePolicy($this->actor, $owned, $candidate), $admin);
    expect($owned->fresh()->delegation_policy_id)->toBe($policy->id);
})->with(['same', 'equal', 'smaller', 'null'])->with([false, true]);

it('validates active candidates and authority over incoming and outgoing policy contents', function ($current, $candidate, $allowed) {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a]), name: 'manager', level: 2));
    $old = $current ? delegationPolicyFixture([$this->{$current}], false) : null;
    $new = $candidate ? delegationPolicyFixture([$this->{$candidate}]) : null;
    delegationExpectDecision(fn () => $this->service->assertPolicyAssignable($this->actor, $old, $new), $allowed);
})->with([[null, 'a', true], ['a', null, true], ['b', null, false], [null, 'b', false], ['b', 'a', false]]);

it('rejects an inactive candidate even for admin and reloads its active flag', function ($admin) {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $this->actor->assignRole(delegationRoleFixture(name: $admin ? 'admin' : 'manager'));
    $candidate = delegationPolicyFixture([]);
    DelegationPolicy::whereKey($candidate)->update(['active' => false]);
    expect(fn () => $this->service->assertPolicyAssignable($this->actor, null, $candidate))->toThrow(ValidationException::class);
})->with([false, true]);

it('reserves exact manager names even before provisioning and forbids non-admin renames', function () {
    config(['permission-manager.policy_manager_roles' => ['future-manager']]);
    expect($this->service->reservedRoleNames())->toBe(['admin', 'future-manager'])
        ->and($this->service->isReservedRoleName('future-manager'))->toBeTrue()
        ->and($this->service->isReservedRoleName('future-manager-extra'))->toBeFalse();
    $ordinary = delegationRoleFixture(name: 'ordinary');
    foreach (['admin', 'future-manager', 'renamed'] as $name) {
        expect(fn () => $this->service->assertCanUseRoleIdentity($this->actor, $ordinary, $name))->toThrow(AuthorizationException::class);
    }
    expect(fn () => $this->service->assertCanUseRoleIdentity($this->actor, null, 'future-manager'))->toThrow(AuthorizationException::class);
    $this->service->assertCanUseRoleIdentity($this->actor, $ordinary, 'ordinary');
    $this->service->assertCanUseRoleIdentity($this->actor, null, 'future-manager-extra');
    $this->actor->assignRole(delegationRoleFixture(name: 'admin'));
    $this->service->assertCanUseRoleIdentity($this->actor, $ordinary, 'future-manager');
});

it('blocks qualitative ticket documentation and configured sensitive identities even with empty P and Q', function ($name) {
    if ($name === 'docs-reader') {
        config(['permission-manager.sensitive_role_names' => [$name]]);
    }
    if ($name === 'custom-sensitive') {
        config(['permission-manager.sensitive_role_names' => ['case-supervisor', 'case-support', $name]]);
    }
    $role = delegationRoleFixture(name: $name);
    expect($this->service->sensitiveRoleNames())->toContain($name);
    expect(fn () => $this->service->assertCanAssignRole($this->actor, $role))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->assertCanReplaceRoles($this->actor, [$role->id], []))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->assertCanUseRoleIdentity($this->actor, null, $name))->toThrow(AuthorizationException::class);
})->with(['case-supervisor', 'case-support', 'docs-reader', 'custom-sensitive']);

it('checks direct P Q multirol and reserved account authority for control and deletion', function ($kind, $allowed, $admin) {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a]), name: $admin ? 'admin' : null));
    $target = User::create(['name' => 'Fixture']);
    match ($kind) {
        'inside' => $target->givePermissionTo($this->a),
        'direct' => $target->givePermissionTo($this->b),
        'P' => $target->assignRole(delegationRoleFixture(usage: [$this->b])),
        'Q' => $target->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->b]))),
        'multirol' => $target->assignRole(delegationRoleFixture(usage: [$this->a]), delegationRoleFixture(delegationPolicyFixture([$this->b]))),
        'admin' => $target->assignRole(Role::findOrCreate('admin', 'web')),
        'manager' => $target->assignRole(delegationRoleFixture(name: 'manager')),
        'case-support' => $target->assignRole(delegationRoleFixture(name: 'case-support')),
    };
    delegationExpectDecision(fn () => $this->service->assertCanControlAccount($this->actor, $target), $admin || $allowed);
    delegationExpectDecision(fn () => $this->service->assertCanDeleteAccount($this->actor, $target), $admin || $allowed);
    if ($kind === 'multirol') {
        expect($this->service->targetAuthorityFootprint($target)['permissionIds'])->toEqualCanonicalizing([$this->a, $this->b]);
    }
    expect($target->fresh())->not->toBeNull();
})->with([['inside', true], ['direct', false], ['P', false], ['Q', false], ['multirol', false], ['admin', false], ['manager', false], ['case-support', false]])->with([false, true]);

it('reloads actor memberships policy linkage contents and active status despite loaded relations', function () {
    $first = delegationPolicyFixture([$this->a]);
    $role = delegationRoleFixture($first);
    $this->actor->assignRole($role);
    $this->actor->load('roles.permissions', 'roles.delegationPolicy.permissions');
    expect($this->service->delegablePermissionIds($this->actor))->toBe([$this->a]);
    $first->permissions()->sync([$this->b]);
    expect($this->service->delegablePermissionIds($this->actor))->toBe([$this->b]);
    $first->update(['active' => false]);
    expect($this->service->delegablePermissionIds($this->actor))->toBe([]);
    $second = delegationPolicyFixture([$this->c]);
    Role::whereKey($role)->update(['delegation_policy_id' => $second->id]);
    expect($this->service->delegablePermissionIds($this->actor))->toBe([$this->c]);
    $this->actor->roles()->detach();
    expect($this->service->delegablePermissionIds($this->actor))->toBe([]);
});

it('fails closed for foreign guard roles and malformed memberships during transfers', function () {
    $this->actor->assignRole(delegationRoleFixture(name: 'admin'));
    $foreign = Permission::findOrCreate('foreign', 'other');
    $role = delegationRoleFixture(usage: [$foreign->id]);
    expect(fn () => $this->service->assertCanAssignRole($this->actor, $role))->toThrow(ValidationException::class);
    $policy = delegationPolicyFixture([$foreign->id]);
    expect(fn () => $this->service->assertCanManagePolicyPermissions($this->actor, $policy, []))->toThrow(ValidationException::class);
    $other = Role::create(['name' => 'foreign', 'guard_name' => 'other']);
    expect(fn () => $this->service->assertCanAssignRole($this->actor, $other))->toThrow(ValidationException::class);
});

it('exhaustively preserves C union F and self monotonicity for subsets of three permissions', function () {
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a, $this->b])));
    $snapshot = $this->service->snapshot($this->actor);
    $sets = [];
    for ($mask = 0; $mask < 8; $mask++) {
        $sets[] = array_values(array_filter([$this->a, $this->b, $this->c], fn ($id, $index) => ($mask & (1 << $index)) !== 0, ARRAY_FILTER_USE_BOTH));
    }
    foreach ($sets as $current) {
        foreach ($sets as $final) {
            $allowed = array_diff(array_merge($current, $final), $snapshot->permissionIds) === [];
            delegationExpectDecision(fn () => $this->service->assertCanReplacePermissions($this->actor, $current, $final), $allowed);
        }
        delegationExpectDecision(fn () => $this->service->assertNoSelfEscalation($snapshot, $current), array_diff($current, $snapshot->permissionIds) === []);
    }
});

it('preserves P union Q containment for every pair of singleton or empty sets', function () {
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a])));
    foreach ([[], [$this->a], [$this->b]] as $p) {
        foreach ([[], [$this->a], [$this->b]] as $q) {
            $role = delegationRoleFixture(delegationPolicyFixture($q), $p);
            delegationExpectDecision(fn () => $this->service->assertCanAssignRole($this->actor, $role), array_diff(array_merge($p, $q), [$this->a]) === []);
        }
    }
});

it('allows a manager to change another manageable role policy without writing it', function () {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$this->a]), name: 'manager', level: 2));
    $old = delegationPolicyFixture([$this->a]);
    $role = delegationRoleFixture($old, level: 3);
    $candidate = delegationPolicyFixture([$this->a]);
    $this->service->assertCanChangeRolePolicy($this->actor, $role, $candidate);
    expect($role->fresh()->delegation_policy_id)->toBe($old->id);
    $higher = delegationRoleFixture($old, level: 1);
    expect(fn () => $this->service->assertCanChangeRolePolicy($this->actor, $higher, $candidate))->toThrow(AuthorizationException::class);
});

it('rejects ambiguous foreign guard names before composing the existing hierarchy', function () {
    config(['permission-manager.policy_manager_roles' => ['manager']]);
    Role::create(['name' => 'manager', 'guard_name' => 'other', 'level' => 1]);
    $this->actor->assignRole(delegationRoleFixture(name: 'manager', level: 3));
    $policy = delegationPolicyFixture([]);
    delegationRoleFixture($policy, level: 2);
    expect(fn () => $this->service->assertCanManagePolicyPermissions($this->actor, $policy, []))->toThrow(ValidationException::class);
});

it('reloads target footprint and qualitative ACL identities on each check', function () {
    $target = User::create(['name' => 'Fixture']);
    $role = delegationRoleFixture(name: 'dynamic-reader');
    $target->assignRole($role);
    $target->load('roles.permissions', 'permissions');
    $this->service->assertCanControlAccount($this->actor, $target);
    $target->permissions()->attach($this->b);
    expect(fn () => $this->service->assertCanControlAccount($this->actor, $target))->toThrow(AuthorizationException::class);
    $target->permissions()->detach();
    config(['permission-manager.sensitive_role_names' => ['dynamic-reader']]);
    expect(fn () => $this->service->assertCanControlAccount($this->actor, $target))->toThrow(AuthorizationException::class);
    expect($this->service->targetAuthorityFootprint($target)['sensitiveRoleNames'])->toBe(['dynamic-reader']);
});

it('rejects nonexistent roles policies and unpersisted actors without treating them as admin', function () {
    $this->actor->assignRole(delegationRoleFixture(name: 'admin'));
    expect(fn () => $this->service->snapshot(new User))->toThrow(AuthorizationException::class);
    expect(fn () => $this->service->assertCanAssignRole($this->actor, new Role))->toThrow(ValidationException::class);
    expect(fn () => $this->service->assertPolicyAssignable($this->actor, null, new DelegationPolicy))->toThrow(ValidationException::class);
    expect(fn () => $this->service->assertCanReplaceRoles($this->actor, [], [999999]))->toThrow(ValidationException::class);
    $role = delegationRoleFixture();
    expect(fn () => $this->service->assertCanReplaceRoles($this->actor, [$role->id], [(string) $role->id]))->toThrow(ValidationException::class);
});

it('checks exact permission guards even when the database uses case insensitive comparison', function () {
    $foreign = Permission::findOrCreate('upper-guard', 'WEB');
    $this->actor->assignRole(delegationRoleFixture(delegationPolicyFixture([$foreign->id])));
    expect($this->service->delegablePermissionIds($this->actor))->toBe([]);
    $this->actor->assignRole(delegationRoleFixture(name: 'admin'));
    expect($this->service->delegablePermissionIds($this->actor))->not->toContain($foreign->id);
    expect(fn () => $this->service->assertCanReplacePermissions($this->actor, [], [$foreign->id]))->toThrow(ValidationException::class);
});
