<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Support\AuditState;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Escrituras autorizadas de políticas y asociaciones, coordinadas con auditoría y caché.
 */
class DelegationPolicyService
{
    /**
     * Combina las reglas de gestor/delegación con la frontera transaccional de escritura.
     */
    public function __construct(private readonly PermissionDelegationService $delegation, private readonly RbacWriteTransaction $writes) {}

    /**
     * Crea una política para un gestor autorizado con permisos iniciales dentro de su D.
     *
     * Valida name, slug, active y permissions; por defecto permanece inactiva y vacía.
     * Recarga autoridad bajo lock, registra la creación y devuelve la política con permisos.
     *
     * La frontera invalida la caché al terminar; los errores previos al commit revierten
     * los cambios y su auditoría. No usar dentro de una transacción externa MariaDB/MySQL.
     *
     * @throws AuthorizationException Si falta la autoridad requerida.
     * @throws ValidationException Si los datos o invariantes son inválidos.
     */
    public function create(Model $actor, array $data): DelegationPolicy
    {
        return $this->writes->run(function () use ($actor, $data) {
            $actor = $this->delegation->policyManagerActor($actor);
            $data = Validator::make($data, [
                'name' => ['required', 'string', 'max:255'],
                'slug' => ['required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:[-_][a-z0-9]+)*$/', 'unique:lpm_delegation_policies,slug'],
                'active' => ['sometimes', 'boolean'],
                'permissions' => ['sometimes', 'array'],
                'permissions.*' => ['integer', 'min:1', 'distinct'],
            ])->validate();
            $ids = $data['permissions'] ?? [];
            $this->delegation->assertCanReplacePermissions($actor, [], $ids);
            $policy = DelegationPolicy::create(collect($data)->only(['name', 'slug', 'active'])->all());
            $policy->permissions()->sync($ids);
            app(AuthorityAuditTrail::class)->record($actor->getKey(), 'delegation_policy', $policy->getKey(), 'delegation_policy.created', [], [...AuditState::policy($policy->fresh()), 'permissions' => AuditState::ids($policy->permissions()->get()->modelKeys())]);

            return $policy->fresh('permissions');
        });
    }

    /**
     * Actualiza name y/o active de una política para un gestor autorizado.
     *
     * slug y permissions no se aceptan aquí. Cambiar active comprueba el contenido
     * almacenado y todos los consumidores/miembros, incluso al desactivar.
     * Opera bajo lock y audita solo diferencias; devuelve la política recargada.
     *
     * La frontera invalida la caché al terminar; los errores previos al commit revierten
     * los cambios y su auditoría. No usar dentro de una transacción externa MariaDB/MySQL.
     *
     * @throws AuthorizationException Si falta la autoridad requerida.
     * @throws ValidationException Si los datos o invariantes son inválidos.
     */
    public function update(Model $actor, DelegationPolicy $policy, array $data): DelegationPolicy
    {
        return $this->writes->run(function () use ($actor, $policy, $data) {
            $actor = $this->delegation->policyManagerActor($actor);
            $policy = DelegationPolicy::findOrFail($policy->getKey());
            $data = Validator::make($data, ['name' => ['sometimes', 'required', 'string', 'max:255'], 'active' => ['sometimes', 'boolean'], 'slug' => ['missing'], 'permissions' => ['missing']])->validate();
            if (array_key_exists('active', $data)) {
                $this->delegation->assertCanMutatePolicy($actor, $policy, $policy->permissions()->get()->modelKeys(), (bool) $data['active']);
            }
            $before = AuditState::policy($policy);
            $policy->fill($data)->save();
            app(AuthorityAuditTrail::class)->record($actor->getKey(), 'delegation_policy', $policy->getKey(), 'delegation_policy.updated', $before, AuditState::policy($policy->fresh()));

            return $policy->fresh('permissions');
        });
    }

    /**
     * Reemplaza Q almacenado comprobando permisos actuales/finales y consumidores afectados.
     *
     * ids contiene enteros existentes del guard. Incluso inactiva exige alcance completo.
     * Opera bajo lock, rechaza autoescalamiento y devuelve la política con permisos actualizados.
     *
     * La frontera invalida la caché al terminar; los errores previos al commit revierten
     * los cambios y su auditoría. No usar dentro de una transacción externa MariaDB/MySQL.
     *
     * @throws AuthorizationException Si falta la autoridad requerida.
     * @throws ValidationException Si los datos o invariantes son inválidos.
     */
    public function syncPermissions(Model $actor, DelegationPolicy $policy, array $ids): DelegationPolicy
    {
        return $this->writes->run(function () use ($actor, $policy, $ids) {
            $actor = $this->delegation->policyManagerActor($actor);
            $policy = DelegationPolicy::findOrFail($policy->getKey());
            $this->delegation->assertCanMutatePolicy($actor, $policy, $ids, $policy->active);
            $before = ['permissions' => AuditState::ids($policy->permissions()->get()->modelKeys())];
            $policy->permissions()->sync($ids);
            app(AuthorityAuditTrail::class)->record($actor->getKey(), 'delegation_policy', $policy->getKey(), 'delegation_policy.permissions.changed', $before, ['permissions' => AuditState::ids($policy->permissions()->get()->modelKeys())]);

            return $policy->fresh('permissions');
        });
    }

    /**
     * Asocia o desvincula una política de un rol bajo las reglas del gestor y el lock común.
     *
     * policyId null desvincula. La candidata debe existir y estar activa; se comprueban
     * contenidos saliente/entrante y miembros. Un no administrador no cambia políticas
     * de roles propios, ni siquiera por la misma. Devuelve el rol recargado con su política.
     *
     * La frontera invalida la caché al terminar; los errores previos al commit revierten
     * los cambios y su auditoría. No usar dentro de una transacción externa MariaDB/MySQL.
     *
     * @throws AuthorizationException Si falta la autoridad requerida.
     * @throws ValidationException Si los datos o invariantes son inválidos.
     */
    public function assignToRole(Model $actor, Role $role, ?int $policyId): Role
    {
        return $this->writes->run(function () use ($actor, $role, $policyId) {
            $actor = $this->delegation->policyManagerActor($actor);
            $role = Models::role()->findOrFail($role->getKey());
            $policy = $policyId === null ? null : DelegationPolicy::find($policyId);
            if ($policyId !== null && ! $policy) {
                throw ValidationException::withMessages(['delegation_policy_id' => 'Policy must exist.']);
            }
            $this->delegation->assertCanAssignPolicyToRole($actor, $role, $policy);
            $before = ['delegation_policy_id' => $role->delegation_policy_id === null ? null : (int) $role->delegation_policy_id];
            $role->delegationPolicy()->associate($policy);
            $role->save();
            app(AuthorityAuditTrail::class)->record($actor->getKey(), 'role', $role->getKey(), 'role.delegation_policy.changed', $before, ['delegation_policy_id' => $role->fresh()->delegation_policy_id === null ? null : (int) $role->fresh()->delegation_policy_id]);

            return $role->fresh('delegationPolicy');
        });
    }
}
