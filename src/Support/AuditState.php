<?php

namespace Ahinest\LaravelPermissionManager\Support;

use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Models\Role;

/**
 * Proyecciones explícitas de campos permitidos y diferencias de autoridad.
 * Nunca serializa el usuario anfitrión completo ni sus atributos privados.
 */
final class AuditState
{
    /**
     * Normaliza IDs de autoridad a enteros únicos ordenados para comparar conjuntos sin ruido.
     *
     * No valida existencia ni guard: el llamador debe haber autorizado los identificadores.
     */
    public static function ids(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        sort($ids, SORT_NUMERIC);

        return $ids;
    }

    /**
     * Extrae únicamente name, guard_name, level y description del rol para auditoría.
     */
    public static function role(Role $role): array
    {
        return ['name' => $role->name, 'guard_name' => $role->guard_name, 'level' => $role->level === null ? null : (int) $role->level, 'description' => $role->description];
    }

    /**
     * Extrae únicamente name, slug y active de la política para auditoría.
     */
    public static function policy(DelegationPolicy $policy): array
    {
        return ['name' => $policy->name, 'slug' => $policy->slug, 'active' => (bool) $policy->active];
    }

    /**
     * Lee IDs persistidos de roles y permisos directos, sin serializar atributos de cuenta.
     */
    public static function memberships(Model $user): array
    {
        return ['roles' => self::ids($user->roles()->get()->modelKeys()), 'direct_permissions' => self::ids($user->permissions()->get()->modelKeys())];
    }

    /**
     * Compara campos y produce pares before/after solo donde existen cambios efectivos.
     *
     * Distingue ausencia de campo y valor null; añade added/removed a los conjuntos
     * permissions, roles y direct_permissions. Los estados deben usar listas permitidas.
     */
    public static function differences(array $before, array $after): array
    {
        $changes = [];
        foreach (array_unique([...array_keys($before), ...array_keys($after)]) as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ($old === $new && array_key_exists($field, $before) === array_key_exists($field, $after)) {
                continue;
            }
            $changes[$field] = ['before' => $old, 'after' => $new];
            if (in_array($field, ['permissions', 'roles', 'direct_permissions'], true)) {
                $changes[$field]['added'] = array_values(array_diff($new ?? [], $old ?? []));
                $changes[$field]['removed'] = array_values(array_diff($old ?? [], $new ?? []));
            }
        }

        return $changes;
    }
}
