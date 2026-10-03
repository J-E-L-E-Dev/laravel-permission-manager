# Laravel Permission Manager

[Español](README.es.md)

**Version 1.0.0** · `ahinest/laravel-permission-manager`

Administrative authority for Laravel applications using Spatie Permission: explicit delegation, role hierarchy, authorized account and membership operations, and transactional audit history through a host adapter.

Requires PHP ^8.2, Laravel 12 and Spatie Permission ^6.25. Check [verified compatibility](docs/compatibility.md) before choosing dependencies.

## Installation

```bash
composer require ahinest/laravel-permission-manager
php artisan vendor:publish --tag=permission-manager-config
```

Keep your User model with Spatie HasRoles. Select the package Role or a Spatie subclass with HasDelegationPolicy, then configure user_model, the exact admin_role, guard and application permission mappings. Apply Spatie's schema before publishing and running the extension migration. Follow the [installation guide](docs/installation.md) for the complete sequence.

## Authority model

- **P(role)**: functional permissions supplied by Spatie.
- **Q(role)**: permissions in the role's active delegation policy.
- **D(user)**: union of Q across the user's persisted roles in the configured guard.

Functional permissions and policy-manager identity do not implicitly enlarge D. Every administrative write rechecks persisted authority under the shared transaction lock. Smaller role levels mean greater hierarchical authority. The configured global administrator has explicit administrative privileges; no functional Gate::before bypass is installed.

## Minimal usage

```php
use Ahinest\LaravelPermissionManager\Services\UserPermissionManagementService;

$user = app(UserPermissionManagementService::class)
    ->assignRole($actor, $target, $roleId);
```

The actor and target are persisted host users. The service checks functional entry, hierarchy, delegable authority and administrative identities, protects the last eligible administrator, records effective changes and returns fresh assignments.

Bind UserAccountWriter for compound account operations and TransactionalAuditStore for durable audit persistence. HTTP is optional and disabled by default. Installation creates no accounts, roles, permissions or implicit grants.

## Documentation

[Installation](docs/installation.md) · [Authorization](docs/authorization-model.md) · [Delegation](docs/delegation.md) · [Integration](docs/integration.md) · [HTTP API](docs/api.md) · [Auditing](docs/auditing.md) · [Public API](docs/public-api.md)

[Security](docs/security.md) · [Migrations](docs/migrations.md) · [Compatibility](docs/compatibility.md) · [Verification](docs/status.md) · [Static analysis](docs/static-analysis.md) · [Release process](docs/releasing.md) · [Changelog](CHANGELOG.md)

Use one guard, integer Spatie keys and one connection. Teams, wildcards and Passport client-credential actors are unsupported. Direct SQL and Spatie/Eloquent writes outside the authorized services are not intercepted. External effects must wait for commit; cache errors after commit require reconciliation before retrying. See the security guide for the full boundary.
