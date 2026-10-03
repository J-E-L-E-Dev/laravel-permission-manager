<?php

namespace Ahinest\LaravelPermissionManager;

use Ahinest\LaravelPermissionManager\Console\InspectCommand;
use Ahinest\LaravelPermissionManager\Console\ProvisionCommand;
use Ahinest\LaravelPermissionManager\Contracts\ActorEligibility;
use Ahinest\LaravelPermissionManager\Contracts\AdministrativeIdentity;
use Ahinest\LaravelPermissionManager\Contracts\SensitiveRoleResolver;
use Ahinest\LaravelPermissionManager\Support\AuditContext;
use Ahinest\LaravelPermissionManager\Support\ConfiguredSensitiveRoles;
use Ahinest\LaravelPermissionManager\Support\PersistedActorEligibility;
use Ahinest\LaravelPermissionManager\Support\UnrestrictedAdministrativeIdentity;
use Illuminate\Support\ServiceProvider;

/**
 * Integra configuración, adaptadores, rutas opcionales y herramientas de consola en Laravel.
 */
class PermissionManagerServiceProvider extends ServiceProvider
{
    /**
     * Integra la configuración y registra el contexto de auditoría y adaptadores predeterminados.
     *
     * bindIf respeta los contratos ya vinculados por la aplicación anfitriona.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/permission-manager.php', 'permission-manager');
        $this->app->singleton(AuditContext::class);
        $this->app->bindIf(SensitiveRoleResolver::class, ConfiguredSensitiveRoles::class);
        $this->app->bindIf(ActorEligibility::class, PersistedActorEligibility::class);
        $this->app->bindIf(AdministrativeIdentity::class, UnrestrictedAdministrativeIdentity::class);
    }

    /**
     * Registra rutas solo si HTTP está habilitado y expone configuración, migraciones y comandos.
     *
     * Publicar migraciones no las ejecuta; los comandos se registran únicamente en consola.
     */
    public function boot(): void
    {
        if (config('permission-manager.http.enabled')) {
            $this->loadRoutesFrom(__DIR__.'/../routes/api.php');
        }
        $this->publishes([__DIR__.'/../config/permission-manager.php' => config_path('permission-manager.php')], 'permission-manager-config');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'permission-manager-migrations');
        if ($this->app->runningInConsole()) {
            $this->commands([ProvisionCommand::class, InspectCommand::class]);
        }
    }
}
