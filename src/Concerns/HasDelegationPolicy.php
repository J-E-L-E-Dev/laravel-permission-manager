<?php

namespace Ahinest\LaravelPermissionManager\Concerns;

use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Añade al Role anfitrión la relación explícita con su política de delegación.
 */
trait HasDelegationPolicy
{
    /**
     * Política de delegación asociada al rol; la relación puede no tener un registro.
     *
     * @return BelongsTo<DelegationPolicy, $this>
     */
    public function delegationPolicy(): BelongsTo
    {
        return $this->belongsTo(DelegationPolicy::class, 'delegation_policy_id');
    }
}
