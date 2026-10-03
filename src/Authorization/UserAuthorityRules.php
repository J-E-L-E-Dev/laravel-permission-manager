<?php

namespace Ahinest\LaravelPermissionManager\Authorization;

use Ahinest\LaravelPermissionManager\Contracts\AdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Services\RoleHierarchy;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * Comprobaciones de lectura compartidas por ciclo de cuentas, asignaciones y candidatos.
 * Las proyecciones informativas no sustituyen una nueva autorización al escribir.
 */
class UserAuthorityRules
{
    /**
     * Valida los roles actuales y finales sin escribir membresías.
     *
     * final contiene IDs de roles. Comprueba P/Q, identidades administrativas y jerarquía
     * de la unión actual/final; si el objetivo es el operador, impide ampliar su D.
     * El escritor debe volver a ejecutar esta comprobación dentro del lock común.
     */
    public function roles(Model $actor, ?Model $target, array $final): void
    {
        $engine = app(PermissionDelegationService::class);
        $authorization = app(RbacMutationAuthorization::class);
        $current = $target?->roles()->get()->modelKeys() ?? [];
        $before = $engine->snapshot($actor);
        $engine->assertCanReplaceRoles($actor, $current, $final);
        app(AdministrativeIdentity::class)->assertRoles($actor, $target, $final);
        foreach (array_unique([...$current, ...$final]) as $id) {
            $role = $authorization->role(Models::role()->findOrFail($id));
            $authorization->require(app(RoleHierarchy::class)->canEditRole($actor, $role));
        }
        if ($target && $actor->is($target)) {
            $engine->assertNoSelfEscalation($before, $engine->candidateRoleAuthorityIds($final));
        }
    }

    /**
     * Valida que los permisos directos actuales y finales estén dentro del D previo.
     *
     * final contiene IDs existentes del guard configurado; un objetivo null representa
     * una cuenta nueva. No persiste relaciones ni concede permisos heredados.
     */
    public function permissions(Model $actor, ?Model $target, array $final): void
    {
        app(PermissionDelegationService::class)->assertCanReplacePermissions($actor, $target?->permissions()->get()->modelKeys() ?? [], $final);
    }
}
