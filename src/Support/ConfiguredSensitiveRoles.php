<?php

namespace Ahinest\LaravelPermissionManager\Support;

use Ahinest\LaravelPermissionManager\Contracts\SensitiveRoleResolver;

/**
 * Resuelve identidades sensibles desde la configuración explícita del anfitrión.
 */
class ConfiguredSensitiveRoles implements SensitiveRoleResolver
{
    /**
     * Devuelve los nombres sensibles configurados por el anfitrión, sin descubrir ni modificar roles.
     */
    public function names(): array
    {
        return config('permission-manager.sensitive_role_names', []);
    }
}
