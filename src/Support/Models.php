<?php

namespace Ahinest\LaravelPermissionManager\Support;

use Ahinest\LaravelPermissionManager\Concerns\HasDelegationPolicy;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

/**
 * Valida modelos configurados, guard único y conexión compartida antes de consultar RBAC.
 */
final class Models
{
    /**
     * Exige un guard RBAC explícito y descarta modalidades no compatibles.
     *
     * @throws LogicException Si falta guard o se habilitan teams, wildcards o clientes Passport.
     */
    public static function guard(): string
    {
        $guard = config('permission-manager.guard');
        if (! is_string($guard) || trim($guard) === '' || config('permission.teams') || config('permission.enable_wildcard_permission') || config('permission.use_passport_client_credentials')) {
            throw new LogicException('An explicit single RBAC guard without teams, wildcard or Passport client credentials is required.');
        }

        return $guard;
    }

    /**
     * Construye una consulta del User anfitrión, comprobando HasRoles y la conexión común.
     *
     * No crea usuarios ni instala un modelo propio del paquete.
     *
     *
     * @return Builder<Model>
     *
     * @throws LogicException Si la clase configurada o su conexión son incompatibles.
     */
    public static function user(): Builder
    {
        self::guard();
        $class = config('permission-manager.user_model');
        if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! in_array(HasRoles::class, class_uses_recursive($class), true)) {
            throw new LogicException('Configure the host user_model with Spatie HasRoles.');
        }
        $model = new $class;
        self::connection($model);

        return $model->newQuery();
    }

    /**
     * Construye una consulta del Role compartido con Spatie y comprueba HasDelegationPolicy.
     *
     *
     * @return Builder<Role>
     *
     * @throws LogicException Si ambos paquetes no usan la misma subclase o conexión.
     */
    public static function role(): Builder
    {
        self::guard();
        $class = config('permission-manager.role_model') ?? config('permission.models.role');
        if ($class !== config('permission.models.role') || ! is_a($class, Role::class, true) || ! in_array(HasDelegationPolicy::class, class_uses_recursive($class), true)) {
            throw new LogicException('Configure the same Spatie Role subclass with HasDelegationPolicy in both packages.');
        }
        $model = new $class;
        self::connection($model);

        return $model->newQuery();
    }

    /**
     * Exige que el modelo utilice la conexión predeterminada compartida con el lock.
     *
     * @throws LogicException Si el nombre de conexión del modelo difiere del predeterminado.
     */
    public static function connection(Model $model): void
    {
        if ($model->getConnection()->getName() !== DB::connection()->getName()) {
            throw new LogicException('All RBAC models and the coordination lock must use the default database connection.');
        }
    }
}
