<?php

namespace Ahinest\LaravelPermissionManager\Console;

use Ahinest\LaravelPermissionManager\Services\InfrastructureInspector;
use Illuminate\Console\Command;

/**
 * Diagnóstico de solo lectura de las tablas y coordinación transaccional RBAC.
 */
class InspectCommand extends Command
{
    protected $signature = 'permission-manager:check';

    protected $description = 'Validate participating tables, InnoDB engines and coordination row without repairs.';

    /**
     * Muestra los errores de infraestructura sin reparar tablas ni crear la fila de coordinación.
     *
     * Devuelve SUCCESS cuando no hay errores y FAILURE cuando el diagnóstico informa alguno.
     */
    public function handle(InfrastructureInspector $inspector): int
    {
        $errors = $inspector->inspect();
        foreach ($errors as $error) {
            $this->error($error);
        }
        if (! $errors) {
            $this->info('RBAC infrastructure ready.');
        }

        return $errors ? self::FAILURE : self::SUCCESS;
    }
}
