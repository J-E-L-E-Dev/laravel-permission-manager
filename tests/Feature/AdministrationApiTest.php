<?php

use Ahinest\LaravelPermissionManager\Http\RequireActor;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\DelegationPolicyProvisioningService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    require __DIR__.'/../../routes/api.php';
    $this->admin = User::create(['name' => 'Admin']);
    $this->admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    $this->role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 4]);
    $this->target = User::create(['name' => 'Target']);
    $this->base = '/api/v1/permission-manager';
});
it('authenticates reads and requires explicit functional entry', function () {
    $this->getJson($this->base.'/roles')->assertUnauthorized();
    $this->actingAs($this->target)->getJson($this->base.'/roles')->assertForbidden();
    $this->actingAs($this->admin)->getJson($this->base.'/roles')->assertOk()->assertJsonCount(2, 'data');
    expect($this->getJson($this->base.'/capabilities')->assertOk()->json('data')['users.create'])->toBeTrue();
});
it('uses domain writers for HTTP role and membership operations', function () {
    $this->actingAs($this->admin);
    $p = Permission::create(['name' => 'feature', 'guard_name' => 'web']);
    $role = $this->postJson($this->base.'/roles', ['name' => 'created', 'level' => 5])->assertCreated()->json('data.id');
    $this->putJson($this->base.'/roles/'.$role.'/permissions', ['ids' => [$p->id]])->assertOk();
    $this->putJson($this->base.'/users/'.$this->target->id.'/roles/'.$role)->assertOk();
    $this->getJson($this->base.'/users/'.$this->target->id.'/authority')->assertOk()->assertJsonCount(1, 'data.inherited_permissions');
    $this->putJson($this->base.'/users/'.$this->target->id.'/permissions/'.$p->id)->assertOk();
    $this->deleteJson($this->base.'/users/'.$this->target->id.'/permissions/'.$p->id)->assertOk();
    $this->deleteJson($this->base.'/users/'.$this->target->id.'/roles/'.$role)->assertOk();
    $this->putJson($this->base.'/users/'.$this->admin->id.'/roles', ['ids' => []])->assertUnprocessable();
    $this->putJson($this->base.'/roles/999', ['description' => 'missing'])->assertNotFound();
    $this->putJson($this->base.'/users/'.$this->target->id.'/permissions', ['ids' => [999]])->assertUnprocessable();
    $this->postJson($this->base.'/permissions', ['name' => 'forbidden'])->assertStatus(405);
});
it('supports policy lifecycle without implicit grants', function () {
    $this->actingAs($this->admin);
    $policy = $this->postJson($this->base.'/policies', ['name' => 'Empty', 'slug' => 'empty'])->assertCreated()->json('data.id');
    $this->putJson($this->base.'/policies/'.$policy, ['active' => true])->assertOk();
    $this->getJson($this->base.'/policies/'.$policy.'/assignable-roles')->assertOk();
    $this->putJson($this->base.'/roles/'.$this->role->id.'/delegation-policy', ['delegation_policy_id' => $policy])->assertOk();
    $this->getJson($this->base.'/policies')->assertOk()->assertJsonCount(0, 'data.0.permissions');
});
it('separates contextual candidates from the catalog and revalidates stale authority', function () {
    $entry = Permission::create(['name' => 'entry', 'guard_name' => 'web']);
    $scopePermission = Permission::create(['name' => 'scope', 'guard_name' => 'web']);
    config(['permission-manager.abilities' => ['users.roles' => ['entry']]]);
    $policy = DelegationPolicy::create(['name' => 'Scope', 'slug' => 'scope', 'active' => true]);
    $policy->permissions()->attach($scopePermission);
    $operator = User::create(['name' => 'Operator']);
    $operator->assignRole(Role::create(['name' => 'operator', 'guard_name' => 'web', 'level' => 2, 'delegation_policy_id' => $policy->id]));
    $operator->givePermissionTo($entry);
    $this->role->givePermissionTo($scopePermission);
    $this->actingAs($operator)->getJson($this->base.'/roles')->assertForbidden();
    $this->getJson($this->base.'/users/'.$this->target->id.'/assignable-roles')->assertOk()->assertJsonPath('data.0.id', $this->role->id);
    $policy->permissions()->detach();
    $this->putJson($this->base.'/users/'.$this->target->id.'/roles/'.$this->role->id)->assertForbidden();
    expect($this->target->fresh()->roles)->toBeEmpty();
});
it('rejects foreign guard identities and hierarchy abuse through HTTP', function () {
    $foreign = Role::create(['name' => 'foreign', 'guard_name' => 'other', 'level' => 5]);
    $this->actingAs($this->admin)->getJson($this->base.'/roles')->assertOk()->assertJsonCount(2, 'data');
    $this->putJson($this->base.'/users/'.$this->target->id.'/roles', ['ids' => [$foreign->id]])->assertUnprocessable();
    $this->postJson($this->base.'/roles', ['name' => 'bad', 'level' => 3, 'guard_name' => 'other'])->assertUnprocessable();
});
it('plans provisioning then applies idempotently while preserving managed content', function () {
    config(['permission-manager.provisioning' => ['member']]);
    $service = app(DelegationPolicyProvisioningService::class);
    expect($service->run(true)['rows'][0]['action'])->toBe('create-and-link')->and(DelegationPolicy::count())->toBe(0);
    expect($service->run()['applied'])->toBeTrue();
    $policy = $this->role->fresh()->delegationPolicy;
    $p = Permission::create(['name' => 'existing', 'guard_name' => 'web']);
    $policy->permissions()->attach($p);
    $policy->update(['active' => false]);
    expect($service->run()['rows'][0]['action'])->toBe('unchanged')->and($policy->fresh()->active)->toBeFalse()->and($policy->permissions()->count())->toBe(1);
    $this->artisan('permission-manager:check')->assertExitCode(0);
});
it('rejects the complete provisioning plan before any mutation when a role conflicts', function () {
    config(['permission-manager.provisioning' => ['member', 'missing']]);
    expect(app(DelegationPolicyProvisioningService::class)->run()['conflicts'])->toBeTrue()->and(DelegationPolicy::count())->toBe(0);
});

it('does not implicitly grant a populated detached policy during provisioning', function () {
    config(['permission-manager.provisioning' => ['member']]);
    $policy = DelegationPolicy::create(['name' => 'Existing', 'slug' => 'role-member', 'active' => true]);
    $policy->permissions()->attach(Permission::create(['name' => 'scope', 'guard_name' => 'web']));
    expect(app(DelegationPolicyProvisioningService::class)->run()['conflicts'])->toBeTrue()->and($this->role->fresh()->delegation_policy_id)->toBeNull();
});

it('allows management discovery independently of general catalog access', function () {
    $actor = User::create(['name' => 'Manager']);
    $actor->assignRole(Role::create(['name' => 'manager', 'guard_name' => 'web', 'level' => 2]));
    $entry = Permission::create(['name' => 'edit', 'guard_name' => 'web']);
    $actor->givePermissionTo($entry);
    config(['permission-manager.policy_manager_roles' => ['manager'], 'permission-manager.abilities' => ['roles.update' => ['edit']]]);
    $this->actingAs($actor)->getJson($this->base.'/roles')->assertForbidden();
    $this->getJson($this->base.'/roles/manageable')->assertOk()->assertJsonPath('data.0.id', $this->role->id);
    $this->getJson($this->base.'/policies')->assertForbidden();
    $this->getJson($this->base.'/policies/manageable')->assertOk();
});

it('requires the optional functional policy entry without granting global scope', function () {
    $actor = User::create(['name' => 'Manager']);
    $actor->assignRole(Role::create(['name' => 'manager', 'guard_name' => 'web', 'level' => 2]));
    $entry = Permission::create(['name' => 'manage', 'guard_name' => 'web']);
    config(['permission-manager.policy_manager_roles' => ['manager'], 'permission-manager.policy_management_ability' => 'policies.manage', 'permission-manager.abilities' => ['policies.manage' => ['manage']]]);
    $this->actingAs($actor)->postJson($this->base.'/policies', ['name' => 'Empty', 'slug' => 'empty'])->assertForbidden();
    $actor->givePermissionTo($entry);
    $this->postJson($this->base.'/policies', ['name' => 'Empty', 'slug' => 'empty'])->assertCreated();
    $this->postJson($this->base.'/policies', ['name' => 'Forbidden', 'slug' => 'forbidden', 'permissions' => [$entry->id]])->assertForbidden();
});

it('does not expose foreign policy permissions through read or metadata responses', function () {
    $policy = DelegationPolicy::create(['name' => 'Malformed fixture', 'slug' => 'malformed', 'active' => false]);
    $policy->permissions()->attach(Permission::create(['name' => 'foreign-secret', 'guard_name' => 'other']));
    $this->actingAs($this->admin)->getJson($this->base.'/policies/'.$policy->id)->assertOk()->assertJsonCount(0, 'data.permissions');
    $this->putJson($this->base.'/policies/'.$policy->id, ['name' => 'Metadata'])->assertOk()->assertJsonCount(0, 'data.permissions');
    $this->putJson($this->base.'/policies/'.$policy->id, ['active' => true])->assertUnprocessable();
});

it('maps SQL conflicts without exposing internal statements', function ($code, $status) {
    $request = Request::create('/api/test');
    $request->setUserResolver(fn () => $this->admin);
    $previous = new PDOException('private details');
    $previous->errorInfo = ['23000', $code, 'private details'];
    $response = (new RequireActor)->handle($request, fn () => throw new QueryException('testing', 'SECRET SQL', [], $previous));
    expect($response->getStatusCode())->toBe($status)->and($response->getContent())->not->toContain('SECRET', 'private');
})->with([[1062, 409], [999, 500]]);
