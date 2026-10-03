<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use LogicException;

/**
 * Diagnostica tablas, motores y coordinación sin reparar automáticamente el esquema.
 */
class InfrastructureInspector
{
    /**
     * Devuelve errores de tablas ausentes, motores no InnoDB y fila de coordinación inexistente.
     *
     * Incluye tablas Spatie, usuario, extensión y tablas declaradas por anfitrión/auditoría.
     * No repara el esquema ni verifica cada columna o restricción posible; errores de
     * configuración o del adaptador pueden propagarse como excepciones.
     */
    public function inspect(): array
    {
        $auditTables = app(AuthorityAuditTrail::class)->store(DB::connection())?->tables() ?? [];
        $tables = array_unique([Models::user()->getModel()->getTable(), ...array_values(config('permission.table_names')), 'lpm_delegation_policies', 'lpm_policy_permission', 'lpm_write_locks', 'lpm_schema_columns', ...config('permission-manager.transaction_tables', []), ...$auditTables]);
        $errors = [];
        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                $errors[] = 'Missing table: '.$table;

                continue;
            }
            if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
                $row = DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?', [DB::getDatabaseName(), DB::getTablePrefix().$table]);
                if (strtolower($row->engine ?? '') !== 'innodb') {
                    $errors[] = 'InnoDB required: '.$table;
                }
            }
        }
        if (Schema::hasTable('lpm_write_locks') && ! DB::table('lpm_write_locks')->where('id', 1)->exists()) {
            $errors[] = 'Missing coordination row.';
        }

        return $errors;
    }

    /**
     * Rechaza la operación si el diagnóstico encuentra infraestructura incompatible.
     *
     * @throws LogicException Si inspect informa uno o varios errores.
     */
    public function assertReady(): void
    {
        $errors = $this->inspect();
        if ($errors) {
            throw new LogicException(implode(' ', $errors));
        }
    }
}
