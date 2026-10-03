<?php

namespace Ahinest\LaravelPermissionManager\Authorization;

use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Ahinest\LaravelPermissionManager\Services\PermissionDelegationService;
use Ahinest\LaravelPermissionManager\Services\RoleHierarchy;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Compone identidad persistida, entrada funcional, jerarquía y control del objetivo.
 * Los servicios escritores la invocan después de adquirir el bloqueo RBAC.
 */
class RbacMutationAuthorization
{
    /**
     * Compone las comprobaciones de delegación y jerarquía para autorizar mutaciones.
     */
    public function __construct(private readonly PermissionDelegationService $delegation, private readonly RoleHierarchy $hierarchy) {}

    /**
     * Recarga al operador y exige elegibilidad y la entrada funcional indicada por ability.
     *
     * Valida todos sus roles y calcula permisos desde relaciones persistidas; el administrador
     * exacto conserva su entrada global. No adquiere el lock: el servicio escritor debe hacerlo.
     *
     * @throws AuthorizationException Si falta elegibilidad o autoridad funcional.
     * @throws ValidationException Si un rol es ambiguo o pertenece a otro guard.
     */
    public function actor(Model $actor, string $ability): Model
    {
        $this->delegation->snapshot($actor); // Verifica la identidad persistida del modelo anfitrión.
        $actor = Models::user()->whereKey($actor->getKey())->firstOrFail();
        $this->require(app(ActorEligibility::class)->allows($actor));
        foreach ($actor->roles()->get() as $role) {
            $this->role($role);
        }
        $names = config('permission-manager.abilities', [])[$ability] ?? [];
        $effective = $actor->permissions()->get()->merge($actor->roles()->with('permissions')->get()->flatMap(fn ($role) => $role->permissions))
            ->filter(fn ($permission) => $permission->guard_name === Models::guard())->pluck('name')->all();
        $this->require($this->delegation->isAdmin($actor) || (is_array($names) && array_intersect($names, $effective) !== []));

        return $actor;
    }

    /**
     * Recarga un rol y exige una identidad inequívoca en el guard y conexión configurados.
     *
     * @throws ValidationException Si el rol no existe o su identidad es incompatible.
     */
    public function role(Role $role): Role
    {
        Models::connection($role);
        $role = $role->exists ? Models::role()->find($role->getKey()) : null;
        if (! $role || $role->guard_name !== Models::guard() || Models::role()->where('name', $role->name)->count() !== 1) {
            throw ValidationException::withMessages(['roles' => 'An unambiguous role in the configured guard is required.']);
        }

        return $role;
    }

    /**
     * Recarga la cuenta objetivo y comprueba su jerarquía y toda su autoridad actual.
     *
     * Incluye permisos directos, P, Q activa e identidades sensibles; no autoriza aún
     * una modificación concreta de atributos o asignaciones.
     *
     * @throws AuthorizationException Si el operador no puede controlar la cuenta.
     */
    public function target(Model $actor, Model $target): Model
    {
        $this->delegation->targetAuthorityFootprint($target);
        $target = Models::user()->whereKey($target->getKey())->firstOrFail();
        foreach ($target->roles()->get() as $role) {
            $this->role($role);
        }
        $this->require($this->hierarchy->canEditUser($actor, $target));
        $this->delegation->assertCanControlAccount($actor, $target);

        return $target;
    }

    /**
     * Exige accounts.control y comprueba el alcance sobre la cuenta persistida.
     *
     * Es una comprobación complementaria: el anfitrión debe autorizar también su operación
     * específica y coordinar la escritura; este método no inicia una transacción.
     */
    public function accountControl(Model $actor, Model $target): Model
    {
        return $this->target($this->actor($actor, 'accounts.control'), $target);
    }

    /**
     * Interrumpe la operación cuando una condición de autoridad no se cumple.
     *
     * @throws AuthorizationException Si allowed es false.
     */
    public function require(bool $allowed): void
    {
        if (! $allowed) {
            throw new AuthorizationException('Insufficient functional or hierarchical authority.');
        }
    }
}
