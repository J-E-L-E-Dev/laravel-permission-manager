# Changelog

## Unreleased — phase 2.2, 2026-09-22

- Immutable AuthorityAudit event with explicit allowlists, fresh before/after diffs, normalized membership deltas, UTC timestamps and transaction correlation.
- Optional TransactionalAuditStore contract; mandatory same-connection persistence fails closed and participates in infrastructure inspection.
- Auditing across authorized role, policy, user membership and compound writes, plus effective structural infrastructure provisioning; no-op suppression without weakening authorization.
- Backward-compatible optional metadata on UserAuthorityChanged; DomiYa separates account effects from RBAC effects without duplicating assignments.
- Host-owned rbac_audits migration and nullable correlation on existing histories; prior data preserved.
- New SQLite/integration and real MariaDB audit tests, Spanish/English audit contracts. No stable version, tag or publication.

## Unreleased — phase 2.1, 2026-09-21

- Optional AdministrativeIdentity contract; DomiYa reserves global identity by persistent ID and canonical email.
- Reserved-only CLI provisioning/recovery and recipient-owned password establishment for administrative account creation.
- Exact results are retained in the phase 2.1 historical status document.

## Unreleased — phase 2, 2026-09-21

- AuthorizedUserMutation and UserAccountWriter coordinate host account attributes and RBAC atomically, without allowing ambient MySQL/MariaDB transactions.
- Last eligible administrator protection includes eligibility attribute changes, suspension and deletion; candidate evaluation is chunked and retains custom eligibility rules.
- Incremental role/direct-permission assignment and removal read current state under the common lock.
- Authorized catalog, manageable-resource, contextual-candidate and capability queries; optional authenticated HTTP API remains disabled by default.
- Explicit policy provisioning with dry-run, full-plan conflict detection, idempotency and no implicit delegation grants.
- Infrastructure inspection verifies every configured Spatie and participating host table uses InnoDB.
- Optional additional functional entry for configured policy managers; configured identity and P/Q/D checks remain mandatory.
- DomiYa integration adds Sanctum, own profile, password recovery, account lifecycle, revocation, audit and privileged verified-account bootstrap/recovery.
- Expanded SQLite, HTTP, host integration and separate-process MariaDB tests. See status.es.md for actual executed totals.
## Unreleased — initial extraction, 2026-09-20

- Independent Composer library, Laravel auto-discovery and explicit configuration/migration publishing.
- Host user model and custom Spatie role extension; configurable single guard, admin identity, hierarchy and sensitive identities.
- P/Q/D delegation engine adapted with its original 107-case suite.
- Common transaction lock and authorized role, policy and existing-user authority services.
- Schema preservation and ownership-aware rollback; no catalog, user or role seeding.
- New last-admin role-removal check and admin identity rename protection, limited to package services.
- SQLite/Testbench and separate disposable MariaDB integration suites.
- English/Spanish installation, model, delegation, integration and security documentation.
- HTTP, contextual administrative queries and the provisioning CLI remain pending. No stable version/tag or public release.

