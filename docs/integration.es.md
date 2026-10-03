# Integración de cuentas, autoridad y auditoría

[English version](integration.md)

## Transacción compartida

`AuthorizedUserMutation::execute(actor, UserOperation, target|null, attributes, roles|null, permissions|null)` es la entrada pública para crear, actualizar, suspender, reactivar o eliminar cuentas. La creación exige target null; las demás operaciones exigen una cuenta persistida. `roles` y `permissions` contienen IDs enteros existentes del guard configurado. Null conserva la relación; [] la vacía explícitamente. Crear sin relaciones produce una cuenta sin autoridad: el paquete no infiere un rol comercial.

La secuencia es: abrir transacción propia, adquirir la fila común, recargar operador y objetivo, comprobar elegibilidad y permiso funcional de la operación, jerarquía y footprint ACTUAL del objetivo, validar las relaciones actuales/finales contra D previo, invocar el adaptador del anfitrión, persistir relaciones, comprobar que siga existiendo un administrador elegible y emitir `UserAuthorityChanged`. Cualquier excepción antes del commit revierte todas las tablas transaccionales participantes. Un fallo posterior del backend de caché no deshace un commit ya confirmado; consultar y reconciliar el estado persistido antes de reintentar (véase security.es.md). Retirar roles y cambiar credenciales en la misma petición no evita comprobar la autoridad anterior.

No envolver este servicio en una transacción MariaDB/MySQL externa. Cada entrada autorizada es propietaria de su transacción. `UserAuthorityRules` comparte comprobaciones de lectura entre consultas y escrituras, sin exponer un contexto capaz de saltarse autorización. No llamar a replaceRoles desde el adaptador: pasar los IDs a execute.

## Contrato del anfitrión

Vincular `Contracts\UserAccountWriter` a una implementación de `persist(Model $target, UserOperation $operation, array $attributes): void`. Recibe el modelo NUEVO o actualizado desde persistencia y debe validar su allowlist, guardar exclusivamente atributos propios de cuenta y, si corresponde, revocar sesiones/tokens en la misma conexión. Para Delete puede usar soft delete. Debe mantener la identidad del objetivo, no editar RBAC, no abrir/confirmar la transacción ni ejecutar SMTP, HTTP o push. El servicio verifica identidad persistida y conexión tras el adaptador.

Es código confiable del anfitrión, no un callback elegido por el cliente. PHP arbitrario, listeners, SQL, Spatie directo o modificaciones de configuración externas no están interceptados por el paquete. Las tablas adicionales deben declararse en `transaction_tables`. La inspección rechaza tablas ausentes o no InnoDB antes de escribir en MariaDB/MySQL.

`UserAuthorityChanged` acepta actorId, targetId y operation, además de audit, correlationId y accountChanged opcionales, sin atributos ni contraseñas. Sigue notificando invocaciones aceptadas; AuthorityAudit representa únicamente cambios efectivos. El anfitrión debe separar efectos de cuenta y asignaciones, evitando registrar dos veces la misma modificación RBAC. Los efectos externos deben esperar al commit.

## Auditoría obligatoria configurable

Vincular `Contracts\TransactionalAuditStore` e instalar sus tablas antes de activar `audit.required=true`. El adaptador declara su conexión y todas las tablas que escribe; el paquete exige la misma instancia de conexión RBAC y las incluye en la inspección InnoDB. `record(AuthorityAudit, Connection)` se ejecuta síncronamente dentro de la transacción. Si falla, se propaga la excepción y se revierten los cambios. Sin adaptador y con required=false sólo se ofrecen eventos, sin promesa de persistencia automática.

AuthorityAudit se construye con allowlists, estados frescos y diferencias normalizadas; no contiene modelos ni secretos. El UUID de correlación pertenece a la operación transaccional y funciona igual desde HTTP, CLI o servicios internos. La creación de cuenta con asignaciones produce una sola diferencia compuesta; las modificaciones estructurales no generan eventos por miembro. Consultar [auditing.es.md](auditing.es.md) para formato, operaciones, ejemplo independiente, compatibilidad, límites de atomicidad y listeners posteriores al commit.

## Administrador elegible

El contrato opcional `Contracts\AdministrativeIdentity` permite al anfitrión imponer invariantes de identidad adicionales. El proveedor usa bindIf con `UnrestrictedAdministrativeIdentity`, que proporciona comportamiento genérico. Vincular una implementación propia antes de resolver servicios. No necesita correo, campos ni tablas de negocio en el paquete.

- `assertActor` valida coherencia persistida del operador; `allowsGlobalAdministration` condiciona el privilegio global en isAdmin, snapshot, isPolicyManager y RoleHierarchy.
- `assertAccountControl` interviene sobre objetivos y miembros afectados indirectamente por roles y políticas.
- `assertAccountMutation` valida operaciones de ciclo de vida y atributos antes de invocar UserAccountWriter.
- `assertRoles` valida el conjunto final para reemplazos, incrementos, creación/edición compuesta y candidatos contextuales.
- `assertRoleMutation` protege identidad/nivel del rol al crear o actualizar.

Los hooks de escritura se ejecutan bajo el mismo bloqueo después de recargar modelos. Las consultas de candidatos son informativas y no adquieren una autorización duradera. Los hooks deben consultar estado persistido, lanzar AuthorizationException/ValidationException cuando corresponda y no mutar datos ni llamar recursivamente al motor. Las invariantes se suman a P/Q/D, no los sustituyen. Un resultado false de allowsGlobalAdministration no añade automáticamente a un usuario a gestores si su único rol es el admin configurado.

Por ejemplo, un anfitrión puede reservar una identidad persistente y excluir cuentas pendientes mediante ActorEligibility. Su correo, aprovisionamiento y recuperación permanecen en el código anfitrión. El adaptador no habilita ningún contexto de bypass para HTTP.

`EligibleAdministrator` restringe primero la consulta a poseedores del rol admin configurado y evalúa `ActorEligibility` en bloques de 100. No aproxima elegibilidad con active en SQL: conserva contratos personalizados. El costo es proporcional a los candidatos administrativos examinados y a las consultas del contrato, sin cargar toda la tabla users.

Se comprueba después de persistir, todavía bajo el lock, para detectar cambios en cualquier atributo que afecte la elegibilidad. La pérdida del último administrador antes elegible produce ValidationException y rollback de atributos, membresías, auditoría y revocaciones. No bloquea arbitrariamente una instalación aún sin administrador. Bootstrap/recuperación son procedimientos privilegiados del anfitrión; el anfitrión debe implementar su procedimiento auditado sin ofrecer bypass HTTP.

## Servicios PHP

| Servicio | Entradas |
|---|---|
| RoleManagementService | create, update, syncPermissions |
| DelegationPolicyService | create, update, syncPermissions, assignToRole |
| UserPermissionManagementService | assignRole, removeRole, replaceRoles, grantDirectPermission, revokeDirectPermission, replaceDirectPermissions |
| AuthorizedUserMutation | execute |
| AuthorityQueryService | roles, manageableRoles, permissions, userAuthority, assignableRoles, delegablePermissions, policies, policy, manageablePolicies, assignablePolicyRoles, capabilities |
| DelegationPolicyProvisioningService | run(dryRun=false), sólo infraestructura |
| InfrastructureInspector | inspect, assertReady |

Los incrementos leen el conjunto actual dentro del lock, incluyendo al eliminar un ID no asignado. No admiten IDs inexistentes o de otro guard. No se convierten permisos heredados en permisos directos.

## Entrada funcional y descubrimiento

El anfitrión configura `abilities` con nombres de permisos de SU catálogo. Claves: roles.view/create/update/permissions, permissions.view, policies.view, users.view/create/update/suspend/reactivate/delete/roles/permissions y accounts.control. Arrays vacíos no conceden entrada a operadores ordinarios. El admin exacto conserva su entrada global explícita, sin instalar Gate::before.

Para gestionar políticas sigue siendo obligatoria la identidad de admin o rol configurado en policy_manager_roles. `policy_management_ability` permite añadir una entrada funcional extra sin convertir un permiso funcional en identidad de gestor; null no añade un requisito adicional. Por ejemplo, policies.manage puede mapearse al permiso anfitrión delegation-policies.manage. Ser gestor no concede D global: las transferencias siguen limitadas por Q activo.

Las consultas roles y policies necesitan sus permisos de catálogo; manageableRoles y manageablePolicies verifican la entrada de gestión. Los candidatos de usuario comprueban entrada de asignación, footprint y jerarquía sobre ese objetivo; `new` exige además users.create. assignableRoles proyecta AGREGAR un rol conservando los actuales. Las capacidades son proyecciones informativas; no son autorizaciones duraderas ni garantías para cualquier payload. La escritura siempre relee y valida. No hay endpoint para crear, renombrar o eliminar permisos.

Las listas generales devuelven colecciones completas. Las capacidades hacen consultas por recurso/consumidor para reutilizar las reglas exactas; no se promete una optimización por lotes ni un benchmark. En catálogos grandes el anfitrión puede paginar su presentación sin sustituir las comprobaciones de escritura.

## HTTP y CLI

Ver [API del paquete](api.es.md). HTTP permanece desactivado por defecto en el paquete. El anfitrión puede habilitarlo con su middleware de API, autenticación y elegibilidad. Autenticación y reglas de dominio también se comprueban aunque el anfitrión omita por error su middleware de autenticación: RequireActor rechaza un usuario ausente.

`permission-manager:check` verifica esquema, lock y todas las tablas Spatie/anfitrionas participantes. No repara motores.

`permission-manager:provision --dry-run` muestra el plan sin escrituras. `permission-manager:provision` recomputa el plan bajo el lock y sólo lo aplica si no hay conflictos. `provisioning` es una lista explícita de nombres de roles existentes; cada uno recibe una política vacía `role-{nombre}`. No crea usuarios, roles, permisos ni contraseñas; nunca copia P a Q. Preserva nombre/contenido/active administrados de una política ya vinculada. No enlaza una política preexistente poblada a un rol desvinculado, pues concedería Q implícitamente; exige la operación autorizada de asociación. Admin se excluye del plan. Guard/slug ambiguos, asociaciones incompatibles o consumidores ajenos producen conflicto sin cambios.
