<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Authorization\RbacMutationAuthorization;
use Ahinest\LaravelPermissionManager\Authorization\UserAuthorityRules;
use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Consultas autorizadas y proyecciones contextuales de autoridad para la aplicación.
 * Los resultados son informativos y no reservan autoridad para escrituras posteriores.
 */
class AuthorityQueryService
{
    /**
     * Combina autorización funcional, delegación y reglas de usuario para consultas informativas.
     */
    public function __construct(private RbacMutationAuthorization $auth, private PermissionDelegationService $engine, private UserAuthorityRules $rules) {}

    /**
     * Devuelve el catálogo del guard con P, Q activa y capacidades, previa autorización roles.view.
     */
    public function roles(Model $actor): Collection
    {
        $actor = $this->auth->actor($actor, 'roles.view');

        return $this->roleCatalog()->map(fn ($role) => $this->roleData($role, $actor));
    }

    /**
     * Devuelve IDs, nombres y descripciones del catálogo de permisos tras exigir permissions.view.
     */
    public function permissions(Model $actor): Collection
    {
        $this->auth->actor($actor, 'permissions.view');
        $class = config('permission.models.permission');

        return $class::query()->get()->filter(fn ($p) => $p->guard_name === Models::guard())->values()->map(fn ($p) => $p->only(['id', 'name', 'description']));
    }

    /**
     * Devuelve roles editables por jerarquía tras exigir roles.update, sin requerir roles.view.
     */
    public function manageableRoles(Model $actor): Collection
    {
        $actor = $this->auth->actor($actor, 'roles.update');

        return $this->roleCatalog()->filter(fn ($role) => app(RoleHierarchy::class)->canEditRole($actor, $role))->values()->map(fn ($role) => $this->roleData($role, $actor));
    }

    /**
     * Devuelve políticas y capacidades para un gestor configurado, sin exigir policies.view.
     */
    public function manageablePolicies(Model $actor): Collection
    {
        $actor = $this->engine->policyManagerActor($actor);

        return DelegationPolicy::with(['permissions', 'roles'])->get()->map(fn ($policy) => $this->policyData($actor, $policy));
    }

    /**
     * Devuelve candidatos para agregar un rol conservando las membresías actuales.
     *
     * Exige users.roles y, si target es null, users.create; comprueba alcance del objetivo.
     * Los resultados son informativos y no autorizan una escritura posterior.
     */
    public function assignableRoles(Model $actor, ?Model $target = null): Collection
    {
        $actor = $this->auth->actor($actor, $target ? 'users.roles' : 'users.create');
        $this->auth->actor($actor, 'users.roles');
        if ($target) {
            $target = $this->auth->target($actor, $target);
        }
        // Los candidatos agregan un rol al conjunto actual sin reemplazar las demás membresías.
        $current = $target?->roles()->get()->modelKeys() ?? [];

        return $this->roleCatalog()->filter(fn ($role) => $this->allows(fn () => $this->rules->roles($actor, $target, array_values(array_unique([...$current, $role->id])))))
            ->values()->map(fn ($role) => $this->roleData($role));
    }

    /**
     * Describe roles, permisos directos, heredados, efectivos y capacidades del objetivo.
     *
     * Exige users.view y control de la cuenta por jerarquía y autoridad; no expone atributos personales.
     */
    public function userAuthority(Model $actor, Model $target): array
    {
        $actor = $this->auth->actor($actor, 'users.view');
        $target = $this->auth->target($actor, $target);
        $roles = $target->roles()->with('permissions')->get();
        $direct = $target->permissions()->get()->filter(fn ($p) => $p->guard_name === Models::guard());
        $inherited = $roles->flatMap(fn ($r) => $r->permissions)->filter(fn ($p) => $p->guard_name === Models::guard())->unique('id')->values();

        return ['roles' => $roles->map(fn ($r) => $this->roleData($r)), 'direct_permissions' => $direct->map->only(['id', 'name'])->values(), 'inherited_permissions' => $inherited->map->only(['id', 'name']), 'effective_permissions' => $direct->merge($inherited)->unique('id')->map->only(['id', 'name'])->values(), 'capabilities' => $this->userCapabilities($actor, $target)];
    }

    /**
     * Calcula conjuntos grantable y revocable de permisos directos para el objetivo.
     *
     * Exige users.permissions y control del objetivo, o users.create cuando es null.
     * Cada candidato conserva las demás asignaciones; no garantiza autoridad futura.
     */
    public function delegablePermissions(Model $actor, ?Model $target = null): array
    {
        $actor = $this->auth->actor($actor, 'users.permissions');
        if (! $target) {
            $this->auth->actor($actor, 'users.create');
        }
        if ($target) {
            $target = $this->auth->target($actor, $target);
        }
        $current = $target?->permissions()->get()->modelKeys() ?? [];
        $permissions = $this->engine->delegablePermissions($actor);
        $grant = $permissions->filter(fn ($p) => $this->allows(fn () => $this->rules->permissions($actor, $target, array_values(array_unique([...$current, $p->id])))));
        $revoke = $permissions->whereIn('id', $current)->filter(fn ($p) => $this->allows(fn () => $this->rules->permissions($actor, $target, array_values(array_diff($current, [$p->id])))));

        return ['grantable' => $grant->map->only(['id', 'name'])->values(), 'revocable' => $revoke->map->only(['id', 'name'])->values()];
    }

    /**
     * Devuelve el catálogo de políticas con relaciones y capacidades tras exigir policies.view.
     */
    public function policies(Model $actor): Collection
    {
        $actor = $this->auth->actor($actor, 'policies.view');

        return DelegationPolicy::with(['permissions', 'roles'])->get()->map(fn ($p) => $this->policyData($actor, $p));
    }

    /**
     * Devuelve el detalle y las capacidades de la política indicada, previa autorización policies.view.
     *
     * @throws ModelNotFoundException Si no existe la política.
     */
    public function policy(Model $actor, int $id): array
    {
        $actor = $this->auth->actor($actor, 'policies.view');

        return $this->policyData($actor, DelegationPolicy::with(['permissions', 'roles'])->findOrFail($id));
    }

    /**
     * Devuelve roles a los que el gestor puede asociar la política activa indicada.
     *
     * Evalúa contenido saliente/entrante y miembros; no persiste la asociación.
     *
     * @throws ValidationException Si la política está inactiva.
     */
    public function assignablePolicyRoles(Model $actor, int $id): Collection
    {
        $actor = $this->engine->policyManagerActor($actor);
        $policy = DelegationPolicy::findOrFail($id);
        if (! $policy->active) {
            throw ValidationException::withMessages(['policy' => 'An active policy is required.']);
        }

        return $this->roleCatalog()->filter(fn ($role) => $this->allows(fn () => $this->engine->assertCanAssignPolicyToRole($actor, $role, $policy)))->values()->map(fn ($r) => $this->roleData($r));
    }

    /**
     * Proyecta entradas funcionales y gestión de políticas para un operador persistido elegible.
     *
     * No concede autoridad ni comprueba todos los posibles payloads de futuras operaciones.
     */
    public function capabilities(Model $actor): array
    {
        $this->engine->snapshot($actor);
        $actor = Models::user()->whereKey($actor->getKey())->firstOrFail();
        $this->auth->require(app(ActorEligibility::class)->allows($actor));
        foreach ($actor->roles()->get() as $role) {
            $this->auth->role($role);
        }

        return collect(['roles.view', 'roles.create', 'roles.update', 'roles.permissions', 'permissions.view', 'users.view', 'users.create', 'users.update', 'users.suspend', 'users.reactivate', 'users.delete', 'users.roles', 'users.permissions', 'policies.view'])
            ->mapWithKeys(fn ($ability) => [$ability => $this->allows(fn () => $this->auth->actor($actor, $ability))])->all()
            + ['policies.manage' => $this->allows(fn () => $this->engine->policyManagerActor($actor))];
    }

    /**
     * Proyecta operaciones sobre una cuenta y descarta pérdida conocida del último administrador.
     *
     * Las comprobaciones no adquieren un lock; el escritor debe repetirlas sobre estado actualizado.
     */
    private function userCapabilities(Model $actor, Model $target): array
    {
        $result = [];
        foreach (['update', 'suspend', 'reactivate', 'delete', 'roles', 'permissions'] as $operation) {
            $result['can_'.$operation] = $this->allows(function () use ($actor, $target, $operation) {
                $this->auth->actor($actor, 'users.'.$operation);
                $this->auth->target($actor, $target);
                if (in_array($operation, ['suspend', 'delete'], true) && app(EligibleAdministrator::class)->qualifies($target) && ! app(EligibleAdministrator::class)->exists($target->getKey())) {
                    throw new AuthorizationException;
                }
            });
        }

        return $result;
    }

    /**
     * Construye metadatos, permisos, consumidores y capacidades informativas de una política.
     *
     * La edición de metadatos y la modificación de autoridad usan comprobaciones distintas.
     */
    private function policyData(Model $actor, DelegationPolicy $policy): array
    {
        $can = fn (bool $active) => $this->allows(function () use ($actor, $policy, $active) {
            $this->engine->policyManagerActor($actor);
            $this->engine->assertCanMutatePolicy($actor, $policy, $policy->permissions->modelKeys(), $active);
        });

        return $policy->only(['id', 'name', 'slug', 'active']) + ['permissions' => $policy->permissions->filter(fn ($p) => $p->guard_name === Models::guard())->map->only(['id', 'name'])->values(), 'roles' => $policy->roles->filter(fn ($r) => $r->guard_name === Models::guard())->map->only(['id', 'name', 'level'])->values(), 'capabilities' => ['can_update' => $this->allows(fn () => $this->engine->policyManagerActor($actor)), 'can_sync_permissions' => $can($policy->active), 'can_activate' => $can(true), 'can_deactivate' => $can(false)]];
    }

    /**
     * Carga roles del guard con relaciones y descarta identidades ambiguas según autorización.
     */
    private function roleCatalog(): Collection
    {
        return Models::role()->with(['permissions', 'delegationPolicy.permissions'])->get()->filter(fn ($r) => $r->guard_name === Models::guard() && $this->allows(fn () => $this->auth->role($r)))->values();
    }

    /**
     * Proyecta metadatos, P y Q activa del rol; añade capacidades cuando se recibe actor.
     */
    private function roleData(Role $role, ?Model $actor = null): array
    {
        $data = $role->only(['id', 'name', 'description', 'level', 'delegation_policy_id']) + ['permissions' => $this->engine->roleUsagePermissions($role)->map->only(['id', 'name'])->values(), 'delegable_permissions' => $this->engine->roleDelegablePermissions($role)->map->only(['id', 'name'])->values()];
        if ($actor) {
            $data['capabilities'] = ['can_update' => $this->allows(function () use ($actor, $role) {
                $this->auth->actor($actor, 'roles.update');
                $this->auth->require(app(RoleHierarchy::class)->canEditRole($actor, $role));
            }), 'can_sync_permissions' => $this->allows(function () use ($actor, $role) {
                $this->auth->actor($actor, 'roles.permissions');
                $this->auth->require(app(RoleHierarchy::class)->canEditRole($actor, $role));
                $this->engine->assertCanChangeRoleUsage($actor, $role, $role->permissions->modelKeys());
            })];
        }

        return $data;
    }

    /**
     * Convierte únicamente rechazos de autorización o validación en false.
     *
     * Los errores de infraestructura y persistencia se propagan para no ocultar fallos operativos.
     */
    private function allows(callable $check): bool
    {
        try {
            $check();

            return true;
        } catch (AuthorizationException|ValidationException) {
            return false;
        }
    }
}
