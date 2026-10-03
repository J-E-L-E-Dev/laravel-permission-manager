# Delegation services

[Versión en español](delegation.es.md)

Use `Services\DelegationPolicyService` with an explicit persisted host actor:

```php
$policy = $policies->create($actor, ['name' => 'Support scope', 'slug' => 'support-scope']);
$policies->syncPermissions($actor, $policy, $existingPermissionIds);
$policies->update($actor, $policy, ['active' => true]);
$policies->assignToRole($actor, $role, $policy->id);
$policies->assignToRole($actor, $role, null); // Authorized unlink, including outgoing contents.
```

Policies start inactive unless an authorized creation explicitly supplies active=true. Name is editable separately; slug is stable and cannot be updated. Empty permission replacement is valid and still checks outgoing authority. Inactive policy candidates cannot be linked, even by admin. There is no policy delete operation. A shared policy may be linked to several roles; editing content affects all of them, while linking affects only the chosen role.

Permission IDs must be positive PHP integers identifying existing records in the configured guard. Internal callers must not pass numeric strings. No arbitrary name creates a permission. Policy mutation never copies P into Q and never creates role/user memberships.

`PermissionDelegationService::snapshot`, `delegablePermissionIds`, `capabilities`, `roleUsagePermissions` and `roleDelegablePermissions` are trusted-code read primitives. They do not authorize administrative disclosure to an HTTP caller. AuthorityQueryService provides authorized contextual reads and catalogs, also exposed by the optional authenticated HTTP API. Avoid exposing raw models/footprints.

The `permission-manager:provision --dry-run` and `permission-manager:provision` commands are implemented. Hosts explicitly list existing roles in provisioning. The complete plan detects conflicts and is recomputed under the shared lock. It creates empty structural policies, preserves managed content/name/activity and refuses implicit linking of populated policies to detached roles. It creates no permissions or roles and has no HTTP endpoint. See integration.md.

