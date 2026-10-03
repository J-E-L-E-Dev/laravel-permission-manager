<?php

namespace Ahinest\LaravelPermissionManager\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Política persistida cuyo contenido concede delegación únicamente cuando está activa.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property bool $active
 * @property Collection<int, Permission> $permissions
 */
class DelegationPolicy extends Model
{
    protected $table = 'lpm_delegation_policies';

    protected $fillable = ['name', 'slug', 'active'];

    protected $attributes = ['active' => false];

    protected $casts = ['active' => 'boolean'];

    /**
     * Permisos almacenados de la política, incluso cuando está inactiva.
     *
     * La relación conserva las asociaciones persistidas sin ocultar permisos de otro
     * guard, de modo que las comprobaciones de autorización puedan detectar una
     * configuración inconsistente.
     *
     * @return BelongsToMany<Permission, $this, Pivot>
     */
    public function permissions(): BelongsToMany
    {
        /** @var class-string<Permission> $class */
        $class = config('permission.models.permission');

        // No oculta membresías de otro guard a las comprobaciones de autorización.
        return $this->belongsToMany($class, 'lpm_policy_permission', 'delegation_policy_id', 'permission_id');
    }

    /**
     * Roles que consumen esta política de delegación mediante delegation_policy_id.
     *
     * @return HasMany<Role, $this>
     */
    public function roles(): HasMany
    {
        /** @var class-string<Role> $class */
        $class = config('permission.models.role');

        return $this->hasMany($class, 'delegation_policy_id');
    }
}
