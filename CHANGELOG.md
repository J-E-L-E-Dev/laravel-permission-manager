# Changelog

## [1.0.0] - 2026-10-16

- Explicit P/Q/D delegation and configurable administrative hierarchy over Spatie Permission.
- Authorized role, policy, user assignment and account lifecycle services with fresh checks under a shared transaction lock.
- Shared-role and policy-consumer checks, reserved administrative identities and protection of the last eligible administrator.
- Host contracts for account persistence, administrative identity, actor eligibility, sensitive roles and transactional audit storage.
- Optional authenticated HTTP API, contextual discovery, infrastructure diagnostics and explicit empty-policy provisioning.
- Immutable authority audit events with effective differences, transaction correlation and suppression of unchanged operations.
- Ownership-aware migrations and rollback, with explicit InnoDB for all package tables.
- Permission cache invalidation after transaction commit or rollback, including synchronous observer and audit failures.
- English and Spanish documentation, Spanish source PHPDoc and automated documentation coverage checks.
