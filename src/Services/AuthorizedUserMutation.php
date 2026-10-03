<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Authorization\RbacMutationAuthorization;
use Ahinest\LaravelPermissionManager\Authorization\UserAuthorityRules;
use Ahinest\LaravelPermissionManager\Contracts\AdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Contracts\UserAccountWriter;
use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Ahinest\LaravelPermissionManager\Events\UserAuthorityChanged;
use Ahinest\LaravelPermissionManager\Support\AuditContext;
use Ahinest\LaravelPermissionManager\Support\AuditState;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Frontera autorizada del ciclo de cuentas y sus asignaciones en una transacción RBAC.
 */
class AuthorizedUserMutation
{
    /**
     * Coordina el ciclo de cuenta y sus asignaciones bajo autorización y una sola transacción RBAC.
     *
     * Recarga la autoridad tras el lock, comprueba jerarquía y estado actual antes de invocar
     * UserAccountWriter, valida roles/permisos explícitos y protege al último administrador.
     * Emite diferencias efectivas y UserAuthorityChanged; invalida caché al terminar.
     * No llamar dentro de una transacción externa MariaDB/MySQL. Los fallos anteriores
     * al commit revierten las tablas participantes; un error de caché posterior no deshace el commit.
     *
     * @param  Model  $actor  Operador persistido con la entrada users correspondiente.
     * @param  UserOperation  $operation  Creación, edición, suspensión, reactivación o eliminación.
     * @param  Model|null  $target  Null solo para Create; cuenta persistida en las demás operaciones.
     * @param  array  $attributes  Datos cuya validación y persistencia corresponden al anfitrión.
     * @param  array|null  $roles  IDs finales; null conserva la relación y [] la vacía.
     * @param  array|null  $permissions  IDs directos finales; null conserva y [] revoca.
     * @return Model Cuenta recargada con relaciones, o el modelo eliminado para Delete.
     *
     * @throws AuthorizationException Si falta autoridad sobre la operación o cuenta.
     * @throws ValidationException Si la operación, asignaciones o protección administrativa son inválidas.
     * @throws LogicException Si el adaptador cambia identidad o no persiste el objetivo requerido.
     */
    public function execute(Model $actor, UserOperation $operation, ?Model $target, array $attributes, ?array $roles = null, ?array $permissions = null): Model
    {
        return app(RbacWriteTransaction::class)->run(function () use ($actor, $operation, $target, $attributes, $roles, $permissions) {
            $authorization = app(RbacMutationAuthorization::class);
            $actor = $authorization->actor($actor, 'users.'.$operation->value);
            if (($operation === UserOperation::Create) !== ($target === null)) {
                throw ValidationException::withMessages(['user' => 'Create requires no target; other operations require a persisted target.']);
            }
            $target = $target ? $authorization->target($actor, $target) : null;
            app(AdministrativeIdentity::class)->assertAccountMutation($actor, $target, $operation, $attributes);
            $admins = app(EligibleAdministrator::class);
            $wasAdmin = $target && $admins->qualifies($target);
            if ($roles !== null) {
                $authorization->actor($actor, 'users.roles');
                app(UserAuthorityRules::class)->roles($actor, $target, $roles);
            }
            if ($permissions !== null) {
                $authorization->actor($actor, 'users.permissions');
                app(UserAuthorityRules::class)->permissions($actor, $target, $permissions);
            }
            $target ??= Models::user()->newModelInstance();
            $before = $target->exists ? AuditState::memberships($target) : ['roles' => [], 'direct_permissions' => []];
            // Compara atributos de cuenta localmente sin incluir sus valores en los eventos.
            $accountBefore = $target->getAttributes();
            $originalId = $target->getKey();
            app(UserAccountWriter::class)->persist($target, $operation, $attributes);
            Models::connection($target);
            if (($originalId !== null && $target->getKey() !== $originalId) || ($operation !== UserOperation::Delete && ! $target->exists)) {
                throw new LogicException('Account adapter must persist the same target identity.');
            }
            if ($operation !== UserOperation::Delete) {
                if ($roles !== null) {
                    $target->syncRoles(Models::role()->whereKey($roles)->get());
                }
                if ($permissions !== null) {
                    $target->syncPermissions($permissions);
                }
            }
            $admins->protect($wasAdmin);
            $after = AuditState::memberships($target);
            $audit = app(AuthorityAuditTrail::class)->record($actor->getKey(), 'user', $target->getKey(), 'user.'.$operation->value, $before, $after);
            $accountAfter = $operation === UserOperation::Delete ? [] : $target->fresh()->getAttributes();
            foreach ([$target->getCreatedAtColumn(), $target->getUpdatedAtColumn()] as $timestamp) {
                unset($accountBefore[$timestamp], $accountAfter[$timestamp]);
            }
            event(new UserAuthorityChanged($actor->getKey(), $target->getKey(), $operation->value, $audit, app(AuditContext::class)->correlationId(), $accountBefore !== $accountAfter));

            return $operation === UserOperation::Delete ? $target : $target->fresh(['roles', 'permissions']);
        });
    }
}
