<?php

namespace Ahinest\LaravelPermissionManager\Authorization;

/**
 * Instantánea inmutable de autoridad previa para comprobaciones del código confiable.
 * Nunca construir desde un payload HTTP ni conservar como autorización entre operaciones.
 */
final readonly class DelegationAuthoritySnapshot
{
    /**
     * Captura la autoridad persistida anterior a una escritura, sin volver a consultarla.
     *
     * actorId identifica al operador; admin y policyManager representan sus identidades.
     * roleIds y roleNames describen sus roles; usageByRole y delegableByRole contienen
     * P y Q por rol; permissionIds contiene D y sensitiveRoleNames sus identidades sensibles.
     * Solo código confiable debe construir esta instantánea y usarla en la misma evaluación.
     */
    public function __construct(
        public int|string $actorId,
        public bool $admin,
        public bool $policyManager,
        public array $roleIds,
        public array $roleNames,
        public array $usageByRole,
        public array $delegableByRole,
        public array $permissionIds,
        public array $sensitiveRoleNames,
    ) {}
}
