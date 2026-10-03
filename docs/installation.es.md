# Instalación

[English version](installation.md)

Versión: **1.0.0**. Requiere PHP ^8.2, Laravel 12 y Spatie Permission ^6.25. Consultar [compatibilidad](compatibility.es.md) para las combinaciones verificadas. Las claves primarias de roles/permisos deben ser enteras. Usar un guard RBAC y una conexión predeterminada de base de datos. Teams, permisos wildcard y actores Passport client credentials no están soportados.

## Composer y Spatie

```bash
composer require ahinest/laravel-permission-manager
php artisan vendor:publish --tag=permission-manager-config
```

Configurar la base de la aplicación antes de migrar. Si todavía no se publicaron configuración y migraciones de Spatie, publicarlas y aplicar primero ese esquema:

```bash
php artisan vendor:publish --tag=permission-config
php artisan vendor:publish --tag=permission-migrations
php artisan migrate
```

Omitir esos pasos de publicación cuando Spatie ya esté instalado. No duplicar migraciones existentes. Aplicar Spatie antes de publicar la extensión: los archivos publicados en el mismo segundo no deben depender del orden de sus nombres.

## Modelos y configuración

Conservar el User de la aplicación y añadir el trait `HasRoles` de Spatie. En `config/permission.php`, elegir `Ahinest\LaravelPermissionManager\Models\Role::class`, o una subclase Role de Spatie con `Ahinest\LaravelPermissionManager\Concerns\HasDelegationPolicy`. El provider no sobrescribe el modelo configurado.

En `config/permission-manager.php`, configurar:

```php
'user_model' => App\Models\User::class,
'admin_role' => 'admin',
'guard' => 'web',
'role_model' => null,
```

`admin` es un ejemplo de identidad exacta, no un rol creado por la instalación. admin_role null deniega administración global. role_model null utiliza el modelo configurado en Spatie; un valor explícito debe ser la misma clase. Configurar `abilities` con nombres de permisos del catálogo propio. Los mapeos vacíos deniegan entrada a operadores ordinarios.

## Migración de la extensión

Revisar el esquema y respaldo antes de migrar. Publicar la extensión una sola vez:

```bash
php artisan config:clear
php artisan vendor:publish --tag=permission-manager-migrations
php artisan migrate
php artisan permission-manager:check
```

Las migraciones no se cargan ni ejecutan automáticamente al arrancar. Repetir la publicación puede generar archivos duplicados con otro timestamp; no republicar una migración instalada.

La extensión añade level/description nullable al rol y description al permiso cuando no existen, políticas, membresías de permisos delegables, una FK de política del rol y la fila de coordinación. Conserva los datos Spatie; no infiere ni rellena niveles o Q. Las columnas description/level existentes siguen perteneciendo al anfitrión. Una delegation_policy_id o tabla del paquete preexistente requiere adaptación explícita.

El rollback elimina datos del paquete y solo sus columnas propias, preservando las tablas originales Spatie. El DDL MariaDB puede quedar parcialmente confirmado ante un fallo: consultar [recuperación de migraciones](migrations.es.md), no utilizar rollback como reparación automática.

## Integración con la aplicación

Vincular UserAccountWriter para el ciclo de cuentas y declarar tablas transaccionales adicionales. Configurar autenticación antes de habilitar HTTP opcional; está desactivado por defecto. Vincular TransactionalAuditStore antes de exigir persistencia durable de auditoría.

El aprovisionamiento es explícito y solo utiliza nombres de roles existentes en `provisioning`:

```bash
php artisan permission-manager:provision --dry-run
php artisan permission-manager:provision
```

Crea estructuras de políticas vacías, nunca usuarios, roles, contraseñas, permisos ni concesiones implícitas P→Q. Consultar [integración](integration.es.md), [API HTTP](api.es.md), [auditoría](auditing.es.md) y [publicación](releasing.es.md) para la transición de path a tag.
