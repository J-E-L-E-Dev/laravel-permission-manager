<?php

namespace Ahinest\LaravelPermissionManager\Support;

use Ahinest\LaravelPermissionManager\Contracts\AdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Implementación predeterminada sin invariantes de identidad adicionales.
 * No concede autoridad por sí misma ni elimina controles funcionales, jerárquicos o P/Q/D.
 */
class UnrestrictedAdministrativeIdentity implements AdministrativeIdentity
{
    /**
     * No añade restricciones de identidad al operador; siguen vigentes las reglas RBAC del paquete.
     */
    public function assertActor(Model $actor): void {}

    /**
     * Permite evaluar el privilegio global por el rol configurado, sin concederlo por sí mismo.
     */
    public function allowsGlobalAdministration(Model $actor): bool
    {
        return true;
    }

    /**
     * No añade invariantes anfitrionas al control de cuenta; no omite jerarquía ni delegación.
     */
    public function assertAccountControl(Model $actor, Model $target): void {}

    /**
     * No restringe adicionalmente atributos o ciclo de vida; la operación sigue requiriendo autorización.
     */
    public function assertAccountMutation(Model $actor, ?Model $target, UserOperation $operation, array $attributes): void {}

    /**
     * No añade restricciones de identidad al conjunto final; P/Q/D y jerarquía siguen aplicándose.
     */
    public function assertRoles(Model $actor, ?Model $target, array $final): void {}

    /**
     * No añade invariantes anfitrionas a la creación o actualización del rol.
     */
    public function assertRoleMutation(Model $actor, ?Role $role, array $attributes): void {}
}
