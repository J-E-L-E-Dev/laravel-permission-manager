<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Authorization\RbacMutationAuthorization;
use Ahinest\LaravelPermissionManager\Authorization\UserAuthorityRules;
use Ahinest\LaravelPermissionManager\Events\UserAuthorityChanged;
use Ahinest\LaravelPermissionManager\Support\AuditContext;
use Ahinest\LaravelPermissionManager\Support\AuditState;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Asignaciones completas e incrementales con autorización, auditoría y protección administrativa.
 */
class UserPermissionManagementService
{
    /**
     * Reemplaza todos los roles del objetivo por roleIds bajo autorización users.roles.
     *
     * [] revoca explícitamente todos; se validan roles actuales y finales, jerarquía,
     * identidad y D previo, incluida la prohibición de autoescalamiento.
     *
     * La operación es transaccional, protege al último administrador y registra solo cambios
     * efectivos de autoridad. Invalida caché al finalizar y devuelve el objetivo recargado.
     *
     * @throws AuthorizationException Si falta autoridad funcional, jerárquica o delegable.
     * @throws ValidationException Si los IDs o las invariantes son inválidos.
     */
    public function replaceRoles(Model $actor, Model $target, array $roleIds): Model
    {
        return $this->mutate($actor, $target, 'roles', $roleIds);
    }

    /**
     * Reemplaza permisos directos por permissionIds tras exigir users.permissions.
     *
     * [] revoca los directos; no convierte ni elimina permisos heredados. Tanto los
     * permisos retirados como conservados y añadidos deben estar dentro del D previo.
     *
     * La operación es transaccional, protege al último administrador y registra solo cambios
     * efectivos de autoridad. Invalida caché al finalizar y devuelve el objetivo recargado.
     *
     * @throws AuthorizationException Si falta autoridad funcional, jerárquica o delegable.
     * @throws ValidationException Si los IDs o las invariantes son inválidos.
     */
    public function replaceDirectPermissions(Model $actor, Model $target, array $permissionIds): Model
    {
        return $this->mutate($actor, $target, 'permissions', $permissionIds);
    }

    /**
     * Añade roleId al conjunto leído bajo lock sin perder otras asignaciones concurrentes.
     *
     * Exige users.roles y autoridad sobre el objetivo y el rol, incluidos P/Q e identidad.
     *
     * La operación es transaccional, protege al último administrador y registra solo cambios
     * efectivos de autoridad. Invalida caché al finalizar y devuelve el objetivo recargado.
     *
     * @throws AuthorizationException Si falta autoridad funcional, jerárquica o delegable.
     * @throws ValidationException Si los IDs o las invariantes son inválidos.
     */
    public function assignRole(Model $actor, Model $target, int $roleId): Model
    {
        return $this->mutate($actor, $target, 'roles', [$roleId], 'add');
    }

    /**
     * Retira roleId conservando las demás membresías leídas bajo lock.
     *
     * Incluso si no estaba asignado, exige un rol existente, del guard y dentro del alcance.
     *
     * La operación es transaccional, protege al último administrador y registra solo cambios
     * efectivos de autoridad. Invalida caché al finalizar y devuelve el objetivo recargado.
     *
     * @throws AuthorizationException Si falta autoridad funcional, jerárquica o delegable.
     * @throws ValidationException Si los IDs o las invariantes son inválidos.
     */
    public function removeRole(Model $actor, Model $target, int $roleId): Model
    {
        return $this->mutate($actor, $target, 'roles', [$roleId], 'remove');
    }

    /**
     * Añade permissionId como permiso directo, conservando el conjunto leído bajo lock.
     *
     * Exige users.permissions, control de la cuenta y permiso dentro del D previo.
     *
     * La operación es transaccional, protege al último administrador y registra solo cambios
     * efectivos de autoridad. Invalida caché al finalizar y devuelve el objetivo recargado.
     *
     * @throws AuthorizationException Si falta autoridad funcional, jerárquica o delegable.
     * @throws ValidationException Si los IDs o las invariantes son inválidos.
     */
    public function grantDirectPermission(Model $actor, Model $target, int $permissionId): Model
    {
        return $this->mutate($actor, $target, 'permissions', [$permissionId], 'add');
    }

    /**
     * Retira permissionId de los permisos directos sin alterar los heredados.
     *
     * Aunque no esté asignado, valida existencia, guard y autoridad sobre el permiso.
     *
     * La operación es transaccional, protege al último administrador y registra solo cambios
     * efectivos de autoridad. Invalida caché al finalizar y devuelve el objetivo recargado.
     *
     * @throws AuthorizationException Si falta autoridad funcional, jerárquica o delegable.
     * @throws ValidationException Si los IDs o las invariantes son inválidos.
     */
    public function revokeDirectPermission(Model $actor, Model $target, int $permissionId): Model
    {
        return $this->mutate($actor, $target, 'permissions', [$permissionId], 'remove');
    }

    /**
     * Compone reemplazo, alta o baja sobre roles o permisos desde estado persistido.
     *
     * Autoriza dentro del lock, valida el conjunto actual/final, preserva al último
     * administrador y audita diferencias. Emite UserAuthorityChanged incluso sin cambios
     * y devuelve la cuenta con roles/permisos frescos; la frontera invalida caché al finalizar.
     */
    private function mutate(Model $actor, Model $target, string $kind, array $ids, string $mode = 'replace'): Model
    {
        return app(RbacWriteTransaction::class)->run(function () use ($actor, $target, $kind, $ids, $mode) {
            $authorization = app(RbacMutationAuthorization::class);
            $actor = $authorization->actor($actor, 'users.'.$kind);
            $target = $authorization->target($actor, $target);
            $current = $target->{$kind}()->get()->modelKeys();
            $rules = app(UserAuthorityRules::class);
            // Incluso un ID no asignado que se retira debe existir en el guard y estar dentro del alcance.
            if ($mode !== 'replace') {
                $rules->{$kind}($actor, $target, array_values(array_unique([...$current, ...$ids])));
            }
            $final = match ($mode) {
                'add' => array_values(array_unique([...$current, ...$ids])),
                'remove' => array_values(array_diff($current, $ids)),
                default => $ids,
            };
            $rules->{$kind}($actor, $target, $final);
            $admins = app(EligibleAdministrator::class);
            $wasAdmin = $admins->qualifies($target);
            if ($kind === 'roles') {
                $target->syncRoles(Models::role()->whereKey($final)->get());
            } else {
                $target->syncPermissions($final);
            }
            $admins->protect($wasAdmin);
            $field = $kind === 'roles' ? 'roles' : 'direct_permissions';
            $audit = app(AuthorityAuditTrail::class)->record($actor->getKey(), 'user', $target->getKey(), 'user.'.$kind.'.'.$mode,
                [$field => AuditState::ids($current)], [$field => AuditState::ids($target->{$kind}()->get()->modelKeys())]);
            event(new UserAuthorityChanged($actor->getKey(), $target->getKey(), $kind.'.'.$mode, $audit, app(AuditContext::class)->correlationId(), false));

            return $target->fresh(['roles', 'permissions']);
        });
    }
}
