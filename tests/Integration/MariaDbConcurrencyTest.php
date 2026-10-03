<?php

namespace Ahinest\LaravelPermissionManager\Tests\Integration;

use Ahinest\LaravelPermissionManager\Data\UserOperation;
use Ahinest\LaravelPermissionManager\Models\DelegationPolicy;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\AuthorizedUserMutation;
use Ahinest\LaravelPermissionManager\Services\RbacWriteTransaction;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\MariaDbHarness;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Symfony\Component\Process\Process;

class MariaDbConcurrencyTest extends MariaDbHarness
{
    protected function setUp(): void
    {
        if (getenv('LPM_DISPOSABLE_DATABASE') !== '1') {
            $this->markTestSkipped('Requires an explicitly disposable MariaDB database.');
        }
        parent::setUp();
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS version')->version);
        Schema::dropAllTables(); // Harness verifies the unique disposable database name first.
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
            $table->timestamps();
        });
        (require __DIR__.'/../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        (require __DIR__.'/../../database/migrations/2026_09_20_180000_create_permission_manager_tables.php')->up();
    }

    public static function scenarios(): array
    {
        return array_map(fn ($mode) => [$mode], ['revoke', 'rollback', 'deactivate', 'unlink', 'member', 'consumer', 'simultaneous']);
    }

    #[DataProvider('scenarios')]
    public function test_waiting_writer_revalidates_after_the_lock(string $mode): void
    {
        $a = Permission::create(['name' => 'inside', 'guard_name' => 'web']);
        $b = Permission::create(['name' => 'outside', 'guard_name' => 'web']);
        $entry = Permission::create(['name' => 'entry', 'guard_name' => 'web']);
        $scope = DelegationPolicy::create(['name' => 'Scope', 'slug' => 'scope', 'active' => true]);
        $scope->permissions()->attach($a);
        $manager = Role::create(['name' => 'manager', 'guard_name' => 'web', 'level' => 2, 'delegation_policy_id' => $scope->id]);
        $actor = User::create(['name' => 'Actor']);
        $actor->assignRole($manager);
        $actor->givePermissionTo($entry);
        $target = User::create(['name' => 'Target']);
        $targetPolicy = DelegationPolicy::create(['name' => 'Target', 'slug' => 'target', 'active' => true]);
        $consumer = Role::create(['name' => 'consumer', 'guard_name' => 'web', 'level' => 3, 'delegation_policy_id' => $targetPolicy->id]);
        $target->assignRole($consumer);
        $barrier = sys_get_temp_dir().'/lpm-barrier-'.bin2hex(random_bytes(8));
        mkdir($barrier);
        $policyMode = in_array($mode, ['member', 'consumer'], true);
        $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/concurrency-worker.php', $policyMode ? 'policy' : 'grant', (string) $actor->id, (string) ($policyMode ? $targetPolicy->id : $target->id), (string) $a->id, $barrier], dirname(__DIR__, 2), timeout: 25);
        try {
            try {
                app(RbacWriteTransaction::class)->run(function () use ($mode, $scope, $manager, $target, $consumer, $b, $process, $barrier, $a) {
                    $process->start();
                    $deadline = microtime(true) + 15;
                    while (! is_file($barrier.'/ready')) {
                        if (! $process->isRunning() || microtime(true) > $deadline) {
                            throw new \RuntimeException('Worker failed: '.$process->getErrorOutput().$process->getOutput());
                        }
                        usleep(10000);
                    }
                    usleep(250000);
                    $this->assertTrue($process->isRunning());
                    $this->assertSame('', $process->getOutput());
                    // Privileged infrastructure fixture participates in the SAME lock.
                    match ($mode) {
                        'deactivate' => $scope->update(['active' => false]),
                        'unlink' => $manager->update(['delegation_policy_id' => null]),
                        'member' => $target->givePermissionTo($b),
                        'consumer' => $consumer->update(['level' => 1]),
                        'simultaneous' => $target->givePermissionTo($a),
                        default => $scope->permissions()->detach(),
                    };
                    if ($mode === 'rollback') {
                        throw new \RuntimeException('intentional rollback');
                    }
                });
            } catch (\RuntimeException $exception) {
                if ($mode !== 'rollback' || $exception->getMessage() !== 'intentional rollback') {
                    throw $exception;
                }
            }
            $process->wait();
            $allowed = in_array($mode, ['rollback', 'simultaneous'], true);
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame($allowed ? 'committed' : 'denied', $process->getOutput());
            $this->assertSame($allowed ? 1 : 0, $policyMode ? $targetPolicy->permissions()->count() : $target->permissions()->count());
        } finally {
            $process->stop();
            foreach (glob($barrier.'/*') as $file) {
                unlink($file);
            }
            rmdir($barrier);
        }
    }

    public function test_rejects_ambient_repeatable_read_transaction(): void
    {
        DB::beginTransaction();
        try {
            DB::table('roles')->get();
            $this->expectException(\LogicException::class);
            app(RbacWriteTransaction::class)->run(fn () => throw new \RuntimeException('must not execute'));
        } finally {
            DB::rollBack();
        }
    }

    public static function accountScenarios(): array
    {
        return array_map(fn ($mode) => [$mode], ['create', 'suspend', 'increment', 'increment-role', 'remove-admin']);
    }

    #[DataProvider('accountScenarios')]
    public function test_account_writes_recheck_concurrent_authority(string $mode): void
    {
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]);
        $role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 3]);
        $otherRole = Role::create(['name' => 'second', 'guard_name' => 'web', 'level' => 4]);
        $entry = Permission::create(['name' => 'entry', 'guard_name' => 'web']);
        $a = Permission::create(['name' => 'a', 'guard_name' => 'web']);
        $b = Permission::create(['name' => 'b', 'guard_name' => 'web']);
        $actor = User::create(['name' => 'Actor']);
        $target = User::create(['name' => 'Target']);
        if (in_array($mode, ['create', 'suspend'], true)) {
            $actor->assignRole(Role::create(['name' => 'manager', 'guard_name' => 'web', 'level' => 2]));
            $actor->givePermissionTo($entry);
        } else {
            $actor->assignRole($adminRole);
        }
        if ($mode === 'remove-admin') {
            $target->assignRole($adminRole);
        }
        $barrier = sys_get_temp_dir().'/lpm-account-'.bin2hex(random_bytes(8));
        mkdir($barrier);
        $targetId = $mode === 'create' ? $role->id : ($mode === 'remove-admin' ? $actor->id : $target->id);
        $id = match ($mode) {
            'remove-admin' => $adminRole->id, 'increment-role' => $role->id, default => $a->id
        };
        $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/concurrency-worker.php', $mode, (string) $actor->id, (string) $targetId, (string) $id, $barrier], dirname(__DIR__, 2), timeout: 40);
        try {
            app(RbacWriteTransaction::class)->run(function () use ($process, $barrier, $mode, $actor, $target, $adminRole, $b, $otherRole) {
                $process->start();
                $deadline = microtime(true) + 20;
                while (! is_file($barrier.'/ready')) {
                    if (! $process->isRunning() || microtime(true) > $deadline) {
                        throw new \RuntimeException('Worker failed: '.$process->getErrorOutput().$process->getOutput());
                    }
                    usleep(10000);
                }
                usleep(250000);
                $this->assertTrue($process->isRunning());
                $this->assertSame('', $process->getOutput());
                // Cooperative privileged first writer; the waiting writer uses the public authorized API.
                match ($mode) {
                    'create' => $actor->syncPermissions([]),
                    'suspend' => $target->assignRole($adminRole),
                    'increment' => $target->givePermissionTo($b),
                    'increment-role' => $target->assignRole($otherRole),
                    'remove-admin' => $target->update(['active' => false]),
                };
            });
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $allowed = in_array($mode, ['increment', 'increment-role'], true);
            $this->assertSame($allowed ? 'committed' : 'denied', $process->getOutput());
            if ($mode === 'increment') {
                $this->assertEqualsCanonicalizing([$a->id, $b->id], $target->permissions()->get()->modelKeys());
            }
            if ($mode === 'increment-role') {
                $this->assertEqualsCanonicalizing([$role->id, $otherRole->id], $target->roles()->get()->modelKeys());
            }
            if ($mode === 'create') {
                $this->assertSame(2, User::count());
            }
            if ($mode === 'suspend') {
                $this->assertEquals(1, $target->fresh()->active);
            }
            if ($mode === 'remove-admin') {
                $this->assertTrue($actor->fresh()->hasRole($adminRole));
            }
        } finally {
            $process->stop();
            foreach (glob($barrier.'/*') as $file) {
                unlink($file);
            } rmdir($barrier);
        }
    }

    public function test_account_save_rolls_back_before_membership_persistence(): void
    {
        $admin = User::create(['name' => 'Admin']);
        $admin->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));
        $role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 3]);
        try {
            app(AuthorizedUserMutation::class)->execute($admin, UserOperation::Create, null, ['name' => 'Rollback', 'fail_after_save' => true], [$role->id]);
            $this->fail('Expected account persistence failure.');
        } catch (\RuntimeException $e) {
            $this->assertSame('fixture account rollback', $e->getMessage());
        }
        $this->assertSame(1, User::count());
        $this->assertSame(1, DB::table('model_has_roles')->count());
    }

    public function test_rejects_nontransactional_spatie_pivot(): void
    {
        Schema::table('model_has_permissions', fn ($table) => $table->dropForeign(['permission_id']));
        DB::statement('ALTER TABLE model_has_permissions MODIFY model_type VARCHAR(191) NOT NULL');
        DB::statement('ALTER TABLE model_has_permissions ENGINE=MyISAM');
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('InnoDB required: model_has_permissions');
        app(RbacWriteTransaction::class)->run(fn () => $this->fail('Must not write.'));
    }
}
