# Verification status — 1.0.0

[Versión en español](status.es.md)

Version: **1.0.0**. Documentation review date: **2026-10-02**. Verification is scoped to the environments and suites below; it is not a security certification.

## Environments

| Component | Verified environment |
|---|---|
| PHP | 8.2.28, 8.3.35 and 8.4.26 |
| Laravel / Spatie | Laravel 12.69.2 and minimum development resolution 12.0.1; Spatie 6.25.0 |
| SQLite | Functional, installation and documentation suites |
| MariaDB | 10.6.12 with InnoDB, separate-process concurrency |
| Composer | 2.8.11 |
| Analysis | PHPStan/Larastan level 5 |

The full PHP/current-minimum matrix was executed on 2026-09-22 with 216 tests and 692 assertions per combination. The 1.0.0 documentation checks supplement those functional tests. See [compatibility](compatibility.md) for the Laravel 12.0.0 experiment, dependency advisories, per-runtime resolution and unverified platforms.

## Available suites

Verification on 2026-10-02: **220 tests / 700 assertions** in the package suite and **21 tests / 127 assertions** on MariaDB. Composer validation, level-5 analysis, Pint and syntax checks passed.

| Suite | Scope |
|---|---|
| composer test | P/Q/D, hierarchy, identity, services, HTTP, migrations, cache, audit and documentation |
| composer analyse | Production PHP, configuration, migrations and routes |
| vendor/bin/pint --test | Source formatting |
| tests/Support/run-mariadb.php | 21 tests / 127 assertions: concurrency, rollback, audit and partial DDL |
| DocumentationTest | Named-method PHPDoc, bilingual pairs and detector fixtures |
| Independent Laravel consumer | 26 checks per clean/existing-Spatie scenario |

The source inventory covers **182 named production methods**, all with individual descriptive PHPDoc. Public docs contain **13 English/Spanish pairs**, plus both READMEs. Interfaces, traits, constructors, private/protected methods and migration up/down are included; closures are excluded.

Independent installation uses Composer with isolated VCS/archive sources rather than the host path repository. Checks include autodiscovery, publication, Artisan migrations/rollback, preserved Spatie data, host User, services, optional HTTP and mandatory audit rollback. Distribution review checks the real index/archive and compares installed files.

## CI and limits

GitHub Actions defines PHP 8.2/8.3/8.4 × lowest/latest and a separate MariaDB 10.6 job. The workflow is locally validated; no remote execution is recorded. A published-tag installation is not part of this verification record. Follow the [release process](releasing.md) when publishing.

Actual MySQL servers, other MariaDB versions, PHP outside the matrix, external SMTP/mobile clients and load performance are unverified. Historical minimum dependencies have security advisories; functional success is not a production recommendation. Noncooperating SQL, cross-connection transactions and arbitrary host adapters are outside the guarantees. Cache errors after commit and partially committed DDL require reconciliation or reviewed recovery; see [security](security.md) and [migrations](migrations.md).
