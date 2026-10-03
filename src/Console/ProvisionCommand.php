<?php

namespace Ahinest\LaravelPermissionManager\Console;

use Ahinest\LaravelPermissionManager\Services\DelegationPolicyProvisioningService;
use Illuminate\Console\Command;

/**
 * Entrada CLI privilegiada para planificar y aplicar políticas estructurales vacías.
 */
class ProvisionCommand extends Command
{
    protected $signature = 'permission-manager:provision {--dry-run}';

    protected $description = 'Plan or provision explicitly configured empty delegation policies without granting permissions.';

    /**
     * Presenta el plan de políticas vacías y, salvo --dry-run, solicita su aplicación coordinada.
     *
     * Devuelve FAILURE ante conflictos; no crea roles, usuarios ni permisos.
     */
    public function handle(DelegationPolicyProvisioningService $service): int
    {
        $plan = $service->run((bool) $this->option('dry-run'));
        $this->table(['Role', 'Role ID', 'Slug', 'Policy ID', 'Action', 'Reason'], $plan['rows']);

        return $plan['conflicts'] ? self::FAILURE : self::SUCCESS;
    }
}
