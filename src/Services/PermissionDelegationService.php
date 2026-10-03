<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Authorization\DelegationAuthoritySnapshot;
use Ahinest\LaravelPermissionManager\Authorization\RbacMutationAuthorization;
use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Ahinest\LaravelPermissionManager\Contracts\AdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Contracts\SensitiveRoleResolver;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model as User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Primitivas complementarias de lectura y comprobación del modelo P/Q/D.
 * Los servicios administrativos las componen bajo el lock; no son entradas de escritura
 * autorizadas ni conceden acceso HTTP al catálogo o a cuentas ajenas.
 */
class PermissionDelegationService
{
    /**
     * Recibe las reglas jerárquicas necesarias para comprobar consumidores y miembros afectados.
     */
    public function __construct(private readonly RoleHierarchy $hierarchy) {}

    /**
     * Comprueba la identidad administrativa exacta sobre una cuenta persistida recargada.
     *
     * Consulta AdministrativeIdentity y los roles del guard; no concede permisos físicos
     * ni verifica por sí solo toda la elegibilidad de una operación de escritura.
     */
    public function isAdmin(User $actor): bool
    {
        $actor = $this->freshUser($actor);
        $identity = app(AdministrativeIdentity::class);
        $identity->assertActor($actor);

        return $identity->allowsGlobalAdministration($actor) && $this->webRoles($actor)->contains(fn (Role $role) => $role->name === config('permission-manager.admin_role'));
    }

    /**
     * Determina si el operador es administrador o posee un rol de gestor configurado.
     *
     * La identidad de gestor no amplía D; la elegibilidad y entrada adicional se verifican
     * en policyManagerActor antes de las operaciones de gestión.
     */
    public function isPolicyManager(User $actor): bool
    {
        return $this->isAdmin($actor) || $this->webRoles($actor)->contains(fn (Role $role) => $role->name !== config('permission-manager.admin_role') && in_array($role->name, config('permission-manager.policy_manager_roles', []), true));
    }

    /**
     * Reúne los nombres de admin, gestores y reservados configurados, sin vacíos ni duplicados.
     */
    public function reservedRoleNames(): array
    {
        return array_values(array_unique(array_filter([config('permission-manager.admin_role'), ...config('permission-manager.policy_manager_roles', []), ...config('permission-manager.reserved_role_names', [])], fn ($name) => is_string($name) && $name !== '')));
    }

    /**
     * Comprueba coincidencia exacta del nombre con las identidades reservadas configuradas.
     */
    public function isReservedRoleName(string $name): bool
    {
        return in_array($name, $this->reservedRoleNames(), true);
    }

    /**
     * Combina identidades reservadas y nombres sensibles actuales del contrato anfitrión.
     *
     * Estos privilegios cualitativos no pueden representarse solo mediante IDs de permisos.
     */
    public function sensitiveRoleNames(): array
    {
        return array_values(array_unique([
            ...$this->reservedRoleNames(),
            ...app(SensitiveRoleResolver::class)->names(),
        ]));
    }

    /**
     * Captura roles, P, Q activa, D e identidades del operador desde relaciones persistidas.
     *
     * Ignora relaciones Eloquent cargadas anteriormente. El administrador obtiene D del
     * catálogo actual del guard; los demás, de la unión de Q activa. No adquiere el lock:
     * las escrituras deben llamarlo dentro de su frontera y no reutilizar el resultado.
     */
    public function snapshot(User $actor): DelegationAuthoritySnapshot
    {
        $roles = $this->webRoles($actor);
        $names = $roles->pluck('name')->all();
        $admin = $this->isAdmin($actor);
        $usage = $delegable = [];
        foreach ($roles as $role) {
            $usage[$role->id] = $this->roleUsagePermissions($role)->modelKeys();
            $delegable[$role->id] = $this->roleDelegablePermissions($role)->modelKeys();
        }

        return new DelegationAuthoritySnapshot(
            $actor->getKey(), $admin,
            $admin || count(array_intersect($names, array_diff(config('permission-manager.policy_manager_roles', []), [config('permission-manager.admin_role')]))) > 0,
            $roles->modelKeys(), $names, $usage, $delegable,
            $admin ? $this->webPermissions()->modelKeys() : $this->union(...array_values($delegable)),
            array_values(array_intersect($names, $this->sensitiveRoleNames())),
        );
    }

    /**
     * Devuelve los IDs de D calculados en una nueva instantánea persistida del operador.
     */
    public function delegablePermissionIds(User $actor): array
    {
        return $this->snapshot($actor)->permissionIds;
    }

    /**
     * Consulta los modelos de permisos del guard cuyos IDs pertenecen al D actual.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Permission>
     */
    public function delegablePermissions(User $actor): Collection
    {
        return $this->permissionQuery()->where('guard_name', Models::guard())->whereKey($this->delegablePermissionIds($actor))->get();
    }

    /**
     * Recarga el rol y devuelve P, filtrado por coincidencia exacta del guard.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Permission>
     */
    public function roleUsagePermissions(Role $role): Collection
    {
        return $this->freshRole($role)->permissions()->get()->filter(fn ($permission) => $permission->guard_name === Models::guard())->values();
    }

    /**
     * Devuelve Q de la política activa del rol recargado, o una colección vacía.
     *
     * Una política ausente o inactiva no concede delegación; se filtra por el guard exacto.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Permission>
     */
    public function roleDelegablePermissions(Role $role): Collection
    {
        $policy = $this->freshRole($role)->delegationPolicy()->first();

        return $policy?->active ? $policy->permissions()->get()->filter(fn ($permission) => $permission->guard_name === Models::guard())->values() : $this->permissionQuery()->whereRaw('1 = 0')->get();
    }

    /**
     * Exige que la unión de permisos actuales y finales esté dentro del D previo.
     *
     * current y final contienen enteros positivos existentes del guard, incluidos permisos
     * retirados y conservados. provided=false omite la comprobación; [] expresa revocación.
     */
    public function assertCanReplacePermissions(User $actor, array $current, array $final, bool $provided = true): void
    {
        if ($provided) {
            $this->assertWithin($this->snapshot($actor), $this->union($this->validIds($current), $this->validIds($final)));
        }
    }

    /**
     * Comprueba que P y Q activa del rol caben en D y que su identidad es transferible.
     *
     * Es una primitiva complementaria: el servicio debe comprobar también entrada,
     * jerarquía y objetivo dentro de la transacción.
     */
    public function assertCanAssignRole(User $actor, Role $role): void
    {
        $this->assertRoleWithin($this->snapshot($actor), $role);
    }

    /**
     * Valida todos los roles actuales y finales, incluida la autoridad que se retira.
     *
     * Los arrays contienen IDs enteros, no nombres. provided=false conserva la omisión;
     * un array final vacío sí debe autorizar la retirada del conjunto actual.
     */
    public function assertCanReplaceRoles(User $actor, array $currentRoles, array $finalRoles, bool $provided = true): void
    {
        if (! $provided) {
            return;
        }
        $before = $this->snapshot($actor);
        foreach (array_merge($currentRoles, $finalRoles) as $id) {
            if (! is_int($id) || $id < 1) {
                $this->invalid('roles', 'Role IDs must be positive integers.');
            }
        }
        foreach ($this->union($currentRoles, $finalRoles) as $id) {
            if (! is_int($id) || ! ($role = Models::role()->find($id))) {
                $this->invalid('roles', 'Role IDs must identify existing web roles.');
            }
            $this->assertRoleWithin($before, $role);
        }
    }

    /**
     * Exige gestor y alcance sobre el contenido actual/final y cada rol consumidor.
     *
     * Incluye contenido almacenado aunque la política esté inactiva. No persiste cambios.
     */
    public function assertCanManagePolicyPermissions(User $actor, DelegationPolicy $policy, array $finalPermissions): void
    {
        $before = $this->snapshot($actor);
        $this->assertManager($before);
        $policy = $this->freshPolicy($policy);
        $this->assertWithin($before, $this->union($this->policyIds($policy), $this->validIds($finalPermissions)));
        $this->assertConsumers($actor, $before, $policy);
    }

    /**
     * Comprueba gestión y alcance sobre contenidos de políticas entrante y saliente.
     *
     * La candidata debe estar activa. Es una primitiva de contenido; el servicio completa
     * los controles del rol y sus miembros antes de asociarla.
     */
    public function assertPolicyAssignable(User $actor, ?DelegationPolicy $current, ?DelegationPolicy $candidate): void
    {
        $this->assertPolicyWithin($this->snapshot($actor), $current, $candidate);
    }

    /**
     * Valida el rol y los contenidos saliente/entrante para un gestor.
     *
     * Un no administrador no puede cambiar la política de un rol propio, ni a la misma
     * política ni a null. Esta restricción evita escalamiento mediante asociaciones.
     */
    public function assertCanChangeRolePolicy(User $actor, Role $role, ?DelegationPolicy $candidate): void
    {
        $before = $this->snapshot($actor);
        $this->assertManager($before);
        $role = $this->freshRole($role);
        if (! $before->admin && in_array($role->id, $before->roleIds, true)) {
            $this->deny('A non-admin cannot change the policy of an owned role.');
        }
        $this->assertAdministrativeRole($actor, $before, $role);
        $this->assertPolicyWithin($before, $role->delegationPolicy()->first(), $candidate);
    }

    /**
     * Valida contenido y actividad candidatos considerando todos los consumidores y miembros.
     *
     * Impide que un no administrador modifique autoridad mediante un rol consumidor propio
     * y compara el D candidato con la instantánea anterior a la escritura.
     */
    public function assertCanMutatePolicy(User $actor, DelegationPolicy $policy, array $finalPermissions, bool $active): void
    {
        $before = $this->snapshot($actor);
        $this->assertCanManagePolicyPermissions($actor, $policy, $finalPermissions);
        $candidate = $before->delegableByRole;
        foreach ($this->freshPolicy($policy)->roles()->get() as $role) {
            if (! $before->admin && in_array($role->id, $before->roleIds, true)) {
                $this->deny('A non-admin cannot mutate authority through an owned consumer role.');
            }
            $this->assertRoleMembers($actor, $role);
            if (array_key_exists($role->id, $candidate)) {
                $candidate[$role->id] = $active ? $finalPermissions : [];
            }
        }
        $this->assertNoSelfEscalation($before, $this->union(...array_values($candidate)));
    }

    /**
     * Valida una asociación concreta y todos los miembros afectados por ese rol.
     *
     * Los demás consumidores mantienen su política; proyecta el cambio sin persistirlo
     * y comprueba que no amplíe el D propio del operador.
     */
    public function assertCanAssignPolicyToRole(User $actor, Role $role, ?DelegationPolicy $candidate): void
    {
        $before = $this->snapshot($actor);
        $this->assertCanChangeRolePolicy($actor, $role, $candidate);
        $this->assertRoleMembers($actor, $role);
        // La asociación solo cambia este consumidor; los demás conservan política y contenido.
        $after = $before->delegableByRole;
        if (array_key_exists($role->id, $after)) {
            $after[$role->id] = $candidate ? $this->policyIds($candidate) : [];
        }
        $this->assertNoSelfEscalation($before, $this->union(...array_values($after)));
    }

    /**
     * Recarga al gestor, exige elegibilidad y valida las identidades de sus roles.
     *
     * Aplica policy_management_ability si está configurada; ser gestor no concede D global.
     *
     * @throws AuthorizationException Si no es elegible o no tiene identidad/entrada de gestor.
     */
    public function policyManagerActor(User $actor): User
    {
        $actor = $this->freshUser($actor);
        foreach ($actor->roles()->get() as $role) {
            app(RbacMutationAuthorization::class)->role($role);
        }
        if (! app(ActorEligibility::class)->allows($actor) || ! $this->isPolicyManager($actor)) {
            $this->deny('Policy management requires an active admin or configured manager.');
        }
        if ($ability = config('permission-manager.policy_management_ability')) {
            $actor = app(RbacMutationAuthorization::class)->actor($actor, $ability);
        }

        return $actor;
    }

    /**
     * Proyecta identidad administrativa, condición de gestor y permisos delegables actuales.
     *
     * Es información para código confiable, no una autorización de divulgación HTTP.
     */
    public function capabilities(User $actor): array
    {
        $before = $this->snapshot($actor);

        return [
            'is_admin' => $before->admin,
            'is_policy_manager' => $before->policyManager,
            'delegable_permissions' => $this->webPermissions()->whereIn('id', $before->permissionIds)
                ->map(fn ($permission) => ['id' => $permission->id, 'name' => $permission->name])->values()->all(),
        ];
    }

    /**
     * Comprueba el D candidato contra la instantánea previa, incluida su condición de admin.
     *
     * El candidato debe calcularse por código de dominio sin persistir primero; nunca
     * aceptar una instantánea construida desde una petición.
     */
    public function assertNoSelfEscalation(DelegationAuthoritySnapshot $before, array $candidatePermissionIds): void
    {
        $this->assertWithin($before, $this->validIds($candidatePermissionIds));
    }

    /**
     * Impide a un no administrador renombrar un rol existente o adquirir un nombre sensible.
     *
     * Compara el nombre candidato normalizado para detectar variantes; no modifica el rol.
     */
    public function assertCanUseRoleIdentity(User $actor, ?Role $currentRole, string $candidateName): void
    {
        $before = $this->snapshot($actor);
        $currentRole = $currentRole ? $this->freshRole($currentRole) : null;
        if (! $before->admin && (
            in_array(mb_strtolower(trim($candidateName)), array_map(fn ($name) => mb_strtolower(trim($name)), $this->sensitiveRoleNames()), true)
            || ($currentRole && $currentRole->name !== $candidateName)
        )) {
            $this->deny('Technical role identities cannot be renamed or sensitive identities acquired by non-admins.');
        }
    }

    /**
     * Calcula la autoridad de la cuenta: permisos directos, P, Q activa e identidades sensibles.
     *
     * Recarga todos los roles y valida sus permisos; no oculta miembros de otro guard
     * para reducir artificialmente la autoridad que debe controlar el operador.
     */
    public function targetAuthorityFootprint(User $target): array
    {
        $roles = $this->freshUser($target)->roles()->get();
        $ids = $this->freshUser($target)->permissions()->get()->modelKeys();
        foreach ($roles as $role) {
            $role = $this->freshRole($role);
            $ids = $this->union($ids, $this->roleTransferIds($role));
        }

        return [
            'permissionIds' => $this->validIds($ids),
            'roleIds' => $roles->modelKeys(),
            'sensitiveRoleNames' => array_values(array_intersect($roles->pluck('name')->all(), $this->sensitiveRoleNames())),
        ];
    }

    /**
     * Aplica invariantes anfitrionas y exige alcance sobre toda la autoridad actual del objetivo.
     *
     * El control de identidades sensibles requiere administración global. No sustituye
     * la entrada funcional ni la jerarquía comprobadas por el servicio de mutación.
     */
    public function assertCanControlAccount(User $actor, User $target): void
    {
        app(AdministrativeIdentity::class)->assertAccountControl($this->freshUser($actor), $this->freshUser($target));
        $before = $this->snapshot($actor);
        $footprint = $this->targetAuthorityFootprint($target);
        if (! $before->admin && $footprint['sensitiveRoleNames'] !== []) {
            $this->deny('Control of a sensitive account is reserved to admin.');
        }
        $this->assertWithin($before, $footprint['permissionIds']);
    }

    /**
     * Exige el mismo control de autoridad e identidad requerido para administrar la cuenta.
     *
     * La eliminación efectiva, su entrada funcional y el último administrador se validan
     * en la operación compuesta; este método no elimina datos.
     */
    public function assertCanDeleteAccount(User $actor, User $target): void
    {
        $this->assertCanControlAccount($actor, $target);
    }

    /**
     * Proyecta D a partir de definiciones persistidas de roleIds sin asignarlos al usuario.
     */
    public function candidateRoleAuthorityIds(array $roleIds): array
    {
        $sets = [];
        foreach ($roleIds as $id) {
            $role = $this->freshRole(Models::role()->findOrFail($id));
            $sets[] = $this->roleDelegablePermissions($role)->modelKeys();
        }

        return $this->union(...$sets);
    }

    /**
     * Valida el reemplazo de P y el control de todos los miembros del rol compartido.
     *
     * P no amplía D; Q y las asignaciones del operador permanecen iguales. Un miembro
     * sensible no puede controlarse indirectamente mediante un rol ordinario.
     */
    public function assertCanChangeRoleUsage(User $actor, Role $role, array $finalPermissionIds): void
    {
        $role = $this->freshRole($role);
        $before = $this->snapshot($actor);
        $this->assertCanReplacePermissions($actor, $role->permissions()->get()->modelKeys(), $finalPermissionIds);
        $this->assertRoleMembers($actor, $role);
        // P no amplía D; esta operación conserva Q y todos los roles del operador.
        $this->assertNoSelfEscalation($before, $before->permissionIds);
    }

    /**
     * Impide mejorar el nivel administrativo propio y comprueba todos los miembros afectados.
     *
     * El servicio debe validar además rango y jerarquía del candidateLevel antes de guardar.
     */
    public function assertCanChangeRoleLevel(User $actor, Role $role, int $candidateLevel): void
    {
        $role = $this->freshRole($role);
        $before = $this->snapshot($actor);
        if (! $before->admin && in_array($role->id, $before->roleIds, true) && $candidateLevel < $role->level) {
            $this->deny('A role level change cannot increase the actor administrative authority.');
        }
        $this->assertRoleMembers($actor, $role);
    }

    /**
     * Comprueba identidad sensible, control de cuenta y jerarquía de cada miembro del rol.
     *
     * Evita alterar cuentas fuera del alcance mediante un rol compartido aparentemente inocuo.
     */
    private function assertRoleMembers(User $actor, Role $role): void
    {
        // Usa el operador del guard de assertAdministrativeRole, incluso ante datos antiguos de varios guards.
        $hierarchyActor = $this->freshUser($actor);
        $hierarchyActor->setRelation('roles', $this->webRoles($actor));
        if (! $this->isAdmin($actor) && in_array($role->name, $this->sensitiveRoleNames(), true)) {
            $this->deny('Changing a sensitive role requires admin.');
        }
        foreach (Models::user()->whereHas('roles', fn ($query) => $query->whereKey($role->id))->get() as $member) {
            $this->assertCanControlAccount($actor, $member);
            if (! $this->isAdmin($actor) && ! $this->hierarchy->canEditUser($hierarchyActor, $member)) {
                $this->deny('Every affected role member must be administratively manageable.');
            }
        }
    }

    /**
     * Exige gestor, candidata activa y alcance sobre la unión de contenidos saliente/entrante.
     */
    private function assertPolicyWithin(DelegationAuthoritySnapshot $before, ?DelegationPolicy $current, ?DelegationPolicy $candidate): void
    {
        $this->assertManager($before);
        $candidate = $candidate ? $this->freshPolicy($candidate) : null;
        if ($candidate && ! $candidate->active) {
            $this->invalid('delegation_policy_id', 'The candidate policy must be active.');
        }
        $this->assertWithin($before, $this->union(
            $current ? $this->policyIds($current) : [],
            $candidate ? $this->policyIds($candidate) : [],
        ));
    }

    /**
     * Exige que todos los roles consumidores de la política sean administrables.
     */
    private function assertConsumers(User $actor, DelegationAuthoritySnapshot $before, DelegationPolicy $policy): void
    {
        foreach ($policy->roles()->get() as $role) {
            $this->assertAdministrativeRole($actor, $before, $this->freshRole($role));
        }
    }

    /**
     * Valida identidad sensible, ausencia de ambigüedad entre guards y jerarquía del consumidor.
     */
    private function assertAdministrativeRole(User $actor, DelegationAuthoritySnapshot $before, Role $role): void
    {
        // Conserva el ámbito del guard; la jerarquía también recarga roles persistidos por ID.
        $fresh = $this->freshUser($actor);
        $fresh->setRelation('roles', $this->webRoles($actor));
        // Rechaza identidades técnicas ambiguas según el contrato de guard único.
        if (! $before->admin) {
            foreach (Models::role()->whereIn('name', [...$before->roleNames, $role->name])->get() as $namedRole) {
                if ($namedRole->guard_name !== Models::guard()) {
                    $this->invalid('roles', 'Ambiguous multi-guard role identity in hierarchy.');
                }
            }
        }
        if (! $before->admin && (in_array($role->name, $this->sensitiveRoleNames(), true) || ! $this->hierarchy->canEditRole($fresh, $role))) {
            $this->deny('Every consumer role must be administratively manageable.');
        }
    }

    /**
     * Comprueba identidad transferible y alcance P/Q del rol contra una instantánea previa.
     */
    private function assertRoleWithin(DelegationAuthoritySnapshot $before, Role $role): void
    {
        $role = $this->freshRole($role);
        $ids = $this->roleTransferIds($role);
        if (! $before->admin && in_array($role->name, $this->sensitiveRoleNames(), true)) {
            $this->deny('A sensitive role cannot be transferred by a non-admin.');
        }
        $this->assertWithin($before, $ids);
    }

    /**
     * Une P y Q activa sin ocultar asignaciones de permisos con un guard inválido.
     */
    private function roleTransferIds(Role $role): array
    {
        // No descarta silenciosamente membresías de otro guard al autorizar una transferencia.
        $ids = $this->validIds($role->permissions()->get()->modelKeys());
        $policy = $role->delegationPolicy()->first();

        return $this->union($ids, $policy?->active ? $this->policyIds($policy) : []);
    }

    /**
     * Lee el pivot persistido y valida sus IDs, incluso para políticas inactivas.
     */
    private function policyIds(DelegationPolicy $policy): array
    {
        $policy = $this->freshPolicy($policy);

        return $this->validIds(DB::table('lpm_policy_permission')->where('delegation_policy_id', $policy->id)->pluck('permission_id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * Rechaza permisos fuera del D previo salvo que la instantánea sea administrativa.
     *
     * @throws AuthorizationException Si algún ID excede la autoridad previa.
     */
    private function assertWithin(DelegationAuthoritySnapshot $before, array $ids): void
    {
        if (! $before->admin && array_diff($ids, $before->permissionIds) !== []) {
            $this->deny('The operation exceeds the pre-write delegation authority.');
        }
    }

    /**
     * Exige identidad de administrador o gestor en la instantánea, sin ampliarle D.
     *
     * @throws AuthorizationException Si no existe identidad de gestor.
     */
    private function assertManager(DelegationAuthoritySnapshot $before): void
    {
        if (! $before->policyManager) {
            $this->deny('Policy management requires an admin or configured manager.');
        }
    }

    /**
     * Exige enteros positivos existentes del guard y devuelve IDs únicos.
     *
     * @throws ValidationException Si hay tipos, registros o guards inválidos.
     */
    private function validIds(array $ids): array
    {
        foreach ($ids as $id) {
            if (! is_int($id) || $id < 1) {
                $this->invalid('permissions', 'Permission IDs must be positive integers.');
            }
        }
        $ids = $this->union($ids);
        $found = $this->permissionQuery()->whereKey($ids)->get()->filter(fn ($permission) => $permission->guard_name === Models::guard())->modelKeys();
        if (count($found) !== count($ids)) {
            $this->invalid('permissions', 'Every permission must exist in guard web.');
        }

        return $ids;
    }

    /**
     * Une conjuntos de IDs, elimina duplicados por comparación regular y reindexa el resultado.
     */
    private function union(array ...$sets): array
    {
        return array_values(array_unique(array_merge([], ...$sets), SORT_REGULAR));
    }

    /**
     * Recarga roles del operador y conserva solo coincidencias exactas del guard configurado.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Role>
     */
    private function webRoles(User $actor): Collection
    {
        return $this->freshUser($actor)->roles()->get()->filter(fn (Role $role) => $role->guard_name === Models::guard())->values();
    }

    /**
     * Carga el catálogo y exige coincidencia exacta de guard incluso con collation no sensible a mayúsculas.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, Permission>
     */
    private function webPermissions(): Collection
    {
        // La comparación PHP es exacta aunque la collation no distinga mayúsculas.
        return $this->permissionQuery()->where('guard_name', Models::guard())->get()->filter(fn ($permission) => $permission->guard_name === Models::guard())->values();
    }

    /**
     * Recarga una cuenta persistida de la clase anfitriona y valida su conexión.
     *
     * @throws AuthorizationException Si la identidad o existencia de la cuenta no es válida.
     */
    private function freshUser(User $user): User
    {
        $query = Models::user();
        $class = config('permission-manager.user_model');
        Models::connection($user);
        $fresh = $user instanceof $class && $user->exists ? $query->find($user->getKey()) : null;
        if (! $fresh) {
            $this->deny('A persisted actor or target is required.');
        }

        return $fresh;
    }

    /**
     * Recarga un rol existente del modelo configurado y exige el guard RBAC.
     *
     * @throws ValidationException Si el rol no existe o pertenece a otro guard.
     */
    private function freshRole(Role $role): Role
    {
        $fresh = $role->exists ? Models::role()->find($role->getKey()) : null;
        if (! $fresh || $fresh->guard_name !== Models::guard()) {
            $this->invalid('roles', 'The role must exist in guard web.');
        }

        return $fresh;
    }

    /**
     * Recarga la política persistida para ignorar atributos o relaciones obsoletos.
     *
     * @throws ValidationException Si la política no existe.
     */
    private function freshPolicy(DelegationPolicy $policy): DelegationPolicy
    {
        $fresh = $policy->exists ? DelegationPolicy::query()->find($policy->getKey()) : null;
        if (! $fresh) {
            $this->invalid('delegation_policy_id', 'The policy must exist.');
        }

        return $fresh;
    }

    /**
     * Construye una consulta del modelo Permission configurado tras validar guard y conexión.
     *
     * @return Builder<Permission>
     */
    private function permissionQuery()
    {
        Models::guard();
        /** @var class-string<Permission> $model */
        $model = config('permission.models.permission');
        Models::connection(new $model);

        return $model::query();
    }

    /**
     * Propaga una denegación de autoridad con el motivo de dominio indicado.
     *
     * @throws AuthorizationException Siempre.
     */
    private function deny(string $message): never
    {
        throw new AuthorizationException($message);
    }

    /**
     * Asocia un error de validación al campo indicado e interrumpe la operación.
     *
     * @throws ValidationException Siempre.
     */
    private function invalid(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }
}
