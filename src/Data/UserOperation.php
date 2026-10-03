<?php

namespace Ahinest\LaravelPermissionManager\Data;

/**
 * Operaciones de ciclo de cuenta reconocidas por la frontera de mutación autorizada.
 */
enum UserOperation: string
{
    case Create = 'create';
    case Update = 'update';
    case Suspend = 'suspend';
    case Reactivate = 'reactivate';
    case Delete = 'delete';
}
