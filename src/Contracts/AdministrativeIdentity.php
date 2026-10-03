<?php

namespace Ahinest\LaravelPermissionManager\Contracts;

use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Contrato opcional del anfitrión para invariantes adicionales de identidad administrativa.
 * Sus comprobaciones son de solo lectura y complementan P/Q/D. En escrituras se invoca
 * bajo el lock; el descubrimiento también consulta sus reglas sin autorizar cambios.
 * No debe mutar datos, controlar transacciones ni llamar recursivamente al motor.
 */
interface AdministrativeIdentity
{
    /**
     * Valida la coherencia de identidad del operador persistido.
     *
     * Se invoca al evaluar administración global; debe consultar estado fresco sin mutarlo
     * ni llamar recursivamente al motor de delegación.
     *
     * @throws AuthorizationException Si el operador viola la identidad exigida.
     */
    public function assertActor(Model $actor): void;

    /**
     * Determina si la identidad puede ejercer el privilegio administrativo global.
     *
     * True no basta por sí solo: el paquete exige además el rol administrativo exacto.
     * False no transforma ese rol en una identidad de gestor de políticas.
     */
    public function allowsGlobalAdministration(Model $actor): bool;

    /**
     * Aplica invariantes de identidad al control de una cuenta objetivo.
     *
     * Se evalúa también sobre miembros afectados por roles o políticas compartidos.
     * Debe rechazar el control prohibido sin modificar cuentas ni asignaciones.
     */
    public function assertAccountControl(Model $actor, Model $target): void;

    /**
     * Valida identidad, operación y atributos antes de invocar el escritor de cuentas.
     *
     * El objetivo es null únicamente al crear. Se ejecuta bajo el lock común; no debe
     * persistir datos ni reemplazar la validación de atributos del anfitrión.
     */
    public function assertAccountMutation(Model $actor, ?Model $target, UserOperation $operation, array $attributes): void;

    /**
     * Valida el conjunto final de roles contra las invariantes administrativas del anfitrión.
     *
     * Se usa en escrituras y candidatos informativos; no presupone siempre un lock.
     * final contiene IDs, y target puede ser null para una cuenta nueva. No altera relaciones.
     */
    public function assertRoles(Model $actor, ?Model $target, array $final): void;

    /**
     * Valida la identidad y los atributos de un rol antes de crearlo o actualizarlo.
     *
     * role es null durante la creación y attributes contiene los campos validados.
     * Se ejecuta bajo el lock; no debe modificar RBAC ni sustituir las reglas P/Q/D.
     */
    public function assertRoleMutation(Model $actor, ?Role $role, array $attributes): void;
}
