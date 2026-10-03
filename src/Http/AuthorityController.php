<?php

namespace Ahinest\LaravelPermissionManager\Http;

use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Services\AuthorityQueryService;
use Ahinest\LaravelPermissionManager\Services\DelegationPolicyService;
use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Http\Request;

/**
 * Adaptación HTTP de consultas y escrituras autorizadas, sin exponer secretos de cuentas.
 * Los servicios conservan la autorización de dominio; el middleware exige autenticación.
 */
class AuthorityController
{
    /**
     * Devuelve roles editables por jerarquía tras exigir roles.update, sin requerir roles.view.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function manageableRoles(Request $r, AuthorityQueryService $q)
    {
        return ['data' => $q->manageableRoles($r->user())];
    }

    /**
     * Devuelve políticas y capacidades para un gestor configurado, sin exigir policies.view.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function manageablePolicies(Request $r, AuthorityQueryService $q)
    {
        return ['data' => $q->manageablePolicies($r->user())];
    }

    /**
     * Devuelve el catálogo del guard con P, Q activa y capacidades, previa autorización roles.view.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function roles(Request $r, AuthorityQueryService $q)
    {
        return ['data' => $q->roles($r->user())];
    }

    /**
     * Devuelve IDs, nombres y descripciones del catálogo de permisos tras exigir permissions.view.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function permissions(Request $r, AuthorityQueryService $q)
    {
        return ['data' => $q->permissions($r->user())];
    }

    /**
     * Proyecta entradas funcionales y gestión de políticas para un operador persistido elegible.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function capabilities(Request $r, AuthorityQueryService $q)
    {
        return ['data' => $q->capabilities($r->user())];
    }

    /**
     * Devuelve el catálogo de políticas con relaciones y capacidades tras exigir policies.view.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function policies(Request $r, AuthorityQueryService $q)
    {
        return ['data' => $q->policies($r->user())];
    }

    /**
     * Devuelve el detalle y las capacidades de la política indicada, previa autorización policies.view.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function policy(Request $r, AuthorityQueryService $q, int $policy)
    {
        return ['data' => $q->policy($r->user(), $policy)];
    }

    /**
     * Devuelve roles a los que el gestor puede asociar la política activa indicada.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function assignablePolicyRoles(Request $r, AuthorityQueryService $q, int $policy)
    {
        return ['data' => $q->assignablePolicyRoles($r->user(), $policy)];
    }

    /**
     * Devuelve candidatos para agregar un rol conservando las membresías actuales.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function assignableRoles(Request $r, AuthorityQueryService $q, string $user)
    {
        return ['data' => $q->assignableRoles($r->user(), $user === 'new' ? null : Models::user()->findOrFail($user))];
    }

    /**
     * Describe roles, permisos directos, heredados, efectivos y capacidades del objetivo.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function userAuthority(Request $r, AuthorityQueryService $q, string $user)
    {
        return ['data' => $q->userAuthority($r->user(), Models::user()->findOrFail($user))];
    }

    /**
     * Calcula conjuntos grantable y revocable de permisos directos para el objetivo.
     *
     * Resuelve el operador autenticado de la petición y devuelve el resultado bajo data;
     * la autorización corresponde al servicio de consultas.
     */
    public function delegablePermissions(Request $r, AuthorityQueryService $q, string $user)
    {
        return ['data' => $q->delegablePermissions($r->user(), $user === 'new' ? null : Models::user()->findOrFail($user))];
    }

    /**
     * Delega la creación autorizada del rol y responde 201 con el recurso bajo data.
     */
    public function createRole(Request $r, RoleManagementService $s)
    {
        return response()->json(['data' => $s->create($r->user(), $r->all())], 201);
    }

    /**
     * Resuelve el rol por ID y delega la edición autorizada de los atributos enviados.
     */
    public function updateRole(Request $r, RoleManagementService $s, int $role)
    {
        return ['data' => $s->update($r->user(), Models::role()->findOrFail($role), $r->all())];
    }

    /**
     * Valida el array ids y delega el reemplazo autorizado de P del rol.
     */
    public function rolePermissions(Request $r, RoleManagementService $s, int $role)
    {
        return ['data' => $s->syncPermissions($r->user(), Models::role()->findOrFail($role), $this->ids($r))];
    }

    /**
     * Delega la creación autorizada de una política y responde 201 con su proyección pública.
     */
    public function createPolicy(Request $r, DelegationPolicyService $s)
    {
        return response()->json($this->policyResult($s->create($r->user(), $r->all())), 201);
    }

    /**
     * Resuelve la política y delega la actualización autorizada de name y/o active.
     */
    public function updatePolicy(Request $r, DelegationPolicyService $s, int $policy)
    {
        return $this->policyResult($s->update($r->user(), DelegationPolicy::findOrFail($policy), $r->all()));
    }

    /**
     * Valida ids y delega el reemplazo autorizado de permisos almacenados en la política.
     */
    public function policyPermissions(Request $r, DelegationPolicyService $s, int $policy)
    {
        return $this->policyResult($s->syncPermissions($r->user(), DelegationPolicy::findOrFail($policy), $this->ids($r)));
    }

    /**
     * Exige delegation_policy_id presente, entero positivo o null, y delega la asociación autorizada.
     */
    public function assignPolicy(Request $r, DelegationPolicyService $s, int $role)
    {
        $data = $r->validate(['delegation_policy_id' => ['present', 'nullable', 'integer', 'min:1']]);

        return ['data' => $s->assignToRole($r->user(), Models::role()->findOrFail($role), $data['delegation_policy_id'] === null ? null : (int) $data['delegation_policy_id'])];
    }

    /**
     * Valida ids y reemplaza roles mediante el servicio autorizado; devuelve solo asignaciones e ID.
     */
    public function replaceRoles(Request $r, UserPermissionManagementService $s, string $user)
    {
        return $this->memberships($s->replaceRoles($r->user(), Models::user()->findOrFail($user), $this->ids($r)));
    }

    /**
     * Valida ids y reemplaza permisos directos mediante el servicio; no expone atributos de cuenta.
     */
    public function replacePermissions(Request $r, UserPermissionManagementService $s, string $user)
    {
        return $this->memberships($s->replaceDirectPermissions($r->user(), Models::user()->findOrFail($user), $this->ids($r)));
    }

    /**
     * Delega el alta incremental del rol indicado y devuelve las membresías actualizadas.
     */
    public function assignRole(Request $r, UserPermissionManagementService $s, string $user, int $role)
    {
        return $this->memberships($s->assignRole($r->user(), Models::user()->findOrFail($user), $role));
    }

    /**
     * Delega la baja autorizada del rol indicado conservando las otras membresías.
     */
    public function removeRole(Request $r, UserPermissionManagementService $s, string $user, int $role)
    {
        return $this->memberships($s->removeRole($r->user(), Models::user()->findOrFail($user), $role));
    }

    /**
     * Delega la concesión incremental del permiso directo indicado al usuario resuelto.
     */
    public function grantPermission(Request $r, UserPermissionManagementService $s, string $user, int $permission)
    {
        return $this->memberships($s->grantDirectPermission($r->user(), Models::user()->findOrFail($user), $permission));
    }

    /**
     * Delega la revocación del permiso directo indicado sin afectar permisos heredados.
     */
    public function revokePermission(Request $r, UserPermissionManagementService $s, string $user, int $permission)
    {
        return $this->memberships($s->revokeDirectPermission($r->user(), Models::user()->findOrFail($user), $permission));
    }

    /**
     * Exige un array ids explícito con enteros positivos distintos y normaliza valores a int.
     *
     * Un array vacío expresa revocación; no valida aquí existencia o alcance de autoridad.
     */
    private function ids(Request $r): array
    {
        return array_map('intval', $r->validate(['ids' => ['present', 'array'], 'ids.*' => ['integer', 'min:1', 'distinct']])['ids']);
    }

    /**
     * Proyecta los campos públicos de una política y permisos del guard bajo la clave data.
     */
    private function policyResult(DelegationPolicy $policy): array
    {
        return ['data' => $policy->only(['id', 'name', 'slug', 'active']) + ['permissions' => $policy->permissions->filter(fn ($permission) => $permission->guard_name === Models::guard())->map->only(['id', 'name'])->values()]];
    }

    /**
     * Proyecta ID, roles y permisos directos de la cuenta, excluyendo atributos y secretos.
     */
    private function memberships($user): array
    {
        return ['data' => ['id' => $user->getKey(), 'roles' => $user->roles->map->only(['id', 'name']), 'direct_permissions' => $user->permissions->map->only(['id', 'name'])]];
    }
}
