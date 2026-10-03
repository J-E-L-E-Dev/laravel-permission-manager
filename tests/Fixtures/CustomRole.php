<?php

namespace Ahinest\LaravelPermissionManager\Tests\Fixtures;

use Ahinest\LaravelPermissionManager\Concerns\HasDelegationPolicy;
use Spatie\Permission\Models\Role;

class CustomRole extends Role
{
    use HasDelegationPolicy;

    protected $casts = ['level' => 'integer'];
}
