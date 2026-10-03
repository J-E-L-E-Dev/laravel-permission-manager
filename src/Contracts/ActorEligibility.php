<?php

namespace Ahinest\LaravelPermissionManager\Contracts;

use Illuminate\Database\Eloquent\Model;

/**
 * Contrato anfitrión de elegibilidad de cuentas para actuar y conservar administración.
 * La implementación consulta estado persistido, sin mutaciones ni autoridad implícita.
 * Puede ejecutarse en lecturas y dentro del lock; no debe depender de una petición HTTP.
 */
interface ActorEligibility
{
    /**
     * Determina si una cuenta persistida puede actuar y contar como administrador elegible.
     *
     * La implementación debe evaluar estado actual sin escribir ni conceder autoridad RBAC.
     * Se usa tanto al autorizar operaciones como al buscar administradores supervivientes.
     *
     * @param  Model  $actor  Cuenta persistida evaluada por el paquete.
     * @return bool True si satisface los requisitos de elegibilidad del anfitrión.
     */
    public function allows(Model $actor): bool;
}
