<?php

namespace Ahinest\LaravelPermissionManager\Tests\Integration;

use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Ahinest\LaravelPermissionManager\Models\Role;
use Ahinest\LaravelPermissionManager\Services\InfrastructureInspector;
use Ahinest\LaravelPermissionManager\Services\RoleManagementService;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\MariaDbHarness;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\RecordingAuditStore;
use Ahinest\LaravelPermissionManager\Tests\Fixtures\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;

class MariaDbAuditAndMigrationTest extends MariaDbHarness
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->assertSame(getenv('LPM_TEST_DATABASE'), DB::selectOne('SELECT DATABASE() AS name')->name);
        $this->assertStringContainsString('MariaDB', DB::selectOne('SELECT VERSION() AS version')->version);
        Schema::dropAllTables();
        Schema::create('users', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
        (require __DIR__.'/../../vendor/spatie/laravel-permission/database/migrations/create_permission_tables.php.stub')->up();
        $this->migration()->up();
        Schema::create('test_authority_audits', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->text('payload');
        });
        app()->instance(TransactionalAuditStore::class, new RecordingAuditStore);
        config(['permission-manager.audit.required' => true]);
    }

    private function migration(): Migration
    {
        return require __DIR__.'/../../database/migrations/2026_09_20_180000_create_permission_manager_tables.php';
    }

    private function actor(): User
    {
        $actor = User::create(['name' => 'Admin']);
        $actor->assignRole(Role::create(['name' => 'admin', 'guard_name' => 'web', 'level' => 1]));

        return $actor;
    }

    public static function failures(): array
    {
        return [['store'], ['observer']];
    }

    #[DataProvider('failures')]
    public function test_audit_and_authority_roll_back_together(string $failure): void
    {
        $actor = $this->actor();
        $role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 20]);
        if ($failure === 'store') {
            app(TransactionalAuditStore::class)->fail = true;
        } else {
            Event::listen(AuthorityAudit::class, fn () => throw new \RuntimeException('observer failure'));
        }
        try {
            app(RoleManagementService::class)->update($actor, $role, ['level' => 30]);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('failure', $e->getMessage());
        }
        $this->assertSame(20, $role->fresh()->level);
        $this->assertSame(0, DB::table('test_authority_audits')->count());
    }

    public static function levels(): array
    {
        return [[40, 2], [30, 1]];
    }

    #[DataProvider('levels')]
    public function test_concurrent_audits_use_committed_previous_state(int $level, int $count): void
    {
        $actor = $this->actor();
        $role = Role::create(['name' => 'member', 'guard_name' => 'web', 'level' => 20]);
        $barrier = sys_get_temp_dir().'/lpm-audit-'.bin2hex(random_bytes(8));
        mkdir($barrier);
        $process = new Process([PHP_BINARY, __DIR__.'/../Fixtures/audit-worker.php', (string) $actor->id, (string) $role->id, (string) $level, $barrier], dirname(__DIR__, 2), timeout: 60);
        Event::listen(AuthorityAudit::class, function () use ($process, $barrier) {
            $process->start();
            $deadline = microtime(true) + 30;
            while (! is_file($barrier.'/ready')) {
                if (! $process->isRunning() || microtime(true) > $deadline) {
                    throw new \RuntimeException($process->getErrorOutput().$process->getOutput());
                }
                usleep(10000);
            }
            usleep(250000);
            $this->assertTrue($process->isRunning());
            $this->assertSame('', $process->getOutput());
        });
        try {
            app(RoleManagementService::class)->update($actor, $role, ['level' => 30]);
            $process->wait();
            $this->assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            $this->assertSame('committed', $process->getOutput());
            $events = DB::table('test_authority_audits')->orderBy('id')->pluck('payload')->map(fn ($value) => json_decode($value, true, flags: JSON_THROW_ON_ERROR));
            $this->assertCount($count, $events);
            $this->assertSame(20, $events[0]['before']['level']);
            $this->assertSame(30, $events[0]['after']['level']);
            if ($count === 2) {
                $this->assertSame(30, $events[1]['before']['level']);
                $this->assertSame(40, $events[1]['after']['level']);
                $this->assertNotSame($events[0]['correlationId'], $events[1]['correlationId']);
            }
            $this->assertSame($level, $role->fresh()->level);
        } finally {
            $process->stop();
            foreach (glob($barrier.'/*') as $file) {
                unlink($file);
            }
            rmdir($barrier);
        }
    }

    public function test_ownership_journal_is_innodb_even_with_myisam_default(): void
    {
        $this->migration()->down();
        DB::statement('SET SESSION default_storage_engine=MyISAM');
        try {
            $this->migration()->up();
        } finally {
            DB::statement('SET SESSION default_storage_engine=InnoDB');
        }
        $this->assertSame([], app(InfrastructureInspector::class)->inspect());
    }

    public function test_partial_ddl_is_diagnosed_without_automatic_repair(): void
    {
        $this->migration()->down();
        $role = \Spatie\Permission\Models\Role::create(['name' => 'existing', 'guard_name' => 'web']);
        $interrupted = false;
        DB::listen(function (QueryExecuted $query) use (&$interrupted) {
            if (! $interrupted && str_contains($query->sql, 'alter table `permissions` add `description`')) {
                $interrupted = true;
                throw new \RuntimeException('simulated DDL interruption');
            }
        });
        try {
            $this->migration()->up();
            $this->fail('Expected interrupted migration');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated DDL interruption', $e->getMessage());
        }
        $this->assertTrue(Schema::hasColumn('permissions', 'description'));
        $this->assertFalse(DB::table('lpm_schema_columns')->where('table_name', 'permissions')->exists());
        $this->assertNotEmpty(app(InfrastructureInspector::class)->inspect());
        try {
            $this->migration()->up();
            $this->fail('Expected collision');
        } catch (\LogicException $e) {
            $this->assertStringContainsString('collision', $e->getMessage());
        }
        $this->assertSame('existing', $role->fresh()->name);
        // Fixture-only manual recovery: all three columns were proven absent before this run.
        // Never infer ownership of an unjournaled column in a real application.
        Schema::table('roles', fn (Blueprint $t) => $t->dropColumn(['description', 'level']));
        Schema::table('permissions', fn (Blueprint $t) => $t->dropColumn('description'));
        Schema::drop('lpm_schema_columns');
        $this->migration()->up();
        $this->assertSame([], app(InfrastructureInspector::class)->inspect());
        $this->migration()->down();
        $this->assertSame('existing', $role->fresh()->name);
    }
}
