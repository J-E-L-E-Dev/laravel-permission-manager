<?php

use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Contracts\UserAccountWriter;
use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Ahinest\LaravelPermissionManager\Events\UserAuthorityChanged;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\AuthorityAuditTrail;
use Ahinest\LaravelPermissionManager\Services\AuthorizedUserMutation;
use Ahinest\LaravelPermissionManager\Services\DelegationPolicyProvisioningService;
use Ahinest\LaravelPermissionManager\Services\DelegationPolicyService;
use Ahinest\LaravelPermissionManager\Services\RbacWriteTransaction;
use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Support\AuditContext;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\AccountWriter;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\RecordingAuditStore;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    Schema::create('test_authority_audits', function (Blueprint $table) {
        $table->id();
        $table->text('payload');
    });
    Schema::table('users', function (Blueprint $table) {
        $table->boolean('active')->default(true);
        $table->string('password')->nullable();
    });
    $this->store = new RecordingAuditStore;
    app()->instance(TransactionalAuditStore::class, $this->store);
    config(['permission-manager.audit.required' => true]);
    app()->bind(UserAccountWriter::class, AccountWriter::class);
    $this->admin = User::create(['name' => 'Admin']);
    $this->admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
    $this->role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 20]);
    $this->policy = DelegationPolicy::create(['name' => 'Scope', 'slug' => 'scope', 'active' => true]);
    $this->permissions = collect(['a', 'b', 'c'])->map(fn ($name) => Permission::create(['name' => $name, 'guard_name' => 'web']));
    $this->dispatcher = Event::getFacadeRoot();
    Event::fake([AuthorityAudit::class, UserAuthorityChanged::class]);
});

function persistedAuthorityAudits()
{
    return DB::table('test_authority_audits')->orderBy('id')->get()->map(fn ($row) => json_decode($row->payload, true, flags: JSON_THROW_ON_ERROR));
}

it('audits role creation with complete explicit metadata', function () {
    $role = app(RoleManagementService::class)->create($this->admin, ['name' => 'created', 'level' => 30, 'description' => 'Description']);
    $audit = persistedAuthorityAudits()->sole();
    expect($audit['operation'])->toBe('role.created')->and($audit['actorId'])->toBe($this->admin->id)->and($audit['resourceId'])->toBe($role->id)
        ->and($audit['resourceType'])->toBe('role')->and($audit['guard'])->toBe('web')->and($audit['before'])->toBe([])
        ->and($audit['after'])->toBe(['name' => 'created', 'guard_name' => 'web', 'level' => 30, 'description' => 'Description'])
        ->and($audit['correlationId'])->toMatch('/^[a-f0-9-]{36}$/')->and($audit['occurredAt'])->toEndWith('Z');
    Event::assertDispatchedTimes(AuthorityAudit::class, 1);
});

it('records exactly the role fields changed from fresh persisted state', function ($changes) {
    $this->role->load('permissions');
    Role::whereKey($this->role->id)->update(['level' => 25]);
    app(RoleManagementService::class)->update($this->admin, $this->role, $changes);
    $audit = persistedAuthorityAudits()->sole();
    expect(array_keys($audit['changes']))->toEqualCanonicalizing(array_keys($changes));
    foreach ($changes as $field => $value) {
        expect($audit['after'][$field])->toBe($value);
    }
    if (isset($changes['level'])) {
        expect($audit['before']['level'])->toBe(25);
    }
})->with([[['level' => 30]], [['name' => 'renamed']], [['description' => 'Changed']], [['level' => 40, 'description' => 'Both']]]);

it('omits no-op role updates and repeated permission sets regardless of order', function () {
    $ids = $this->permissions->pluck('id')->all();
    $this->role->syncPermissions($ids);
    app(RoleManagementService::class)->update($this->admin, $this->role, ['level' => 20, 'name' => 'member']);
    app(RoleManagementService::class)->syncPermissions($this->admin, $this->role, array_reverse($ids));
    expect(persistedAuthorityAudits())->toBeEmpty();
    Event::assertNotDispatched(AuthorityAudit::class);
});

it('captures normalized functional permission differences instead of stale relations', function () {
    [$a, $b, $c] = $this->permissions;
    $this->role->load('permissions');
    $this->role->syncPermissions([$a, $b]);
    app(RoleManagementService::class)->syncPermissions($this->admin, $this->role, [$c->id, $a->id]);
    expect(persistedAuthorityAudits()->sole()['changes']['permissions'])->toBe(['before' => [$a->id, $b->id], 'after' => [$a->id, $c->id], 'added' => [$c->id], 'removed' => [$b->id]]);
});

it('never emits success for rejected role or policy writes', function ($kind) {
    $actor = User::create(['name' => 'Denied']);
    expect(fn () => $kind === 'role'
        ? app(RoleManagementService::class)->update($actor, $this->role, ['level' => 30])
        : app(DelegationPolicyService::class)->update($actor, $this->policy, ['active' => false]))->toThrow(AuthorizationException::class);
    expect(persistedAuthorityAudits())->toBeEmpty();
    Event::assertNotDispatched(AuthorityAudit::class);
})->with(['role', 'policy']);

it('audits policy creation including empty or supplied initial Q', function ($populated) {
    $ids = $populated ? $this->permissions->pluck('id')->all() : [];
    $policy = app(DelegationPolicyService::class)->create($this->admin, ['name' => 'Created', 'slug' => 'created', 'permissions' => array_reverse($ids)]);
    $audit = persistedAuthorityAudits()->sole();
    expect($audit['operation'])->toBe('delegation_policy.created')->and($audit['resourceId'])->toBe($policy->id)
        ->and($audit['after'])->toBe(['name' => 'Created', 'slug' => 'created', 'active' => false, 'permissions' => $ids]);
})->with([false, true]);

it('audits policy activation deactivation and name without no-op duplicates', function () {
    $service = app(DelegationPolicyService::class);
    $service->update($this->admin, $this->policy, ['active' => false]);
    $service->update($this->admin, $this->policy, ['active' => true]);
    $service->update($this->admin, $this->policy, ['name' => 'Renamed']);
    $service->update($this->admin, $this->policy, ['active' => true, 'name' => 'Renamed']);
    $audits = persistedAuthorityAudits();
    expect($audits)->toHaveCount(3)->and($audits[0]['changes'])->toBe(['active' => ['before' => true, 'after' => false]])
        ->and($audits[1]['changes'])->toBe(['active' => ['before' => false, 'after' => true]])
        ->and($audits[2]['changes'])->toBe(['name' => ['before' => 'Scope', 'after' => 'Renamed']]);
});

it('captures Q deltas and ignores repeated reordered sets', function () {
    [$a, $b, $c] = $this->permissions;
    $this->policy->permissions()->attach([$a->id, $b->id]);
    $this->policy->load('permissions');
    $service = app(DelegationPolicyService::class);
    $service->syncPermissions($this->admin, $this->policy, [$c->id, $a->id]);
    $service->syncPermissions($this->admin, $this->policy, [$a->id, $c->id]);
    expect(persistedAuthorityAudits()->sole()['changes']['permissions'])->toBe(['before' => [$a->id, $b->id], 'after' => [$a->id, $c->id], 'added' => [$c->id], 'removed' => [$b->id]]);
});

it('audits association replacement and dissociation only once per actual structural change', function () {
    $other = DelegationPolicy::create(['name' => 'Other', 'slug' => 'other', 'active' => true]);
    $service = app(DelegationPolicyService::class);
    foreach ([$this->policy->id, $this->policy->id, $other->id, null, null] as $id) {
        $service->assignToRole($this->admin, $this->role, $id);
    }
    $audits = persistedAuthorityAudits();
    expect($audits)->toHaveCount(3)->and($audits->pluck('resourceId')->unique()->all())->toBe([$this->role->id])
        ->and($audits[0]['changes']['delegation_policy_id'])->toBe(['before' => null, 'after' => $this->policy->id])
        ->and($audits[1]['changes']['delegation_policy_id'])->toBe(['before' => $this->policy->id, 'after' => $other->id])
        ->and($audits[2]['changes']['delegation_policy_id'])->toBe(['before' => $other->id, 'after' => null]);
});

it('preserves the legacy constructor and enriches incremental user changes without no-op audits', function () {
    $legacy = new UserAuthorityChanged('actor-key', 'target-key', 'roles.replace');
    expect($legacy->actorId)->toBe('actor-key')->and($legacy->targetId)->toBe('target-key')->and($legacy->audit)->toBeNull();
    $user = User::create(['name' => 'Target']);
    $service = app(UserPermissionManagementService::class);
    $service->assignRole($this->admin, $user, $this->role->id);
    $service->assignRole($this->admin, $user, $this->role->id);
    $service->grantDirectPermission($this->admin, $user, $this->permissions[0]->id);
    $service->removeRole($this->admin, $user, $this->role->id);
    $service->revokeDirectPermission($this->admin, $user, $this->permissions[0]->id);
    expect(persistedAuthorityAudits())->toHaveCount(4);
    Event::assertDispatchedTimes(UserAuthorityChanged::class, 5);
    Event::assertDispatched(UserAuthorityChanged::class, fn ($event) => $event->audit !== null && $event->correlationId === $event->audit->correlationId && $event->accountChanged === false);
});

it('keeps compound creation in one audit correlated with the legacy account event and never serializes secrets', function () {
    $user = app(AuthorizedUserMutation::class)->execute($this->admin, UserOperation::Create, null, ['name' => 'Private name', 'password' => 'Secret-password', 'token' => 'Secret-token'], [$this->role->id], [$this->permissions[0]->id]);
    $audit = persistedAuthorityAudits()->sole();
    expect($audit['operation'])->toBe('user.create')->and($audit['after'])->toBe(['roles' => [$this->role->id], 'direct_permissions' => [$this->permissions[0]->id]])
        ->and(json_encode($audit))->not->toContain('Private name')->not->toContain('Secret-password')->not->toContain('Secret-token');
    Event::assertDispatchedTimes(AuthorityAudit::class, 1);
    Event::assertDispatched(UserAuthorityChanged::class, fn ($event) => $event->targetId === $user->id && $event->accountChanged && $event->correlationId === $audit['correlationId']);
    expect(app(AuditContext::class)->correlationId())->toBeNull();
});

it('rolls back authority and inserted audit if mandatory persistence fails', function ($kind) {
    $this->store->fail = true;
    expect(fn () => $kind === 'role'
        ? app(RoleManagementService::class)->update($this->admin, $this->role, ['level' => 30])
        : app(DelegationPolicyService::class)->update($this->admin, $this->policy, ['active' => false]))->toThrow(RuntimeException::class, 'audit fixture failure');
    expect($this->role->fresh()->level)->toBe(20)->and($this->policy->fresh()->active)->toBeTrue()->and(persistedAuthorityAudits())->toBeEmpty();
    Event::assertNotDispatched(AuthorityAudit::class);
    expect(app(AuditContext::class)->correlationId())->toBeNull();
})->with(['role', 'policy']);

it('rolls back an already emitted event and its synchronous persistent record', function () {
    Event::swap($this->dispatcher);
    $seen = 0;
    Event::listen(AuthorityAudit::class, function () use (&$seen) {
        $seen++;
        throw new RuntimeException('later listener failure');
    });
    expect(fn () => app(RoleManagementService::class)->update($this->admin, $this->role, ['level' => 30]))->toThrow(RuntimeException::class);
    expect($seen)->toBe(1)->and($this->role->fresh()->level)->toBe(20)->and(persistedAuthorityAudits())->toBeEmpty();
});

it('rejects missing mandatory binding or a different connection before writing', function ($kind) {
    if ($kind === 'missing') {
        app()->forgetInstance(TransactionalAuditStore::class);
    } else {
        config(['database.connections.audit_other' => config('database.connections.testing')]);
        $this->store->overrideConnection = DB::connection('audit_other');
    }
    expect(fn () => app(RoleManagementService::class)->update($this->admin, $this->role, ['level' => 30]))->toThrow(LogicException::class);
    expect($this->role->fresh()->level)->toBe(20)->and(persistedAuthorityAudits())->toBeEmpty();
})->with(['missing', 'other']);

it('audits structural provisioning as infrastructure with common correlation and no dry-run or repeat changes', function () {
    config(['permission-manager.provisioning' => ['member']]);
    $service = app(DelegationPolicyProvisioningService::class);
    $service->run(true);
    expect(persistedAuthorityAudits())->toBeEmpty();
    $service->run();
    $service->run();
    $audits = persistedAuthorityAudits();
    expect($audits)->toHaveCount(2)->and($audits->pluck('actorType')->unique()->all())->toBe(['infrastructure'])
        ->and($audits->pluck('actorId')->all())->toBe([null, null])->and($audits->pluck('correlationId')->unique())->toHaveCount(1);
});

it('preserves string historical user identifiers in the audit contract without changing Spatie key rules', function () {
    app(RbacWriteTransaction::class)->run(fn () => app(AuthorityAuditTrail::class)->record('actor-0001', 'user', 'target-0002', 'user.roles.replace', ['roles' => []], ['roles' => [1]]));
    $audit = persistedAuthorityAudits()->sole();
    expect($audit['actorId'])->toBe('actor-0001')->and($audit['resourceId'])->toBe('target-0002')->and($audit['after']['roles'])->toBe([1]);
});

it('keeps post-commit effects out of a rolled back audit operation', function () {
    Event::swap($this->dispatcher);
    $delivered = [];
    Event::listen(AuthorityAudit::class, function ($event) use (&$delivered) {
        DB::afterCommit(function () use ($event, &$delivered) {
            $delivered[] = $event->eventId;
        });
    });
    app(RoleManagementService::class)->update($this->admin, $this->role, ['level' => 30]);
    expect($delivered)->toHaveCount(1);
    Event::listen(AuthorityAudit::class, fn () => throw new RuntimeException('rollback after scheduling'));
    expect(fn () => app(RoleManagementService::class)->update($this->admin, $this->role, ['level' => 40]))->toThrow(RuntimeException::class);
    expect($delivered)->toHaveCount(1)->and(persistedAuthorityAudits())->toHaveCount(1)->and($this->role->fresh()->level)->toBe(30);
});
