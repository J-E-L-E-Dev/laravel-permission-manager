<?php

namespace Ahinest\LaravelPermissionManager\Support;

use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Illuminate\Database\Eloquent\Model;

/**
 * Elegibilidad predeterminada basada en existencia y, si está presente, el atributo active.
 */
class PersistedActorEligibility implements ActorEligibility
{
    /**
     * Acepta una cuenta existente sin atributo active o con active igual a true, 1 o '1'.
     *
     * Otros valores rechazan elegibilidad; el anfitrión puede sustituir este contrato.
     */
    public function allows(Model $actor): bool
    {
        return $actor->exists && (! array_key_exists('active', $actor->getAttributes()) || in_array($actor->getAttribute('active'), [true, 1, '1'], true));
    }
}
