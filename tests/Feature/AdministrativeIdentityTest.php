<?php

use Ahinest\LaravelPermissionManager\Contracts\AdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\AuthorizedUserMutation;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Services\RoleHierarchy;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Support\UnrestrictedAdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;

it('lets a host restrict global identity across independent admin checks without a domain dependency', function () {
    $user = User::create(['name' => 'Not allowed by host']);
    $user->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    app()->instance(AdministrativeIdentity::class, new class extends UnrestrictedAdministrativeIdentity
    {
        public function allowsGlobalAdministration(Model $actor): bool
        {
            return false;
        }
    });
    $engine = app(PermissionDelegationService::class);
    config(['permission-manager.policy_manager_roles' => ['admin']]);
    expect($engine->isAdmin($user))->toBeFalse()->and($engine->snapshot($user)->admin)->toBeFalse()
        ->and($engine->isPolicyManager($user))->toBeFalse()->and($engine->snapshot($user)->policyManager)->toBeFalse()
        ->and(app(RoleHierarchy::class)->canManageRoleLevel($user, 1))->toBeFalse();
});

it('applies host role invariants before incremental replacement or compound persistence', function ($mode) {
    $actor = User::create(['name' => 'Actor']);
    $role = Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]);
    $actor->assignRole($role);
    $target = User::create(['name' => 'Target']);
    app()->instance(AdministrativeIdentity::class, new class extends UnrestrictedAdministrativeIdentity
    {
        public function assertRoles(Model $actor, ?Model $target, array $final): void
        {
            throw new AuthorizationException('Host identity invariant.');
        }
    });
    expect(fn () => match ($mode) {
        'incremental' => app(UserPermissionManagementService::class)->assignRole($actor, $target, $role->id),
        'replacement' => app(UserPermissionManagementService::class)->replaceRoles($actor, $target, [$role->id]),
        'compound' => app(AuthorizedUserMutation::class)->execute($actor, UserOperation::Update, $target, ['name' => 'Denied'], [$role->id]),
    })->toThrow(AuthorizationException::class);
    expect($target->fresh()->roles)->toBeEmpty()->and($target->fresh()->name)->toBe('Target');
})->with(['incremental', 'replacement', 'compound']);
