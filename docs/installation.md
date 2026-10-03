# Installation

[Versión en español](installation.es.md)

Version: **1.0.0**. Requires PHP ^8.2, Laravel 12 and Spatie Permission ^6.25. See [compatibility](compatibility.md) for verified dependency combinations. Role/permission primary keys must be integers. Use one RBAC guard and one default database connection. Teams, wildcard permissions and Passport client-credential actors are unsupported.

## Composer and Spatie

```bash
composer require ahinest/laravel-permission-manager
php artisan vendor:publish --tag=permission-manager-config
```

Configure the application database before migrating. If Spatie configuration and migrations have not been published, publish them and apply that schema first:

```bash
php artisan vendor:publish --tag=permission-config
php artisan vendor:publish --tag=permission-migrations
php artisan migrate
```

Skip those publication steps when Spatie is already installed. Do not duplicate existing migrations. Apply Spatie before publishing the extension: files published in the same second must not depend on filename ordering.

## Models and configuration

Keep the application's User model and add Spatie's `HasRoles` trait. In `config/permission.php`, select `Ahinest\LaravelPermissionManager\Models\Role::class`, or a Spatie Role subclass using `Ahinest\LaravelPermissionManager\Concerns\HasDelegationPolicy`. The provider does not overwrite the configured model.

In `config/permission-manager.php`, configure:

```php
'user_model' => App\Models\User::class,
'admin_role' => 'admin',
'guard' => 'web',
'role_model' => null,
```

`admin` is an example exact role identity, not a role created by installation. A null admin_role denies global administration. Null role_model resolves Spatie's configured model; an explicit value must be the same class. Configure `abilities` with permission names from your own catalog. Empty mappings deny ordinary operators.

## Extension migration

Review the schema and backup before migrating. Publish the extension once:

```bash
php artisan config:clear
php artisan vendor:publish --tag=permission-manager-migrations
php artisan migrate
php artisan permission-manager:check
```

Migrations are not loaded or executed automatically at boot. Repeated publication can create duplicate timestamped files; do not republish an installed migration.

The extension adds nullable role level/description and permission description where absent, policies, policy-permission memberships, a role-policy foreign key and coordination row. Existing Spatie data is preserved; levels and Q are not inferred or backfilled. Existing description/level columns remain host-owned. A preexisting delegation_policy_id or package table requires explicit schema adaptation.

Rollback removes package data and only package-owned extension columns, preserving original Spatie tables. MariaDB DDL can be partially committed on failure: consult [migration recovery](migrations.md), not an automatic rollback shortcut.

## Application integration

Bind UserAccountWriter for account lifecycle operations and declare additional transactional tables. Configure authentication before enabling optional HTTP; it is disabled by default. Bind TransactionalAuditStore before requiring durable audit persistence.

Provisioning is explicit and applies only to existing role names in `provisioning`:

```bash
php artisan permission-manager:provision --dry-run
php artisan permission-manager:provision
```

It creates empty policy structures, never users, roles, passwords, permissions or implicit P→Q grants. See [integration](integration.md), [HTTP API](api.md), [auditing](auditing.md) and [release process](releasing.md) for the path-to-tag transition.
