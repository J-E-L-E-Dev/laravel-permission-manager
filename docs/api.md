# Optional Laravel Permission Manager API

[Versión en español](api.es.md)

Configurable prefix: `/api/v1/permission-manager`. Disabled by default: `http.enabled=false`. When enabling it, configure host authentication middleware, such as session authentication or Sanctum, and host eligibility. Send `Accept: application/json`. Public services reauthorize every mutation.

Data responses use `{ "data": ... }`. Creation returns 201; reads and updates return 200. Errors: 401 unauthenticated, 403 unauthorized, 404 missing resource, 409 SQL uniqueness/deadlock/lock-wait conflict, and 422 invalid payload or invariant. Refresh context before retrying. Unexpected database failures are reported internally and return a generic 500. Configure APP_DEBUG=false in deployments.

## Routes

| Method | Relative route | Payload / result |
|---|---|---|
| GET | /capabilities | Functional entry map with literal keys such as `users.create` |
| GET | /roles | Authorized catalog: level, P, active Q and capabilities |
| GET | /roles/manageable | Discovery with roles.update, independent of catalog access |
| POST | /roles | name, level, optional description; no guard, permissions or policy |
| PUT | /roles/{role} | Optional name, level, description; admin cannot be renamed |
| PUT | /roles/{role}/permissions | `{"ids":[1,2]}`: replace P |
| PUT | /roles/{role}/delegation-policy | `{"delegation_policy_id":3}` or null |
| GET | /permissions | Guard-scoped catalog; read only |
| GET | /policies | Authorized catalog and per-policy capabilities |
| GET | /policies/manageable | Read access for configured managers, independent of catalog access |
| GET | /policies/{policy} | Authorized detail |
| POST | /policies | name, slug; active=false by default, optional permissions=[IDs] |
| PUT | /policies/{policy} | name and/or active; `{"active":false}` deactivates; slug and permissions forbidden |
| PUT | /policies/{policy}/permissions | `{"ids":[1,2]}`: replace stored Q |
| GET | /policies/{policy}/assignable-roles | Association targets; requires active policy and manager |
| GET | /users/{user}/authority | Roles, direct, inherited, effective permissions and capabilities; no account attributes |
| GET | /users/{user}/assignable-roles | Candidates for adding a role; user accepts `new` |
| GET | /users/{user}/delegable-permissions | grantable and revocable; user accepts `new` |
| PUT | /users/{user}/roles | `{"ids":[1,2]}`: full replacement |
| PUT | /users/{user}/roles/{role} | Add without losing concurrent memberships |
| DELETE | /users/{user}/roles/{role} | Remove while preserving other memberships |
| PUT | /users/{user}/permissions | `{"ids":[1,2]}`: direct replacement |
| PUT | /users/{user}/permissions/{permission} | Incremental direct grant |
| DELETE | /users/{user}/permissions/{permission} | Incremental direct revocation |

All authority IDs are positive integers; [] is explicit revocation. Policy activation rechecks stored content, consumers and members. No client-supplied can_* flag participates in authorization. Metadata capabilities do not promise that every name or level change is valid.

Operators may access candidates without catalog permission. Target-user information is additionally restricted by footprint and hierarchy. Reads are not durable snapshots: a later revocation invalidates a previously displayed selection.

The API does not create or edit accounts: the host owns that layer and uses AuthorizedUserMutation. It includes no endpoints for permission-catalog administration, infrastructure provisioning or administrator recovery.

The host can bind `AdministrativeIdentity` to restrict administrative identities. These rules apply equally to PHP services, replacements, incremental operations and compound writes; global administration does not bypass them. Role candidates exclude prohibited transfers. A host adapter can prevent transfer, suspension or deletion of a reserved identity. These are explicit host invariants, not package defaults.
