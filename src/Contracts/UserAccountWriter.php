<?php

namespace Ahinest\LaravelPermissionManager\Contracts;

use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Illuminate\Database\Eloquent\Model;

/**
 * Adaptador confiable del anfitrión para persistir exclusivamente atributos de cuenta.
 * Se invoca dentro de una operación autorizada y coordinada. No modifica RBAC,
 * no controla transacciones ni ejecuta efectos externos; valida su propia lista de atributos.
 */
interface UserAccountWriter
{
    /**
     * Persiste exclusivamente los atributos propios de la cuenta dentro de una operación autorizada.
     *
     * El anfitrión valida su lista de atributos permitidos. Para Create recibe un modelo nuevo;
     * las demás operaciones reciben el objetivo recargado. Delete puede usar borrado lógico.
     * Debe conservar la identidad y conexión, no modificar RBAC, no controlar transacciones
     * ni ejecutar efectos externos. Las tablas adicionales deben declararse para inspección.
     *
     * @param  Model  $target  Cuenta que debe persistirse o eliminarse según la operación.
     * @param  UserOperation  $operation  Operación de ciclo de vida autorizada.
     * @param  array<string, mixed>  $attributes  Atributos que el anfitrión debe validar y persistir.
     */
    public function persist(Model $target, UserOperation $operation, array $attributes): void;
}
