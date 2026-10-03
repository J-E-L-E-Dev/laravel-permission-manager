<?php

namespace Ahinest\LaravelPermissionManager\Tests\Fixtures;

use Ahinest\LaravelPermissionManager\Contracts\UserAccountWriter;
use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Illuminate\Database\Eloquent\Model;

class AccountWriter implements UserAccountWriter
{
    public function persist(Model $target, UserOperation $operation, array $attributes): void
    {
        if ($operation === UserOperation::Delete) {
            $target->delete();

            return;
        }
        $target->fill(collect($attributes)->only(['name', 'active'])->all());
        if ($operation === UserOperation::Suspend) {
            $target->active = false;
        }
        if ($operation === UserOperation::Reactivate) {
            $target->active = true;
        }
        $target->save();
        if ($attributes['fail_after_save'] ?? false) {
            throw new \RuntimeException('fixture account rollback');
        }
    }
}
