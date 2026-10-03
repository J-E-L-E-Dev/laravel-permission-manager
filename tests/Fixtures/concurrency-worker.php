<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Services\AuthorizedUserMutation;
use Ahinest\LaravelPermissionManager\Services\DelegationPolicyService;
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\MariaDbHarness;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

$runtime = new MariaDbHarness('worker');
$runtime->bootRuntime();
[$script, $mode, $actorId, $targetId, $permissionId, $barrier] = $argv;
$actor = User::findOrFail((int) $actorId);
// Deliberately preload stale authority before entering the serialized writer.
$actor->load('roles.delegationPolicy.permissions', 'permissions');
file_put_contents($barrier.'/ready', '1');
try {
    if ($mode === 'create') {
        app(AuthorizedUserMutation::class)->execute($actor, UserOperation::Create, null, ['name' => 'Concurrent account'], [(int) $targetId]);
    } elseif ($mode === 'suspend') {
        app(AuthorizedUserMutation::class)->execute($actor, UserOperation::Suspend, User::findOrFail((int) $targetId), []);
    } elseif ($mode === 'increment') {
        app(UserPermissionManagementService::class)->grantDirectPermission($actor, User::findOrFail((int) $targetId), (int) $permissionId);
    } elseif ($mode === 'increment-role') {
        app(UserPermissionManagementService::class)->assignRole($actor, User::findOrFail((int) $targetId), (int) $permissionId);
    } elseif ($mode === 'remove-admin') {
        app(UserPermissionManagementService::class)->removeRole($actor, User::findOrFail((int) $targetId), (int) $permissionId);
    } elseif ($mode === 'policy') {
        app(DelegationPolicyService::class)->syncPermissions($actor, DelegationPolicy::findOrFail((int) $targetId), [(int) $permissionId]);
    } else {
        app(UserPermissionManagementService::class)->replaceDirectPermissions($actor, User::findOrFail((int) $targetId), [(int) $permissionId]);
    }
    echo 'committed';
} catch (AuthorizationException|ValidationException) {
    echo 'denied';
}
