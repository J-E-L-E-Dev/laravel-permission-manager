# Authority auditing

[Versión en español](auditing.es.md)

The package emits Events\AuthorityAudit, a common immutable event with an explicit operation name. The host selects persistence through Contracts\TransactionalAuditStore. The package installs no audit table and depends on no application-specific models or tables.

## Covered operations

| Service / method | operation | Audited state |
|---|---|---|
| RoleManagementService::create | role.created | name, guard_name, level, description; no additional relations |
| RoleManagementService::update | role.updated | Only fields that effectively differ |
| RoleManagementService::syncPermissions | role.permissions.changed | Previous/final P, added and removed IDs |
| DelegationPolicyService::create | delegation_policy.created | name, slug, active and initial Q, including empty |
| DelegationPolicyService::update | delegation_policy.updated | name and active changes |
| DelegationPolicyService::syncPermissions | delegation_policy.permissions.changed | Previous/final stored Q, added and removed IDs |
| DelegationPolicyService::assignToRole | role.delegation_policy.changed | Previous/new delegation_policy_id; null distinguishes linking/unlinking |
| UserPermissionManagementService | user.roles.replace/add/remove, user.permissions.replace/add/remove | Roles or direct permissions effectively changed |
| AuthorizedUserMutation::execute | user.create/update/suspend/reactivate/delete | One combined roles/direct_permissions difference, if changed |
| DelegationPolicyProvisioningService::run | delegation_policy.created and role.delegation_policy.changed | Effective creation/link only, infrastructure actor and common correlation |

Structural provisioning is privileged infrastructure, not an authorized user route. actorId is null and actorType is infrastructure: no human operator identity is invented. Dry-run, conflicts and unchanged rows emit no success history.

Sets are read from persistence after locking, before/after the write, never from preloaded relations. Role/permission IDs are normalized, deduplicated and numerically sorted. Guard, P/Q/D, hierarchy, current/final content, consumer, member and protected-identity checks remain mandatory. Identical policy associations, equivalent sets and equal attributes produce no AuthorityAudit. Structural mutations emit one event per resource/change, not per member.

## Event contract

Public readonly properties, without Eloquent models:

| Property | Content |
|---|---|
| eventId | Distinct event UUID |
| actorId | int\|string\|null; null only for infrastructure |
| actorType | user or infrastructure |
| resourceType | role, delegation_policy or user |
| resourceId | Historical int\|string resource identifier |
| operation | Operation name from the preceding table |
| before / after | Maps of relevant changed fields; before is empty for structural creation |
| changes | Per-field before/after; sets include added and removed |
| occurredAt | UTC ISO 8601 timestamp with microseconds |
| guard | Configured RBAC guard |
| correlationId | Transaction-operation UUID, independent of HTTP/sessions |

Example P synchronization changes: `{"permissions":{"before":[1,2],"after":[1,3],"added":[3],"removed":[2]}}`. A level and description change produces one event with two fields. Q uses resource delegation_policy, distinct from role P and effective user permissions.

AuditState uses explicit allowlists. User events contain only role/direct-permission IDs, not names, emails, account attributes, passwords, hashes, tokens, cookies, configuration or HTTP payloads. Comparing account attributes internally does not add them to events. Spatie role/permission IDs remain integers; historical user IDs preserve integers or strings supported by the host schema.

## Persistence and transaction boundary

Default audit.required=false without an adapter supplies events but **does not promise automatic durable history**. A bound TransactionalAuditStore always runs synchronously before AuthorityAudit, even if Laravel observers are faked or disabled. audit.required=true rejects writes when the binding is absent.

The contract declares connection(): Illuminate\Database\Connection, tables(): array and record(AuthorityAudit $event, Connection $connection): void. It requires the same RBAC connection instance, an active transaction and declared tables. InfrastructureInspector includes those tables and checks existence/InnoDB on MariaDB/MySQL before writing. record must insert through the supplied connection. Exceptions propagate; there is no silent queue or external-log fallback.

Sequence: common lock → fresh authorization/previous state → mutation → effective difference → mandatory insert → synchronous Laravel event → remaining listeners → commit. Persistence or later listener failures roll back mutation and history together. Observing an event inside the transaction **does not announce a completed commit**.

The adapter is trusted code: no commit/rollback, different connection or external effects. The package checks the declared connection and verifies unchanged transaction depth/PDO on return. These checks cannot undo an adapter's improper commit or prove arbitrary code actually inserted its record. Atomicity requires a conforming implementation. Independent test adapters insert through the supplied connection and verify real rollback.

## Independent Laravel example

Create a host migration before requiring auditing:

```php
Schema::create('authority_history', function (Blueprint $table) {
    $table->engine = 'InnoDB';
    $table->id();
    $table->uuid('event_id')->unique();
    $table->json('payload');
});
```

Implement the synchronous adapter:

```php
use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class HostAuditStore implements TransactionalAuditStore
{
    public function connection(): Connection { return DB::connection(); }
    public function tables(): array { return ['authority_history']; }
    public function record(AuthorityAudit $event, Connection $connection): void
    {
        $connection->table('authority_history')->insert([
            'event_id' => $event->eventId,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
        ]);
    }
}
```

In the provider's register method bind `$this->app->bind(TransactionalAuditStore::class, HostAuditStore::class);`. Configure `'audit' => ['required' => true]` in permission-manager.php. Run `php artisan permission-manager:check`. This payload is the allowlisted event, not an Eloquent model.

Additional listeners may observe the event but must not insert another copy of mandatory history. External observability must wait for commit:

```php
Event::listen(AuthorityAudit::class, function (AuthorityAudit $event) {
    DB::afterCommit(function () use ($event) {
        // Dispatch an observation or notification job here.
        // Mandatory history has already been persisted.
    });
});
```

An after-commit queue does not guarantee exactly-once external delivery or survival of a process crash between commit and dispatch. Hosts requiring this guarantee must implement a transactional outbox and idempotent consumers; these are outside the package.

## Compatibility and compound operations

`new UserAuthorityChanged(actorId, targetId, operation)` remains valid. Its original properties are followed by optional audit, correlationId and accountChanged parameters. It notifies accepted invocations even when synchronization changes nothing; **AuthorityAudit** represents effective differences. Do not turn each account event into duplicate RBAC history.

Creation with initial roles/direct permissions emits one user.create, not separate assignment operations. Compound updates emit one user.update containing changed sets. Unchanged memberships produce no AuthorityAudit; account history remains a host responsibility. accountChanged indicates whether persisted account attributes changed, excluding automatic timestamps, without exposing values. correlationId links it to RBAC history. Older manual three-argument emissions leave additional fields null and permit legacy host handling.

Hosts may separate account, authority and infrastructure history. Compound creation may have distinct account and assignment effects sharing correlation; they must not duplicate the same assignment. Administrative recovery retains its own auditing.

## Queries, retention and limits

Preserve historical identifiers without cascading deletion foreign keys. Index resource, actor and correlation; order by time and local ID. Hosts authorize history queries and control retention/backups. The package provides no public audit editing, deletion or query endpoints. Records reconstruct concrete changes, not complete effective-permission snapshots at every instant.

SQL, Tinker, external Spatie/Eloquent calls, catalog changes outside services, migrations and noncooperating writes are not automatically audited. There is no generic rejected-attempt audit: denials emit no success event and are not replaced by attempt-logging failures. Host infrastructure auditing is a separate mechanism.

Tests cover fresh state, no-ops, privacy, compatibility, missing/wrong connections, mandatory failures, rollback after events, correlation and after-commit effects; MariaDB tests exercise real races. See [verification status](status.md) for executed results.
