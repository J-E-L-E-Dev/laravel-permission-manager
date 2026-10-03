<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;

/**
 * Evalúa elegibilidad persistida y protege la continuidad del último administrador global.
 */
class EligibleAdministrator
{
    /**
     * Determina si la cuenta existe, es elegible y conserva administración global según los contratos.
     */
    public function qualifies(Model $user): bool
    {
        return $user->exists && app(ActorEligibility::class)->allows($user) && app(PermissionDelegationService::class)->isAdmin($user);
    }

    /**
     * Busca al menos un administrador elegible, excluyendo opcionalmente el ID except.
     *
     * Filtra poseedores del rol en SQL y evalúa el contrato anfitrión en bloques de 100;
     * no aproxima la elegibilidad con una columna específica del anfitrión.
     */
    public function exists(int|string|null $except = null): bool
    {
        $name = config('permission-manager.admin_role');
        if (! is_string($name) || $name === '') {
            return false;
        }
        foreach (Models::user()->when($except !== null, fn ($q) => $q->whereKeyNot($except))->whereHas('roles', fn ($q) => $q->where('name', $name)->where('guard_name', Models::guard()))->lazyById(100) as $user) {
            if ($this->qualifies($user)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Impide perder el último administrador cuando el objetivo era elegible antes del cambio.
     *
     * Se invoca después de persistir bajo el lock común; no exige administrador inicial.
     *
     * @throws ValidationException Si la modificación deja sin administrador elegible.
     */
    public function protect(bool $wasEligibleAdmin): void
    {
        if ($wasEligibleAdmin && ! $this->exists()) {
            throw ValidationException::withMessages(['roles' => 'Cannot remove the last eligible global administrator.']);
        }
    }
}
