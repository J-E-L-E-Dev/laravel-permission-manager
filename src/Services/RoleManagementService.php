<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Authorization\RbacMutationAuthorization;
use Ahinest\LaravelPermissionManager\Contracts\AdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Support\AuditState;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Models\Role;

/**
 * Creación y modificación autorizadas de roles y permisos funcionales compartidos.
 */
class RoleManagementService
{
    /**
     * Combina transacción, entrada funcional, delegación y jerarquía para escrituras de roles.
     */
    public function __construct(
        private readonly RbacWriteTransaction $writes,
        private readonly RbacMutationAuthorization $authorization,
        private readonly PermissionDelegationService $delegation,
        private readonly RoleHierarchy $hierarchy,
    ) {}

    /**
     * Crea un rol sin permisos ni política tras exigir roles.create y un nivel administrable.
     *
     * Valida identidad reservada y atributos bajo el lock común. Registra creación efectiva
     * y devuelve el rol; la asignación de permisos es una operación autorizada separada.
     *
     * La frontera invalida la caché al terminar; los errores previos al commit revierten
     * los cambios y su auditoría. No usar dentro de una transacción externa MariaDB/MySQL.
     *
     * @throws AuthorizationException Si falta la autoridad requerida.
     * @throws ValidationException Si los datos o invariantes son inválidos.
     */
    public function create(Model $actor, array $data): Role
    {
        return $this->writes->run(function () use ($actor, $data) {
            $actor = $this->authorization->actor($actor, 'roles.create');
            $data = $this->validate($data);
            app(AdministrativeIdentity::class)->assertRoleMutation($actor, null, $data);
            $this->authorization->require($this->hierarchy->canManageRoleLevel($actor, (int) $data['level']));
            $this->delegation->assertCanUseRoleIdentity($actor, null, $data['name']);

            // Los permisos funcionales se asignan mediante una operación autorizada separada.
            $role = Models::role()->create([...$data, 'guard_name' => Models::guard()]);
            app(AuthorityAuditTrail::class)->record($actor->getKey(), 'role', $role->getKey(), 'role.created', [], AuditState::role($role->fresh()));

            return $role;
        });
    }

    /**
     * Actualiza atributos permitidos del rol tras exigir roles.update y jerarquía suficiente.
     *
     * Recarga bajo lock, impide renombrar admin y controla identidades sensibles y miembros
     * afectados por cambios de nivel. Audita diferencias y devuelve el rol actualizado.
     *
     * La frontera invalida la caché al terminar; los errores previos al commit revierten
     * los cambios y su auditoría. No usar dentro de una transacción externa MariaDB/MySQL.
     *
     * @throws AuthorizationException Si falta la autoridad requerida.
     * @throws ValidationException Si los datos o invariantes son inválidos.
     */
    public function update(Model $actor, Role $role, array $data): Role
    {
        return $this->writes->run(function () use ($actor, $role, $data) {
            $actor = $this->authorization->actor($actor, 'roles.update');
            $role = $this->authorization->role($role);
            $data = $this->validate($data, $role);
            app(AdministrativeIdentity::class)->assertRoleMutation($actor, $role, $data);
            $level = $data['level'] ?? $role->level;
            if ($level === null) {
                throw ValidationException::withMessages(['level' => 'Assign an explicit level before managing this role.']);
            }
            $this->authorization->require($this->hierarchy->canManageRoleLevel($actor, (int) $level, $role));
            if (array_key_exists('name', $data)) {
                if ($role->name === config('permission-manager.admin_role') && $data['name'] !== $role->name) {
                    throw ValidationException::withMessages(['name' => 'Rename the global admin identity only through a reviewed infrastructure migration.']);
                }
                $this->delegation->assertCanUseRoleIdentity($actor, $role, $data['name']);
            }
            if ((int) $level !== (int) $role->level) {
                $this->delegation->assertCanChangeRoleLevel($actor, $role, (int) $level);
            }
            $before = AuditState::role($role);
            $role->fill($data)->save();
            app(AuthorityAuditTrail::class)->record($actor->getKey(), 'role', $role->getKey(), 'role.updated', $before, AuditState::role($role->fresh()));

            return $role->fresh();
        });
    }

    /**
     * Reemplaza P del rol con IDs del guard tras exigir roles.permissions y alcance completo.
     *
     * Comprueba permisos actuales/finales y todos los miembros bajo lock; no cambia Q.
     * Registra diferencias efectivas y devuelve el rol con permisos recargados.
     *
     * La frontera invalida la caché al terminar; los errores previos al commit revierten
     * los cambios y su auditoría. No usar dentro de una transacción externa MariaDB/MySQL.
     *
     * @throws AuthorizationException Si falta la autoridad requerida.
     * @throws ValidationException Si los datos o invariantes son inválidos.
     */
    public function syncPermissions(Model $actor, Role $role, array $ids): Role
    {
        return $this->writes->run(function () use ($actor, $role, $ids) {
            $actor = $this->authorization->actor($actor, 'roles.permissions');
            $role = $this->authorization->role($role);
            $this->authorization->require($this->hierarchy->canEditRole($actor, $role));
            $this->delegation->assertCanChangeRoleUsage($actor, $role, $ids);
            $before = ['permissions' => AuditState::ids($role->permissions()->get()->modelKeys())];
            $role->syncPermissions($ids);
            app(AuthorityAuditTrail::class)->record($actor->getKey(), 'role', $role->getKey(), 'role.permissions.changed', $before, ['permissions' => AuditState::ids($role->permissions()->get()->modelKeys())]);

            return $role->fresh('permissions');
        });
    }

    /**
     * Valida name, description y level según creación o actualización.
     *
     * Rechaza guard_name, permissions y delegation_policy_id para impedir modificaciones
     * fuera de sus operaciones autorizadas; devuelve solo los atributos validados.
     */
    private function validate(array $data, ?Role $role = null): array
    {
        return Validator::make($data, [
            'name' => [$role ? 'sometimes' : 'required', 'string', 'max:255', 'regex:/^[a-z0-9]+(?:[-_.][a-z0-9]+)*$/', Rule::unique(config('permission.table_names.roles'), 'name')->ignore($role?->getKey())],
            'description' => ['sometimes', 'nullable', 'string', 'max:255'],
            'level' => [$role ? 'sometimes' : 'required', 'integer', 'min:'.config('permission-manager.levels.min'), 'max:'.config('permission-manager.levels.max')],
            'guard_name' => ['missing'],
            'permissions' => ['missing'],
            'delegation_policy_id' => ['missing'],
        ])->validate();
    }
}
