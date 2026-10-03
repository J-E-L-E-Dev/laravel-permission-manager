<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';

use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\MariaDbHarness;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\RecordingAuditStore;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;

(new MariaDbHarness('worker'))->bootRuntime();
app()->instance(TransactionalAuditStore::class, new RecordingAuditStore);
config(['permission-manager.audit.required' => true]);
[$script, $actorId, $roleId, $level, $barrier] = $argv;
$actor = User::findOrFail((int) $actorId);
$role = Role::findOrFail((int) $roleId);
$actor->load('roles.permissions');
file_put_contents($barrier.'/ready', '1');
app(RoleManagementService::class)->update($actor, $role, ['level' => (int) $level]);
echo 'committed';
