# Security boundary

[Versión en español](security.es.md)

## Coordinated writes

Each management write acquires the common coordination row before reading fresh authority and persisting changes. MariaDB/MySQL requires InnoDB on every participating table and rejects ambient transactions to avoid stale REPEATABLE READ snapshots. SQLite reserves its writer with an update because SELECT FOR UPDATE is unavailable. Missing coordination is an error, never silently repaired.

The global lock favors integrity over throughput. No load benchmark or automatic deadlock retry policy is promised. Driver implementation does not establish tested support for every server; see [compatibility](compatibility.md).

| Operation | Boundary |
|---|---|
| Authenticated administration | Authorized service with an explicit persisted actor |
| Host catalog/bootstrap provisioning | Privileged infrastructure, not implicit user authority |
| Migrations/seeders | Controlled infrastructure that can change authority outside services |
| Direct Spatie/Eloquent calls | Not intercepted or automatically authorized |
| SQL/Tinker/database administrator | Outside package enforcement |

Do not expose assignRole/syncRoles/syncPermissions/givePermissionTo directly through custom administrative endpoints or fabricate an admin actor. Read primitives do not authorize catalog or target-footprint disclosure. Host listeners must not secretly modify RBAC after authorization. External identity resolvers must return fresh state and coordinate concurrent changes. Arbitrary trusted PHP/SQL is outside enforcement.

## Security coverage

Testbench uses a host User fixture without business fields. Tests cover current ∪ final scope, role P ∪ active Q, hierarchy, sensitive identities, multiple roles, invalid IDs, custom table/pivot/morph names, shared members/consumers, multi-step self-escalation, last-administrator eligibility, HTTP authorization, account rollback and preservation of existing Spatie data.

Contextual discovery is informative; every write repeats authorization. Policy managers gain no extra functional or delegable authority. Reserved identity rules supplement P/Q/D, including indirect control through shared roles. The permission catalog has no administrative mutation endpoint.

The host owns session/token revocation, soft deletion, bootstrap and audited recovery. It must verify its own adapters and external integrations. SMTP delivery and external mobile/web applications are not covered end to end. Unsupported areas include teams, wildcards, simultaneous multiple guards, noninteger Spatie keys and cross-connection transactions. Candidate capabilities query resources individually; no batch-performance guarantee is made.

## Disposable MariaDB tests

From a source checkout, supply credentials for a dedicated test server:

```text
LPM_TEST_HOST=127.0.0.1
LPM_TEST_PORT=3306
LPM_TEST_USERNAME=<test-user>
LPM_TEST_PASSWORD=<test-password>
```

```bash
php tests/Support/run-mariadb.php
```

The helper validates MariaDB, creates a random database, runs tests and drops that exact database in finally. It does not read a host application's environment file. The test user needs permission to create/drop disposable databases.

For a manually prepared disposable database, the guarded direct runner uses:

```text
LPM_DISPOSABLE_DATABASE=1
LPM_TEST_DATABASE=lpm_test_<16 lowercase hexadecimal characters>
```

```bash
php vendor/bin/pest -c phpunit.mariadb.xml --compact
```

Supply the same connection variables. The suite validates the actual database/name guard and drops all tables in that database. Never use persistent application data.

Separate PHP processes and barriers exercise revocation, policy deactivation/unlinking, changed consumers/members, concurrent role/direct assignments, account creation versus revocation, suspension versus authority changes, last-admin races, audit rollback/no-op differences, non-InnoDB pivots and interrupted DDL. Writers deliberately preload stale relations before waiting. Privileged first-writer fixtures use the same lock; the tests do not serialize arbitrary noncooperating SQL. Executed results are listed in [verification status](status.md).

## Permission cache and rollback

The transaction boundary invalidates Spatie's in-memory/shared permission cache after commit or rollback. Tests cover synchronous observers loading uncommitted permissions, mandatory audit failure, successful P changes, memberships/direct grants and policies. P/Q/D write authorization reads persisted state under the lock, never this cache.

Eloquent instances retained by callers can still have stale loaded relations; use the returned fresh model or refresh them. There is no universal consistency guarantee for other long-lived processes or noncooperating readers. Cache availability remains an operational dependency: an error after commit cannot undo a confirmed write. Reconcile persisted state and history before retrying. Host external effects must wait for commit.

See [auditing](auditing.md), [migrations](migrations.md) and [static analysis](static-analysis.md) for adapter trust, DDL recovery and analysis limits.
