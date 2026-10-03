# Public API for 1.0.0

[Versión en español](public-api.es.md)

Namespace: `Ahinest\LaravelPermissionManager`. Preserve class/method names, parameter names (named PHP arguments), native types, enum values and event constructor order across compatible releases. Source signatures are authoritative.

| Contract | Stable methods |
|---|---|
| ActorEligibility | allows(Model $actor): bool |
| SensitiveRoleResolver | names(): array of role names |
| AdministrativeIdentity | assertActor(Model $actor): void; allowsGlobalAdministration(Model $actor): bool; assertAccountControl(Model $actor, Model $target): void; assertAccountMutation(Model $actor, ?Model $target, UserOperation $operation, array $attributes): void; assertRoles(Model $actor, ?Model $target, array $final): void; assertRoleMutation(Model $actor, ?Spatie Role $role, array $attributes): void |
| UserAccountWriter | persist(Model $target, UserOperation $operation, array $attributes): void |
| TransactionalAuditStore | connection(): Connection; tables(): array of table names; record(AuthorityAudit $event, Connection $connection): void |

Contracts live in Contracts. Hosts keep their Eloquent User with HasRoles; no package User inheritance is required. Configured roles extend Spatie Role and use HasDelegationPolicy. All models/tables use the same default connection. Native signatures are part of the compatibility contract.

| Service | Entry points |
|---|---|
| RoleManagementService | create(actor, data); update(actor, role, data); syncPermissions(actor, role, ids) |
| DelegationPolicyService | create(actor, data); update(actor, policy, data); syncPermissions(actor, policy, ids); assignToRole(actor, role, policyId nullable) |
| UserPermissionManagementService | replaceRoles(actor, target, roleIds); replaceDirectPermissions(actor, target, permissionIds); assignRole/removeRole(actor, target, roleId); grantDirectPermission/revokeDirectPermission(actor, target, permissionId) |
| AuthorizedUserMutation | execute(actor, operation, target nullable, attributes, roles nullable = null, permissions nullable = null) |
| AuthorityQueryService | roles, manageableRoles, permissions, capabilities, userAuthority, assignableRoles, delegablePermissions, policies, policy, manageablePolicies, assignablePolicyRoles |
| InfrastructureInspector | inspect(): array; assertReady(): void |
| DelegationPolicyProvisioningService | run(bool $dryRun = false): array; infrastructure only |

See [integration](integration.md), [delegation](delegation.md), [HTTP](api.md) and [auditing](auditing.md) for parameters, payloads and boundaries. Relationships Role::delegationPolicy and DelegationPolicy::permissions/roles, configuration keys and CLI signatures permission-manager:check and permission-manager:provision {--dry-run} are also integration surfaces.

UserOperation cases: Create, Update, Suspend, Reactivate, Delete, with lowercase string values. AuthorityAudit's readonly fields in constructor order: eventId, actorId, actorType, resourceType, resourceId, operation, before, after, changes, occurredAt, guard, correlationId. UserAuthorityChanged retains actorId, targetId, operation, followed by optional audit, correlationId and accountChanged. User IDs may be integer/string where the host schema supports them; Spatie role/permission keys remain integers. Preserve operation strings and audit field allowlists.

AuthorizationException denotes denial; ValidationException denotes invalid input/invariants; ModelNotFoundException denotes absent targets; LogicException denotes unsupported configuration/infrastructure. Persistence failures before commit propagate and roll back the transaction. A later cache error cannot undo a confirmed commit. HTTP maps exceptions through Laravel/RequireActor. Events precede commit; they are not durable success notifications.

PermissionDelegationService and RoleHierarchy expose trusted read/check primitives, not replacements for authorized services. Snapshots, AuditContext/AuditState, Models and direct RbacWriteTransaction callbacks are internal composition mechanisms, never client-controlled bypass APIs. Record intentional API changes in CHANGELOG before tagging.
