<?php

use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Ahinest\LaravelPermissionManager\Contracts\SensitiveRoleResolver;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

it('reloads dynamic sensitive identities through the host contract', function () {
    $resolver = new class implements SensitiveRoleResolver
    {
        public array $current = [];

        public function names(): array
        {
            return $this->current;
        }
    };
    app()->instance(SensitiveRoleResolver::class, $resolver);
    $actor = User::create(['name' => 'Ordinary']);
    $role = Role::create(['name' => 'dynamic', 'guard_name' => 'web', 'level' => 5]);
    $engine = app(PermissionDelegationService::class);
    $engine->assertCanAssignRole($actor, $role);
    $resolver->current = ['dynamic'];
    expect(fn () => $engine->assertCanAssignRole($actor, $role))->toThrow(AuthorizationException::class);
});

it('honors a host eligibility contract even for a global admin', function () {
    app()->bind(ActorEligibility::class, fn () => new class implements ActorEligibility
    {
        public function allows(Model $actor): bool
        {
            return false;
        }
    });
    $actor = User::create(['name' => 'Suspended elsewhere']);
    $actor->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    expect(fn () => app(RoleManagementService::class)->create($actor, ['name' => 'new', 'level' => 3]))->toThrow(AuthorizationException::class);
});

it('does not grant global authority when admin configuration is absent', function () {
    $actor = User::create(['name' => 'Legacy admin']);
    $actor->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    config(['permission-manager.admin_role' => null]);
    expect(app(PermissionDelegationService::class)->isAdmin($actor))->toBeFalse();
    expect(fn () => app(RoleManagementService::class)->create($actor, ['name' => 'new', 'level' => 3]))->toThrow(AuthorizationException::class);
});
