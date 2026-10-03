# Release process

[Versión en español](releasing.es.md)

This guide describes publication of a stable Composer release. The version documented here is **1.0.0**. Use only the package directory as the Git root, keeping host applications and disposable consumers outside it. Composer derives versions from Git tags; do not add a version field to composer.json.

## Validation

Run from a source checkout with development dependencies:

```bash
composer validate --strict
composer audit
composer test
composer analyse
vendor/bin/pint --test
php tests/Support/run-mariadb.php
```

Supply explicit disposable-server credentials for MariaDB as described in [security](security.md). Also lint production PHP, check documentation links, review compatibility and exercise an independent consumer. Require passing CI on the release commit; local YAML validation does not establish a remote run.

## Source and archive

Review git status --short, git ls-files and git diff --cached. Ignore rules do not remove previously indexed files. Exclude vendor, environment files, local composer.lock, caches, databases, logs, coverage, credentials and temporary artifacts.

Source retains tests, CI, static-analysis configuration and internal historical notes; gitattributes excludes them from distribution. Archives retain composer.json, LICENSE, both READMEs, CHANGELOG, src, config, routes, database/migrations and public bilingual docs.

```bash
git archive --format=zip --output=../permission-manager-source.zip HEAD
composer archive --format=zip --dir=../dist --file=permission-manager-1.0.0
```

Inspect both archives and install the Composer artifact in a separate Laravel application. Use immutable artifact URLs/names or disable Composer's cache when validating a regenerated local ZIP; compare installed file hashes with the archive. Development scripts require a source checkout.

## Tag and registry

Review the version/date in CHANGELOG, public contracts, compatibility limits and release commit. After publication authorization, create the annotated tag and push it to the approved remote:

```bash
git tag -a 1.0.0 -m "Release 1.0.0"
git push origin main
git push origin 1.0.0
```

Use the approved release branch if it is not main. Register the repository in Packagist or refresh its existing package entry. Confirm metadata, source/dist references and the tag, then verify a clean installation:

```bash
composer require ahinest/laravel-permission-manager:^1.0
```

Verify autodiscovery, configuration, migration order, diagnostics, authorization, audit and rollback on disposable data. Publication commands are maintenance instructions, not evidence that they have already run.

## Moving consumers from path to a tag

In a reviewed branch, remove only this package's path repository entry and replace dev-main with ^1.0. Then run:

```bash
composer update ahinest/laravel-permission-manager --with-dependencies
```

Confirm composer.lock references the intended tag and vendor no longer uses a junction/symlink. Run the host's integration, authorization and audit suites. Review published migration differences without republishing old migrations or destructively rolling back a persistent database. Back up and explicitly apply only genuinely new schema changes.
