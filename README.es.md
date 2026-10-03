# Laravel Permission Manager

[English](README.md)

**Versión 1.0.0** · `ahinest/laravel-permission-manager`

Administración de autoridad para aplicaciones Laravel que utilizan Spatie Permission: delegación explícita, jerarquía de roles, operaciones autorizadas de cuentas y asignaciones, e historial transaccional mediante un adaptador anfitrión.

Requiere PHP ^8.2, Laravel 12 y Spatie Permission ^6.25. Consultar la [compatibilidad verificada](docs/compatibility.es.md) antes de elegir dependencias.

## Instalación

```bash
composer require ahinest/laravel-permission-manager
php artisan vendor:publish --tag=permission-manager-config
```

Conservar el User propio con HasRoles de Spatie. Elegir el Role del paquete o una subclase Spatie con HasDelegationPolicy y configurar user_model, admin_role exacto, guard y los mapeos de permisos de la aplicación. Aplicar el esquema Spatie antes de publicar y ejecutar la migración de la extensión. Seguir la [guía de instalación](docs/installation.es.md) para la secuencia completa.

## Modelo de autoridad

- **P(rol)**: permisos funcionales proporcionados por Spatie.
- **Q(rol)**: permisos de la política de delegación activa del rol.
- **D(usuario)**: unión de Q de los roles persistidos del usuario en el guard configurado.

Los permisos funcionales y la identidad de gestor no amplían D implícitamente. Cada escritura administrativa comprueba de nuevo la autoridad persistida bajo el bloqueo transaccional común. Los niveles menores representan mayor autoridad jerárquica. El administrador global configurado tiene privilegios administrativos explícitos; no se instala un bypass funcional Gate::before.

## Uso mínimo

```php
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;

$user = app(UserPermissionManagementService::class)
    ->assignRole($actor, $target, $roleId);
```

El operador y el objetivo son usuarios anfitriones persistidos. El servicio comprueba entrada funcional, jerarquía, autoridad delegable e identidades administrativas, protege al último administrador elegible, registra cambios efectivos y devuelve asignaciones frescas.

Vincular UserAccountWriter para operaciones compuestas de cuentas y TransactionalAuditStore para auditoría persistente. HTTP es opcional y está desactivado por defecto. La instalación no crea cuentas, roles, permisos ni concesiones implícitas.

## Documentación

[Instalación](docs/installation.es.md) · [Autorización](docs/authorization-model.es.md) · [Delegación](docs/delegation.es.md) · [Integración](docs/integration.es.md) · [API HTTP](docs/api.es.md) · [Auditoría](docs/auditing.es.md) · [API pública](docs/public-api.es.md)

[Seguridad](docs/security.es.md) · [Migraciones](docs/migrations.es.md) · [Compatibilidad](docs/compatibility.es.md) · [Verificación](docs/status.es.md) · [Análisis estático](docs/static-analysis.es.md) · [Publicación](docs/releasing.es.md) · [Changelog](CHANGELOG.md)

Usar un guard, claves Spatie enteras y una conexión. Teams, wildcards y actores Passport client credentials no están soportados. Las escrituras SQL y Spatie/Eloquent ajenas a los servicios autorizados no se interceptan. Los efectos externos esperan al commit; los errores de caché posteriores requieren reconciliar antes de reintentar. Consultar la guía de seguridad para conocer la frontera completa.
