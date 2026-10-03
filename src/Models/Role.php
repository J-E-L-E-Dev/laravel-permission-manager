<?php

namespace Ahinest\LaravelPermissionManager\Models;

use Ahinest\LaravelPermissionManager\Concerns\HasDelegationPolicy;

/**
 * Extiende el rol Spatie con nivel entero y asociación de política explícita.
 */
class Role extends \Spatie\Permission\Models\Role
{
    use HasDelegationPolicy;

    protected $casts = ['level' => 'integer'];
}
