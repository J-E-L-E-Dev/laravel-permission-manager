# Migration ownership and partial installation

[Versión en español](migrations.es.md)

Spatie's configured table names and pivot/morph keys are respected. Package table names are fixed: lpm_delegation_policies, lpm_policy_permission, lpm_write_locks and lpm_schema_columns. All four explicitly request InnoDB; SQLite ignores the engine declaration. Existing Spatie tables and participating host/audit tables must already be transactional. The diagnostic reports non-InnoDB tables and never changes their engine automatically.

Install Spatie's schema first. The extension adds nullable role description/level, permission description if absent, and role delegation_policy_id. The ownership journal records only columns actually added. Existing users, roles, permissions, role assignments, direct permissions and role-permission memberships are preserved. Existing level/description columns are retained. A preexisting delegation_policy_id or package table is rejected before automatic repair.

Rolling back a successfully completed migration removes package policy rows, Q memberships, role-policy associations, the coordination row, journal and only the journaled extension columns. Original Spatie tables and host-owned description/level columns remain. Values in package-owned extension columns are lost. Never use this rollback as a recovery shortcut on a persistent production database.

## Interrupted MariaDB DDL

DDL may commit independently of the migration ledger. A failure can occur between ADD COLUMN and the ownership-journal INSERT, leaving a real but unjournaled column. The journal alone therefore cannot prove ownership after an interrupted installation. Re-running up rejects the collision rather than adopting or deleting structures.

1. Stop administrative writers and preserve a schema/data backup, migration log, configuration and pre-installation schema evidence.
2. Run permission-manager:check; inspect the migrations ledger, SHOW CREATE TABLE for affected tables, engines, foreign keys, journal rows and lock row. The diagnostic checks participating tables/engines/lock, not every possible column or constraint inconsistency.
3. Compare each structure with the pre-installation snapshot. If origin cannot be established, stop and request a reviewed database migration. Do not infer ownership from a familiar column name or an absent journal row.
4. Choose a reviewed restoration of the pre-installation backup or an explicit forward repair. Any cleanup must be limited to structures proven to have been added by the failed attempt. Preserve original Spatie rows/pivots and host columns. There is no automatic repair command.
5. Recheck infrastructure, constraints, data counts, authorization and audit persistence before resuming writes. Review the migration ledger only after the schema matches the intended state.

The disposable MariaDB regression interrupts execution immediately after adding permissions.description and before journaling it. It proves the column survives, diagnosis fails, retry refuses the collision and original role data survives. Fixture-only recovery removes columns whose absence was recorded before the test, then reinstalls and rolls back normally. This is evidence for that failure window, not a guarantee covering every DDL failure or every external schema.
