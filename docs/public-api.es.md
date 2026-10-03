# API pública de 1.0.0

[English version](public-api.md)

Namespace: `Ahinest\LaravelPermissionManager`. Conservar nombres de clases/métodos, nombres de parámetros (argumentos PHP nombrados), tipos nativos, valores de enums y orden de constructores de eventos entre versiones compatibles. Las firmas del código fuente son la referencia.

| Contrato | Métodos estables |
|---|---|
| ActorEligibility | allows(Model $actor): bool |
| SensitiveRoleResolver | names(): array de nombres de roles |
| AdministrativeIdentity | assertActor(Model $actor): void; allowsGlobalAdministration(Model $actor): bool; assertAccountControl(Model $actor, Model $target): void; assertAccountMutation(Model $actor, ?Model $target, UserOperation $operation, array $attributes): void; assertRoles(Model $actor, ?Model $target, array $final): void; assertRoleMutation(Model $actor, ?Spatie Role $role, array $attributes): void |
| UserAccountWriter | persist(Model $target, UserOperation $operation, array $attributes): void |
| TransactionalAuditStore | connection(): Connection; tables(): array de nombres de tablas; record(AuthorityAudit $event, Connection $connection): void |

Los contratos están en Contracts. El anfitrión conserva su User Eloquent con HasRoles, sin heredar de un User del paquete. Los roles configurados extienden Role de Spatie e incorporan HasDelegationPolicy. Todos los modelos/tablas utilizan la misma conexión predeterminada. Las firmas nativas forman parte del contrato de compatibilidad.

| Servicio | Entradas |
|---|---|
| RoleManagementService | create(actor, data); update(actor, role, data); syncPermissions(actor, role, ids) |
| DelegationPolicyService | create(actor, data); update(actor, policy, data); syncPermissions(actor, policy, ids); assignToRole(actor, role, policyId nullable) |
| UserPermissionManagementService | replaceRoles(actor, target, roleIds); replaceDirectPermissions(actor, target, permissionIds); assignRole/removeRole(actor, target, roleId); grantDirectPermission/revokeDirectPermission(actor, target, permissionId) |
| AuthorizedUserMutation | execute(actor, operation, target nullable, attributes, roles nullable = null, permissions nullable = null) |
| AuthorityQueryService | roles, manageableRoles, permissions, capabilities, userAuthority, assignableRoles, delegablePermissions, policies, policy, manageablePolicies, assignablePolicyRoles |
| InfrastructureInspector | inspect(): array; assertReady(): void |
| DelegationPolicyProvisioningService | run(bool $dryRun = false): array; solo infraestructura |

Consultar [integración](integration.es.md), [delegación](delegation.es.md), [HTTP](api.es.md) y [auditoría](auditing.es.md) para parámetros, payloads y fronteras. Las relaciones Role::delegationPolicy y DelegationPolicy::permissions/roles, las claves de configuración y las firmas CLI permission-manager:check y permission-manager:provision {--dry-run} también son superficies de integración.

Casos de UserOperation: Create, Update, Suspend, Reactivate y Delete, con valores de cadena en minúsculas. Campos readonly de AuthorityAudit en orden de constructor: eventId, actorId, actorType, resourceType, resourceId, operation, before, after, changes, occurredAt, guard y correlationId. UserAuthorityChanged conserva actorId, targetId y operation, seguidos de audit, correlationId y accountChanged opcionales. Los IDs de usuario pueden ser enteros/cadenas si lo admite el esquema anfitrión; los de roles/permisos Spatie son enteros. Conservar nombres de operación y listas de campos permitidos en auditoría.

AuthorizationException indica denegación; ValidationException, entrada o invariantes inválidas; ModelNotFoundException, objetivos ausentes; LogicException, configuración/infraestructura no soportada. Los fallos de persistencia anteriores al commit se propagan y revierten la transacción. Un error posterior de caché no deshace un commit confirmado. HTTP traduce excepciones mediante Laravel/RequireActor. Los eventos preceden al commit; no son notificaciones de éxito durable.

PermissionDelegationService y RoleHierarchy ofrecen primitivas confiables de lectura/comprobación, no sustitutos de servicios autorizados. Las instantáneas, AuditContext/AuditState, Models y callbacks directos de RbacWriteTransaction son mecanismos internos de composición, nunca vías de bypass controladas por el cliente. Registrar cambios intencionales del API en CHANGELOG antes de etiquetar.
