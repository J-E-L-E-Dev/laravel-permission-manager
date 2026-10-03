# Static analysis and CI

[Versión en español](static-analysis.es.md)

PHPStan/Larastan are development dependencies. `composer analyse` runs level 5 over src, config, migrations and routes. There is no generated error baseline or blanket ignore for undefined properties, argument types or return types. Larastan boots Testbench without a host application.

Public user parameters remain Eloquent Model. PHP cannot express an intersection with the HasRoles trait. One exception permits only roles, permissions, syncRoles and syncPermissions on that exact Model type in src. Models::user validates HasRoles at runtime; configuration-boundary tests enforce it. Other undefined methods/classes still fail analysis.

The small RBAC model stub describes the extended Spatie role schema and its required HasDelegationPolicy relation. It adds no behavior; Models::role validates the configured subclass/trait. Permission relations assume configured Spatie Permission models and integer keys. Collections/relations have explicit generic annotations. Optional host attributes use getAttribute. Scalar user lookups use whereKey()->firstOrFail(), retaining persisted identity semantics without find's scalar-or-array return ambiguity.

Larastan's optional collection-optimization rule is disabled: modelKeys intentionally works with hydrated configured models and their casts/scopes. This does not suppress authorization/type errors. Runtime validation/tests remain essential; level 5 is not proof of every nullable or mixed-value path.

CI checks Composer, dependency resolution, tests, analysis, Pint and syntax on PHP 8.2/8.3/8.4, lowest/latest. A separate MariaDB 10.6 service runs the package-owned disposable database helper, using dummy credentials for that ephemeral service. No application configuration or persistent host database is read. See [verification status](status.md) to distinguish local configuration from remote execution.

The analysis-only config detects whether the installed Larastan MorphTo stub already declares BelongsTo. A small legacy BelongsTo stub is loaded only for older Larastan versions that lack it, avoiding duplicate declarations on current versions. Both lowest and latest resolutions were analyzed.

DocumentationTest inspects named methods in src and database/migrations through native tokens without executing migrations. It requires individual descriptive PHPDoc, covers interfaces, traits, constructors and visibility levels, and excludes closures. It also checks docs/*.md pairs and both READMEs. Detector fixtures cover attributes, references and rejection of comments belonging to other declarations. Technical translation equivalence and Spanish prose quality require editorial review; the test does not claim automatic language detection.
