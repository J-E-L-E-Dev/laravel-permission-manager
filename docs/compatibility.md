# Compatibility

[Versión en español](compatibility.es.md)

## Requirements

Version 1.0.0 declares PHP ^8.2, Illuminate support/database/validation ^12.0 and Spatie Permission ^6.25, with PDO, filter and JSON. Install pdo_sqlite or pdo_mysql for the selected database; Laravel and dependencies impose additional extension requirements. Teams, wildcards, Passport client-credential actors, simultaneous multiple guards and noninteger Spatie keys are unsupported.

## Verified combinations

| Environment | Verification |
|---|---|
| PHP 8.2.28 / Laravel 12.69.2 / Spatie 6.25.0 | Functional suite and independent Laravel consumer |
| PHP 8.3.35 and 8.4.26 / same current dependency set | Functional suite and static analysis |
| PHP 8.2.28, 8.3.35 and 8.4.26 / Laravel 12.0.1 / Spatie 6.25.0 / minimum development dependencies | Functional suite; resolve dependencies for each runtime |
| Laravel 12.0.0 / Spatie 6.25.0 / minimum dependencies / PHP 8.2.28 | Historical clean/existing consumer experiment |
| Laravel 12.0.0 / current transitive dependencies | Composer resolves, but Artisan discovery fails; not a verified working combination |
| MariaDB 10.6.12 / InnoDB / PHP 8.2.28 | Separate-process concurrency, audit rollback and interrupted DDL |
| SQLite / PHP 8.2, 8.3 and 8.4 | Functional coverage; no substitute for MariaDB concurrency |
| Other MariaDB versions, actual MySQL servers or PHP outside these rows | Not verified |

The version matrix was executed on 22 September 2026. Spatie 6.25.0 was both the declared minimum and newest permitted 6.x resolved then; Laravel 12.69.2 was the current resolved Laravel. These are recorded versions, not claims about today's registry. The PHP 8.3/8.4 Windows binaries were verified against official SHA-256 checksums. See [verification status](status.md) for suites and dates.

## Development tooling

Development dependencies use Testbench ^10.0, Pest ^3.8, Pint ^1.24,
PHPStan ^2.2.16 and Larastan ^3.12.2.

PHPStan and Larastan are development tools and are not installed as runtime
dependencies of applications that require this package.

The CI `lowest` matrix continues to resolve the lowest supported runtime
dependencies for PHP, Laravel and Spatie Permission. The static-analysis tools
retain their explicitly supported minimum versions so the same analysis
semantics are used across the compatibility matrix.

Dependencies are resolved independently on PHP 8.2, 8.3 and 8.4 because
transitive platform constraints may differ between runtimes.

## Dependency security and CI

Historical minimum consumer/development graphs reported respectively 38 advisories in 10 packages and 42 in 12. They are disposable compatibility experiments, not production recommendations. The current graph reported none at installation on the verification date. Functional success does not certify dependency security; keep production dependencies patched and run composer audit in the consuming application.

The CI matrix resolves lowest/latest dependencies on PHP 8.2/8.3/8.4. Composer security policy can select different minimum versions. Declared ranges are not evidence that every transitive combination works. Remote workflow execution is not part of the recorded evidence; consult [verification status](status.md).
