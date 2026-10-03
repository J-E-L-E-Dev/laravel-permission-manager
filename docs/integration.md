# Account, authority and audit integration

[Versión en español](integration.es.md)

## Shared transaction

`AuthorizedUserMutation::execute(actor, UserOperation, target|null, attributes, roles|null, permissions|null)` is the public entry for creating, updating, suspending, reactivating or deleting accounts. Creation requires target null; other operations require a persisted account. roles and permissions contain existing integer IDs from the configured guard. Null preserves a relation; [] explicitly empties it. Creation without memberships produces an account without authority: no business role is inferred.

The sequence is: open the owned transaction, acquire the common row, reload actor and target, check eligibility and functional entry, hierarchy and the target's CURRENT footprint, validate current/final relations against pre-write D, invoke the host adapter, persist relations, protect the last eligible administrator and emit UserAuthorityChanged. Exceptions before commit roll back all participating transactional tables. A cache-backend failure after commit cannot undo it; reconcile persisted state before retrying (see [security](security.md)). Removing roles and changing credentials together does not avoid checking previous authority.

Do not wrap this service in an external MariaDB/MySQL transaction. Each authorized entry owns its transaction. UserAuthorityRules shares read checks between queries and writes without exposing an authorization bypass context. Do not call replaceRoles from the adapter: pass the IDs to execute.

## Host contract

Bind `Contracts\UserAccountWriter` to `persist(Model $target, UserOperation $operation, array $attributes): void`. It receives the NEW model or a freshly loaded target and must validate its allowlist, save account attributes only and, where appropriate, revoke sessions/tokens on the same connection. Delete may use soft deletion. Preserve target identity; do not edit RBAC, open/commit the transaction or perform SMTP, HTTP or push effects. The service checks persisted identity and connection after the adapter.

This is trusted host code, not a client-selected callback. Arbitrary PHP, listeners, SQL, direct Spatie calls and external configuration changes are not intercepted. Declare additional tables in transaction_tables. Inspection rejects missing or non-InnoDB tables before MariaDB/MySQL writes.

UserAuthorityChanged accepts actorId, targetId and operation, plus optional audit, correlationId and accountChanged, without account attributes or passwords. It notifies accepted invocations; AuthorityAudit represents only effective changes. Separate account effects from membership effects to avoid duplicate RBAC history. External effects must wait for commit.

## Configurable mandatory auditing

Bind Contracts\TransactionalAuditStore and install its tables before enabling audit.required=true. The adapter declares its connection and every written table. The package requires the exact RBAC connection instance and includes the tables in InnoDB inspection. record(AuthorityAudit, Connection) runs synchronously inside the transaction. Failures propagate and roll back changes. Without an adapter and with required=false, events alone do not promise automatic persistence.

AuthorityAudit uses allowlisted fresh state and normalized differences, without models or secrets. The correlation UUID belongs to the transactional operation and works for HTTP, CLI and internal calls. Account creation with assignments produces one compound difference; structural changes emit no per-member events. See [auditing](auditing.md) for format, operations, independent adapter example, atomicity limits and after-commit listeners.

## Eligible administrator

The optional Contracts\AdministrativeIdentity contract adds host identity invariants. The provider uses bindIf with UnrestrictedAdministrativeIdentity for generic behavior. Bind your implementation before resolving services; no business email, fields or tables are required by the package.

- assertActor validates persisted actor identity; allowsGlobalAdministration conditions global privilege in isAdmin, snapshot, isPolicyManager and RoleHierarchy.
- assertAccountControl checks targets and members indirectly affected by roles/policies.
- assertAccountMutation validates lifecycle operations and attributes before UserAccountWriter.
- assertRoles validates final sets for replacement, incremental operations, compound creation/update and contextual candidates.
- assertRoleMutation protects role identity/level on creation or update.

Write hooks run under the same lock after model reload. Candidate queries are informative and do not acquire durable authorization. Hooks must read persisted state, throw AuthorizationException/ValidationException where appropriate, and neither mutate nor call the engine recursively. Invariants supplement P/Q/D. False from allowsGlobalAdministration does not make a user a policy manager when its only role is the configured admin.

For example, a host may reserve a persistent identity and exclude unapproved accounts through ActorEligibility. Email, provisioning and recovery remain host code. The adapter exposes no HTTP bypass context.

EligibleAdministrator first limits SQL to holders of the configured admin role, then evaluates ActorEligibility in chunks of 100. It does not approximate eligibility with an active SQL condition, preserving custom contracts. Cost is proportional to examined administrator candidates and contract queries, without loading the entire users table.

The final check runs after persistence, under the lock, to detect any attribute affecting eligibility. Losing the last previously eligible administrator causes ValidationException and rollback of attributes, memberships, history and revocations. It does not arbitrarily block an installation without an administrator. Bootstrap/recovery are privileged, audited host procedures without an HTTP bypass.

## PHP services

| Service | Entry points |
|---|---|
| RoleManagementService | create, update, syncPermissions |
| DelegationPolicyService | create, update, syncPermissions, assignToRole |
| UserPermissionManagementService | assignRole, removeRole, replaceRoles, grantDirectPermission, revokeDirectPermission, replaceDirectPermissions |
| AuthorizedUserMutation | execute |
| AuthorityQueryService | roles, manageableRoles, permissions, userAuthority, assignableRoles, delegablePermissions, policies, policy, manageablePolicies, assignablePolicyRoles, capabilities |
| DelegationPolicyProvisioningService | run(dryRun=false), infrastructure only |
| InfrastructureInspector | inspect, assertReady |

Incremental operations read the current set inside the lock, including removal of an unassigned ID. Missing or foreign-guard IDs are rejected. Inherited permissions are not converted to direct grants.

## Functional entry and discovery

Configure abilities with permission names from YOUR catalog. Keys: roles.view/create/update/permissions, permissions.view, policies.view, users.view/create/update/suspend/reactivate/delete/roles/permissions and accounts.control. Empty arrays grant no entry to ordinary operators. The exact admin retains explicit global entry without Gate::before.

Policy management requires admin identity or a role in policy_manager_roles. policy_management_ability can add functional entry without turning a functional permission into manager identity; null adds no extra requirement. For example, policies.manage may map to the host's delegation-policies.manage permission. Manager identity does not grant global D: transfers remain bounded by active Q.

roles and policies require catalog permissions; manageableRoles and manageablePolicies check management entry. User candidates check assignment entry, target footprint and hierarchy; new additionally requires users.create. assignableRoles projects ADDING one role while keeping existing ones. Capabilities are informative projections, not durable authorization or guarantees for arbitrary payloads. Writers always reload and validate. No endpoint creates, renames or deletes catalog permissions.

General lists return full collections. Capabilities query each resource/consumer to reuse exact rules; no batch optimization or benchmark is promised. Hosts may paginate large-catalog presentation without replacing write checks.

## HTTP and CLI

See the [HTTP API](api.md). HTTP is disabled by default. Hosts can enable it with API, authentication and eligibility middleware. RequireActor rejects absent users even if host authentication middleware was mistakenly omitted; domain services still authorize operations.

permission-manager:check verifies schema, lock and every participating Spatie/host table. It does not repair engines.

permission-manager:provision --dry-run displays a read-only plan. permission-manager:provision recomputes it under the lock and applies it only without conflicts. provisioning explicitly lists existing role names; each receives an empty policy role-{name}. It creates no users, roles, permissions or passwords and never copies P into Q. It preserves managed name/content/active on already linked policies. A populated existing policy cannot be linked implicitly to a detached role; use authorized association instead. Admin is excluded. Ambiguous guard/slug, incompatible associations or other consumers produce conflicts without changes.
