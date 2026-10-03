<?php

namespace Ahinest\LaravelPermissionManager\Contracts;

/**
 * Contrato anfitrión para identidades cualitativas que requieren control administrativo.
 * Devuelve nombres frescos sin escribir datos ni convertirlos en permisos funcionales.
 */
interface SensitiveRoleResolver
{
    /**
     * Devuelve identidades sensibles actuales del anfitrión sin modificar RBAC.
     *
     * Estos privilegios cualitativos no se reducen a IDs de permisos. Consultar datos
     * frescos y coordinar las escrituras que cambien estas identidades.
     *
     * @return list<string> Nombres técnicos exactos de roles sensibles.
     */
    public function names(): array;
}
