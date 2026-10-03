<?php

use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Ahinest\LaravelPermissionManager\Contracts\UserAccountWriter;
use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\AuthorizedUserMutation;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Schema::table('users', fn ($table) => $table->boolean('active')->default(true));
    app()->bind(UserAccountWriter::class, fn () => new class implements UserAccountWriter
    {
        public function persist(Model $target, UserOperation $operation, array $attributes): void
        {
            if ($operation === UserOperation::Delete) {
                $target->delete();

                return;
            }
            $target->fill(collect($attributes)->only(['name', 'active'])->all());
            if ($operation === UserOperation::Suspend) {
                $target->active = false;
            }
            if ($operation === UserOperation::Reactivate) {
                $target->active = true;
            }
            $target->save();
        }
    });
    $this->admin = User::create(['name' => 'Admin']);
    $this->adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]);
    $this->admin->assignRole($this->adminRole);
    $this->role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 5]);
    $this->mutations = app(AuthorizedUserMutation::class);
});

it('creates and updates host attributes with authority atomically', function () {
    $permission = Permission::create(['name' => 'feature', 'guard_name' => 'web']);
    $user = $this->mutations->execute($this->admin, UserOperation::Create, null, ['name' => 'Created'], [$this->role->id], [$permission->id]);
    expect($user->name)->toBe('Created')->and($user->roles->modelKeys())->toBe([$this->role->id])->and($user->permissions->modelKeys())->toBe([$permission->id]);
    $user = $this->mutations->execute($this->admin, UserOperation::Update, $user, ['name' => 'Edited'], [], []);
    expect($user->name)->toBe('Edited')->and($user->roles)->toBeEmpty()->and($user->permissions)->toBeEmpty();
});

it('does not create an account for invalid authority', function () {
    expect(fn () => $this->mutations->execute($this->admin, UserOperation::Create, null, ['name' => 'Denied'], [999]))->toThrow(ValidationException::class);
    expect(User::count())->toBe(1);
});

it('rolls back account and pivots on failure after account persistence', function () {
    DB::statement("CREATE TRIGGER fail_membership BEFORE INSERT ON model_has_roles BEGIN SELECT RAISE(ABORT, 'fixture failure'); END");
    expect(fn () => $this->mutations->execute($this->admin, UserOperation::Create, null, ['name' => 'Failed'], [$this->role->id]))->toThrow(QueryException::class);
    expect(User::count())->toBe(1)->and(DB::table('model_has_roles')->count())->toBe(1);
});

it('preserves the last eligible administrator through every account mutation', function ($operation, $attributes, $roles) {
    $other = User::create(['name' => 'Inactive', 'active' => false]);
    $other->assignRole($this->adminRole);
    expect(fn () => $this->mutations->execute($this->admin, $operation, $this->admin, $attributes, $roles ? [] : null))->toThrow(ValidationException::class);
    expect($this->admin->fresh()->active)->toBe(1)->and($this->admin->fresh()->roles->modelKeys())->toBe([$this->adminRole->id]);
})->with([
    [UserOperation::Suspend, [], false], [UserOperation::Delete, [], false],
    [UserOperation::Update, ['active' => false], false], [UserOperation::Update, [], true],
]);

it('respects custom eligibility when counting administrator candidates', function () {
    app()->bind(ActorEligibility::class, fn () => new class implements ActorEligibility
    {
        public function allows(Model $actor): bool
        {
            return $actor->name === 'Admin';
        }
    });
    $other = User::create(['name' => 'Not eligible']);
    $other->assignRole($this->adminRole);
    expect(fn () => $this->mutations->execute($this->admin, UserOperation::Update, $this->admin, ['name' => 'Changed']))->toThrow(ValidationException::class);
    expect($this->admin->fresh()->name)->toBe('Admin');
});

it('preserves assignments on suspension and reactivation with another eligible admin', function () {
    $other = User::create(['name' => 'Second']);
    $other->assignRole($this->adminRole);
    $result = $this->mutations->execute($this->admin, UserOperation::Suspend, $other, []);
    expect($result->active)->toBe(0)->and($result->roles->modelKeys())->toBe([$this->adminRole->id]);
    expect(fn () => $this->mutations->execute($other, UserOperation::Update, $this->admin, ['name' => 'Attack']))->toThrow(AuthorizationException::class);
    expect($this->mutations->execute($this->admin, UserOperation::Reactivate, $other, [])->active)->toBe(1);
});

it('checks the pre-mutation footprint before stripping authority and editing credentials', function () {
    $entry = Permission::create(['name' => 'entry', 'guard_name' => 'web']);
    $outside = Permission::create(['name' => 'outside', 'guard_name' => 'web']);
    config(['permission-manager.abilities' => array_fill_keys(['users.create', 'users.update', 'users.roles'], ['entry'])]);
    $actor = User::create(['name' => 'Operator']);
    $actor->assignRole(Role::create(['name' => 'operator', 'guard_name' => 'web', 'level' => 2]));
    $actor->givePermissionTo($entry);
    $target = User::create(['name' => 'Protected']);
    $target->assignRole($this->role);
    $target->givePermissionTo($outside);
    expect(fn () => $this->mutations->execute($actor, UserOperation::Update, $target, ['name' => 'Captured'], []))->toThrow(AuthorizationException::class);
    expect($target->fresh()->name)->toBe('Protected');
    $this->role->givePermissionTo($outside);
    expect(fn () => $this->mutations->execute($actor, UserOperation::Create, null, ['name' => 'Proxy'], [$this->role->id]))->toThrow(AuthorizationException::class);
    expect(User::count())->toBe(3);
});

it('performs incremental writes from fresh state and validates absent removal ids', function () {
    $target = User::create(['name' => 'Target']);
    $target->load('roles', 'permissions');
    $service = app(UserPermissionManagementService::class);
    $p = Permission::create(['name' => 'feature', 'guard_name' => 'web']);
    $service->assignRole($this->admin, $target, $this->role->id);
    $service->grantDirectPermission($this->admin, $target, $p->id);
    $service->assignRole($this->admin, $target, $this->role->id);
    expect($target->fresh()->roles)->toHaveCount(1);
    $service->removeRole($this->admin, $target, $this->role->id);
    $service->revokeDirectPermission($this->admin, $target, $p->id);
    expect($target->fresh()->roles)->toBeEmpty()->and($target->fresh()->permissions)->toBeEmpty();
    expect(fn () => $service->revokeDirectPermission($this->admin, $target, 999))->toThrow(ValidationException::class);
});
