<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Reglas jerárquicas basadas en niveles persistidos y administración global explícita.
 */
class RoleHierarchy
{
    /**
     * Comprueba si todos los roles persistidos del objetivo están por debajo del alcance del operador.
     *
     * Para cuentas sin roles exige nivel válido del operador o administración global.
     * Es solo jerarquía; la autorización completa también exige entrada y delegación.
     */
    public function canEditUser(Model $operator, Model $target): bool
    {
        $target = Models::user()->whereKey($target->getKey())->firstOrFail();
        $roles = $target->roles()->get();

        return $roles->isEmpty()
            ? $this->operatorLevel($operator) !== null || $this->admin($operator)
            : $roles->every(fn ($role) => $this->canEditRole($operator, $role));
    }

    /**
     * Evalúa la jerarquía del rol persistido en el guard configurado.
     *
     * Niveles menores tienen mayor autoridad; la excepción de igual nivel solo admite
     * el mismo rol propio si está habilitada. No sustituye los controles P/Q/D o de identidad.
     */
    public function canEditRole(Model $operator, Role $role): bool
    {
        $role = Models::role()->find($role->getKey());
        if (! $role || $role->guard_name !== Models::guard()) {
            return false;
        }
        if ($this->admin($operator)) {
            return true;
        }
        $level = $this->operatorLevel($operator);
        if ($level === null || ! $this->validLevel($role->level)) {
            return false;
        }

        return $level < (int) $role->level || ($level === (int) $role->level
            && config('permission-manager.levels.allow_same_owned_role', false)
            && $this->roles($operator)->contains('id', $role->getKey()));
    }

    /**
     * Comprueba si level es válido y administrable, incluida la jerarquía del rol actual target.
     *
     * La excepción de igual nivel requiere un rol propio existente; no permite crear pares arbitrarios.
     */
    public function canManageRoleLevel(Model $operator, int $level, ?Role $target = null): bool
    {
        if (! $this->validLevel($level) || ($target && ! $this->canEditRole($operator, $target))) {
            return false;
        }
        if ($this->admin($operator)) {
            return true;
        }
        $own = $this->operatorLevel($operator);

        return $own !== null && ($level > $own || ($target && $level === $own
            && config('permission-manager.levels.allow_same_owned_role', false)
            && $this->roles($operator)->contains('id', $target->getKey())));
    }

    /**
     * Determina si el valor representa un entero no nulo dentro del rango inclusivo configurado.
     */
    public function validLevel(mixed $level): bool
    {
        return filter_var($level, FILTER_VALIDATE_INT) !== false && $level !== null
            && (int) $level >= config('permission-manager.levels.min', 1)
            && (int) $level <= config('permission-manager.levels.max', 100);
    }

    /**
     * Recarga los roles del operador y conserva únicamente los del guard RBAC configurado.
     */
    private function roles(Model $operator)
    {
        return Models::user()->whereKey($operator->getKey())->firstOrFail()->roles()->get()
            ->filter(fn ($role) => $role->guard_name === Models::guard());
    }

    /**
     * Consulta la identidad administrativa persistida a través del motor de delegación.
     */
    private function admin(Model $operator): bool
    {
        return app(PermissionDelegationService::class)->isAdmin($operator);
    }

    /**
     * Obtiene el menor nivel de los roles persistidos del guard.
     *
     * Devuelve null si no hay roles o alguno carece de nivel válido, evitando autoridad parcial.
     */
    private function operatorLevel(Model $operator): ?int
    {
        $roles = $this->roles($operator);
        if ($roles->isEmpty() || $roles->contains(fn ($role) => ! $this->validLevel($role->level))) {
            return null;
        }

        return (int) $roles->min('level');
    }
}
