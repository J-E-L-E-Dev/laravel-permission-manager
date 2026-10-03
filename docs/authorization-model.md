# Authorization model

[Versión en español](authorization-model.es.md)

P(role) is the role's Spatie functional permission set. Q(role) is its policy's permission set if active, otherwise empty. D(user) is the union of Q across persisted roles in the configured guard. Direct user grants and policy-manager status do not enlarge D. An exact configured admin role gives D equal to the current catalog of that guard, without physical grants or a Gate::before functional bypass.

A replacement validates current ∪ final ⊆ D_before, including retained and removed permissions. Role replacement validates P and active Q of every outgoing/incoming role, identities and hierarchy. Policy-content and policy-link changes inspect stored content even when inactive. Candidate state is calculated before persistence. Snapshots are immutable internal values; never construct them from a request or reuse them to authorize later writes.

Hierarchy uses a configurable inclusive integer range; smaller means stronger. No named commercial roles or implicit role are supplied. Null/out-of-range levels do not confer hierarchical authority. Default non-admin management is strictly below the actor's level. `allow_same_owned_role=true` permits the same owned role at equal level, but does not remove sensitive-identity, member-footprint, own-policy or promotion checks. Distinct peer roles remain blocked. No number of ordinary high-level roles creates global admin status.

Reserved identities include admin, policy managers and `reserved_role_names`. Sensitive identities add the fresh `SensitiveRoleResolver` results. A reserved name alone is not a policy manager. New role names use lowercase ASCII segments; comparisons of privileged identities and guards are exact. Non-admins cannot rename existing identities or transfer sensitive roles. Ambiguous cross-guard names are rejected in write composition; this is isolation under a single-guard contract, not general multiguard support.

Shared P/level mutations inspect every user holding the role. Policy content/activity mutations inspect every consumer role and its members. Footprints include direct grants, P, active Q and qualitative identities. Non-admins cannot change any owned role's policy, even to the same policy or null. No-op policy assignments are still authorization decisions.

Host permission names in `abilities` authorize entry independently of D. Empty mappings deny ordinary operators. Manager identities authorize policy operations, but scope/hierarchy/consumer checks still apply. The global admin bypass is local to the package's administrative services.
