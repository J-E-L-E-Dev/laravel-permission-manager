<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Extiende un esquema Spatie existente con políticas, asociaciones y coordinación RBAC.
     *
     * Conserva las columnas description/level preexistentes y registra solo las añadidas.
     * Las tablas propias solicitan InnoDB; SQLite ignora el motor. El DDL de MariaDB
     * puede quedar confirmado parcialmente: una excepción no garantiza reversión del esquema.
     *
     * @throws LogicException Si falta Spatie, hay teams o existen estructuras incompatibles.
     */
    public function up(): void
    {
        $roles = config('permission.table_names.roles');
        $permissions = config('permission.table_names.permissions');
        if (! Schema::hasTable($roles) || ! Schema::hasTable($permissions)) {
            throw new LogicException('Run the published Spatie migrations before Permission Manager.');
        }
        if (config('permission.teams') || Schema::hasColumn($roles, 'delegation_policy_id')) {
            throw new LogicException('Teams or an existing delegation_policy_id require an explicit schema adaptation.');
        }
        foreach (['lpm_schema_columns', 'lpm_delegation_policies', 'lpm_policy_permission', 'lpm_write_locks'] as $table) {
            if (Schema::hasTable($table)) {
                throw new LogicException('Permission Manager schema collision; inspect before migrating.');
            }
        }
        Schema::create('lpm_schema_columns', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->string('table_name');
            $table->string('column_name');
            $table->primary(['table_name', 'column_name']);
        });
        // El diario de propiedad evita eliminar descripciones o niveles preexistentes del anfitrión.
        foreach ([[$roles, 'description'], [$roles, 'level'], [$permissions, 'description']] as [$table, $column]) {
            if (! Schema::hasColumn($table, $column)) {
                Schema::table($table, function (Blueprint $blueprint) use ($column) {
                    $column === 'level' ? $blueprint->integer($column)->nullable() : $blueprint->string($column)->nullable();
                });
                DB::table('lpm_schema_columns')->insert(['table_name' => $table, 'column_name' => $column]);
            }
        }
        Schema::create('lpm_delegation_policies', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('active')->default(false);
            $table->timestamps();
        });
        Schema::create('lpm_policy_permission', function (Blueprint $table) use ($permissions) {
            $table->engine = 'InnoDB';
            $table->foreignId('delegation_policy_id')->constrained('lpm_delegation_policies')->cascadeOnDelete();
            $table->foreignId('permission_id')->constrained($permissions)->cascadeOnDelete();
            $table->primary(['delegation_policy_id', 'permission_id']);
        });
        Schema::table($roles, function (Blueprint $table) {
            $table->foreignId('delegation_policy_id')->nullable()->constrained('lpm_delegation_policies')->restrictOnDelete();
        });
        Schema::create('lpm_write_locks', function (Blueprint $table) {
            $table->engine = 'InnoDB';
            $table->unsignedTinyInteger('id')->primary();
        });
        DB::table('lpm_write_locks')->insert(['id' => 1]);
    }

    /**
     * Revierte una instalación completa y elimina los datos propios de delegación.
     *
     * Conserva las tablas Spatie y las columnas no registradas como propias. Elimina
     * las asociaciones de políticas y las columnas del diario; no sirve como reparación
     * automática de una instalación DDL interrumpida.
     */
    public function down(): void
    {
        Schema::table(config('permission.table_names.roles'), function (Blueprint $table) {
            $table->dropForeign(['delegation_policy_id']);
            $table->dropColumn('delegation_policy_id');
        });
        Schema::drop('lpm_policy_permission');
        Schema::drop('lpm_delegation_policies');
        Schema::drop('lpm_write_locks');
        foreach (DB::table('lpm_schema_columns')->get() as $owned) {
            Schema::table($owned->table_name, fn (Blueprint $table) => $table->dropColumn($owned->column_name));
        }
        Schema::drop('lpm_schema_columns');
    }
};
