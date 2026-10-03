# Auditoría y decisiones de extracción — 2026-09-20

Esta primera sección conserva el registro histórico de la fase 1. La revisión y los cambios de fase 2 aparecen más abajo; el estado vigente está en status.es.md.

La fase 2.2 añade auditoría de modificaciones de autoridad: [contrato vigente](auditing.es.md), [versión inglesa](auditing.md) e [informe de integración](../../../../domi-ya/docs/phase2.2-report.es.md). Se releyeron los flujos RBAC de Asisnet, que registran errores, y AssignTicketAction/TicketEvent, que persisten eventos dentro de su transacción. Se reutilizó ese principio, sin copiar tablas comerciales. Los 81 hashes del inventario original siguen coincidiendo. Este documento es trazabilidad de extracción; AuthorityAudit y TransactionalAuditStore son los contratos de auditoría operativa nuevos.

## Fuentes y estado inicial

Referencia disponible: proyecto Asisnet extraído en el workspace vecino. Se usa como fuente de lectura, sin ejecutar sus migraciones ni modificarlo. Los informes H02 etapas 3–8 son históricos: D01 elimina posteriormente el CRUD latente del catálogo; F-A1 documenta una invariante single-guard, no soporte multiguard. Sus resultados históricos no son resultados de este paquete.

Versiones instaladas de referencia: Laravel 12.67.0 y Spatie 6.25.0. El código final de ResetPasswordAction ya revoca tokens personales dentro de su transacción; la observación histórica F-A3 sobre tokens conservados no describe ese estado final. El inventario `source-inventory.json` registra 81 fuentes con SHA-256, métodos públicos y nombres de casos cuando corresponde, para futuras comparaciones sin reconstruir el inventario.

DomiYa es el esqueleto Laravel: framework instalado 12.69.2, Spatie 6.25.0, PHP CLI 8.2.28, Composer 2.8.11. No hay repositorio Git en la carpeta de DomiYa. Publicación de Spatie: config/permission.php y database/migrations/2026_09_20_175226_create_permission_tables.php. User no tenía HasRoles. migrate:status falla con Unknown database domi_ya (MariaDB local responde); no hay tablas ni datos verificables en esa base inexistente. No se ejecutará db:seed: el seeder del esqueleto crea una cuenta de ejemplo.

## Matriz de extracción y dependencias

| Fuente Asisnet | Responsabilidad / dependencias | Destino y decisión |
|---|---|---|
| RoleHierarchy | niveles, excepciones por nombres fijos, fallback cliente | Services/RoleHierarchy; niveles configurables, sin fallback comercial, igual nivel denegado por defecto |
| PermissionDelegationService | P/Q/D, C∪F, consumidores, cuentas, identidades; User/Role/ApiApplication/DB | mismo servicio; conservar comprobaciones, resolver modelos configurados, SensitiveRoleResolver sustituye ApiApplication |
| Data/DelegationAuthoritySnapshot | valor readonly previo, sin relaciones mutables | Authorization/DelegationAuthoritySnapshot |
| RbacWriteTransaction | fila global, reserva SQLite, rechazo snapshot ambiental MariaDB | mismo servicio; una conexión, fila lpm_write_locks |
| RbacMutationAuthorization | entrada funcional, validación, jerarquía, composición del motor; acopla Requests de usuarios | Authorization/RbacMutationAuthorization; separar de campos comerciales y de HTTP |
| RoleService / SyncRolePermissionsAction | creación, metadatos, niveles, reemplazo P y consultas contextuales | Services/RoleManagementService; API PHP autorizada; consultas contextuales completas en fase posterior |
| DelegationPolicyService / Requests | metadatos, contenido, activación, asociación bajo lock | Services/DelegationPolicyService; validación de dominio sin FormRequest |
| DelegationPolicyProvisioningService / comando | plan completo, conflictos, dry-run, idempotencia; depende de seeder ROLE_LEVELS | fase posterior; configuración explícita sustituirá seeder, nunca P→Q |
| UserService / SaveUserAction / DeleteUserAction | mezcla autoridad con contraseña, correo, empresas, tokens, contacto y notificaciones | Services/UserPermissionManagementService sólo autoridad de usuarios existentes; excluir ciclo de vida |
| UpdateOwnProfileAction / AuthController | perfil, contraseña actual, fotos, reset, tokens | anfitrión; no copiar; validar allowlist de sus propios campos |
| User | HasRoles y relaciones comerciales | modelo anfitrión con HasRoles; no modelo de usuario productivo del paquete |
| Role / DelegationPolicy | extensión Spatie, relación nullable, política inactive por defecto | Models/Role + Concerns/HasDelegationPolicy; Models/DelegationPolicy |
| User/Role/Permission/DelegationPolicy Controllers | HTTP, permisos de entrada, serialización | pendiente; no rutas en esta entrega |
| EnsurePolicyManager / EnsureRoleListAccess | gestor exacto y catálogo separado de candidatos | conservar semántica en futura capa HTTP |
| EnsureRoleOrPermission | hasRole sin guard: lectura legacy ajena puede pasar | no copiar; futura entrada utilizará motor con guard exacto |
| DelegationPolicyResource | sólo IDs/nombres, niveles, capacidades informativas | pendiente con HTTP; nunca autorizar por flags del cliente |
| migración Spatie | cinco tablas originales y pivotes configurables | no copiar; migración de Spatie publicada por anfitrión |
| migraciones description/level/policy/lock | extensiones al esquema | migración propia; tablas Spatie configuradas; level nullable sin backfill de autoridad |
| PermissionsSeeder | catálogo versionado y grants admin particulares | excluir; catálogo y permisos técnicos pertenecen al anfitrión |
| RoleHierarchyPermissionsSeeder / RolesAndUsersSeeder | roles comerciales, usuarios bootstrap y credenciales fijas | excluir íntegramente |

## Invariantes y pruebas originales

PermissionDelegationServiceTest respalda P independiente de Q, D unión activa, admin exacto, manager sin D implícito, C∪F incluidos retirados, roles actuales/finales, guard exacto, snapshot previo, consultas frescas, identidad sensible y footprint directo∪P∪Q. Es la primera suite candidata a adaptación completa con fixtures neutrales; las ACL dinámicas pasan a un resolver de prueba.

RbacDelegationEnforcementTest respalda entrada funcional dentro del lock, rollback, no control de cuenta tras retirar autoridad, todos los miembros de roles compartidos. Sus casos de registro/correo/tokens/empresas son específicos; requieren sustitución por casos de autoridad sobre usuario existente, no copiar UserService.

DelegationPolicyHttpTest y RoleDelegationCandidatesTest respaldan consumidores y miembros múltiples, autoridad dormida, vínculo propio incluso no-op prohibido, metadata separada de autoridad, scopes revocados y separación catálogo/candidatos. Sus expectativas HTTP y optimización por lotes quedan pendientes hasta implementar HTTP.

DelegationPolicyInfrastructureTest, DelegationPolicyMigrationTest, DelegationConfigurationTest verifican defaults, FK, preservación de datos, configuración cacheable; requieren nuevo esquema y Testbench. DelegationPolicyProvisioningTest/DelegationSeederBoundaryTest se adaptarán cuando exista CLI; sus seeders comerciales se excluyen. RolePermissionsSeederTest es específico del catálogo anfitrión; la garantía reutilizable es ausencia de grants implícitos.

RoleHierarchyAuthorizationTest contiene excepciones comerciales (nivel 2 mismo rol; soporte sólo sí mismo; cliente nunca administra). No se trasladan nombres ni cuatro niveles obligatorios: rango configurable, manejo inferior estricto y opción de mismo rol/igual nivel con prohibición de promoción propia y controles de impacto intactos.

PermissionDelegationHttpIsolationTest ya no prueba ausencia de enforcement: el código final prueba siete operaciones legítimas con scope explícito. PermissionCatalogPolicyTest y Fa1SingleGuardInvariantTest requieren conservar catálogo sin writers y negar identidades ajenas. H02FinalAdversarialSequencesTest exige secuencias proxy, política vacía→contenido→activación→vínculo, retiros y takeover, autoridad dormida, identidades futuras, miembros nuevos y candidatos stale. H02FinalAdjacentBoundariesTest caracteriza lectura legacy ajena y reset de tokens; no copiar el defecto como aceptación del paquete.

RbacMariaDbConcurrencyTest y workers usan procesos separados y barreras para revocación, rollback, consumidores nuevos, retiro de asociación y transacción ambiental. Deben reimplementarse con base descartable del paquete. SQLite no acredita concurrencia MariaDB.

## Arquitectura y decisiones

PHP >=8.2, Laravel 12, Spatie 6.25; Testbench 10 + Pest 3. Un guard RBAC explícito por instalación; sin teams/wildcards/Passport y sin promesa multiguard. Roles/permisos con PK enteras estándar. Modelo de usuario configurable, sin nombres App ni campos comerciales. Un solo connection de DB para grafo y lock; modelos de otra conexión se rechazan. ActorEligibility permite adaptar suspensión del usuario sin imponer active; por defecto un atributo active presente y falso deniega.

Identidades sensibles: administrador exacto + gestores + reservadas + resolver extensible. Nombres nuevos normalizados de forma restrictiva para impedir alias de identidades reservadas por mayúsculas/espacios; validación SQL unique complementa comparación exacta. No Gate::before global. Catálogo no tiene API writer. Migración registra qué columnas añadió para no borrar columnas preexistentes al revertir.

Escrituras: transacción propia → lock global → actor/objetivo frescos → entrada funcional configurada → jerarquía → P/Q/D y consumidores → persistencia. Primitivas del motor son comprobaciones complementarias: no constituyen solas autorización de un endpoint. API pública de escritura compone todas las reglas. Configurar permisos técnicos no los crea; arrays vacíos sólo permiten admin.

El paquete no concede control de credenciales por poder asignar roles. La integración de ciclo de vida debe comprobar permiso funcional específico y footprint/jerarquía antes de alterar atributos, dentro de la misma frontera. No controla llamadas arbitrarias Spatie/SQL/Tinker/seeders.

Decisiones adicionales de esta extracción: la relación de permisos de política muestra su contenido bruto para no ocultar filas malformadas; las lecturas P/Q/D filtran guard y las comprobaciones de transferencia validan IDs/guard. Esto difiere deliberadamente del filtro web en la relación original. La API de usuarios comprueba el footprint completo antes de cualquier reemplazo, una frontera más conservadora que editar sólo permisos directos de una cuenta con otra autoridad fuera de alcance. La proyección HTTP por lotes `policyCapabilities` no se trasladó: es una optimización de lectura pendiente, no una validación de escritura retirada.

## Riesgos y alcance explícito

Último administrador y recuperación no están implementados en la fuente: requisitos nuevos. Primera entrega protegerá retiro del último admin en su API de roles de usuario y bloqueará renombrado del rol admin; desactivación/borrado de cuentas y recuperación seguirán siendo responsabilidad del anfitrión pendiente de integrar. No afirmar protección global ante SQL o Spatie directo.

No publicar ni desplegar. HTTP y CLI de aprovisionamiento son etapas pendientes; no crear archivos vacíos para simularlas. Las pruebas y el informe final deben enumerar lo ejecutado y las limitaciones, incluyendo cualquier escenario de compatibilidad o concurrencia aún sin verificar.

# Revisión y ampliación de fase 2 — 2026-09-21

## Estado real y conservación

Se volvieron a inspeccionar las carpetas extraídas y los ZIP disponibles. domi-ya.zip coincidía en 66 archivos; sólo difería storage/logs/laravel.log. laravel-permission-manager.zip coincidía en 57 archivos; sólo difería .phpunit.cache/test-results. No se extrajeron ZIP sobre el código. Asisnet estaba extraído; no se encontró su ZIP en los directorios de trabajo inspeccionados. Sus 81 hashes del inventario anterior coinciden antes y después de esta fase. phase2-source-inventory.json registra además las fuentes consultadas específicamente para cuentas, autenticación, candidatos y aprovisionamiento.

La revisión directa encontró una diferencia importante frente al informe inicial: domi_ya ya existía y tenía las cinco migraciones iniciales aplicadas. Antes de ampliar el esquema se comprobaron 0 usuarios, 0 roles y 0 permisos. Se conservaron las migraciones originales sin republicar Spatie/LPM.

Se ejecutó la línea base antes de cambiar código: paquete 153 pruebas / 432 aserciones; DomiYa 3 / 5; MariaDB 8 / 44. No se presentaron los resultados históricos de Asisnet como ejecuciones nuevas. Composer instaló exclusivamente Sanctum 4.3.3 como nueva dependencia de DomiYa, sin actualizar Laravel ni Spatie.

## Origen → destino de esta fase

| Referencia Asisnet | Adaptación / decisión |
|---|---|
| SaveUserAction y RbacMutationAuthorization::userSave | AuthorizedUserMutation + UserAuthorityRules + contrato UserAccountWriter; DomiYa AccountWriter valida atributos; preflight antes de guardar; una transacción sin anidar servicios |
| DeleteUserAction | UserOperation::Delete autorizado; DomiYa desactiva, revoca y hace soft delete en vez de copiar borrado físico |
| UserService / UserController / StoreUserRequest / UpdateUserRequest | UserAdministration, UserController y validación explícita del adaptador; sin empresas/contactos comerciales, sin restricción comercial a un único rol |
| RoleService::assignableForUser | AuthorityQueryService::assignableRoles; proyección de agregar a membresías actuales, entrada independiente del catálogo y revalidación al escribir |
| RoleService::assignableForDelegationPolicy | assignablePolicyRoles reutiliza las comprobaciones del motor sobre política, consumidores y miembros |
| DelegationPolicyService::list/show y policyCapabilities | Lecturas y capacidades reutilizan el motor; no se copió su optimización por lotes ni se afirma equivalencia de rendimiento |
| DelegationPolicyProvisioningService / comando | Paquete con configuración explícita, guard/modelos/tablas configurables; plan bajo lock, dry-run y conflicto antes de cambios; adicionalmente impide enlazar Q poblado implícitamente |
| CreateSessionTokenAction | DomiYa Authentication::login con Sanctum, token de 30 días y opción de cookie/CSRF; ahora lee credenciales/active bajo lock |
| RevokeCurrentTokenAction | Logout de token o sesión; nuevo logout-all y versión de sesión para revocación resistente a reescritura concurrente |
| ResetPasswordAction y AuthController | Broker Laravel, comprobación/reset serializados, revocación atómica; mensaje uniforme en forgot; sin copiar catch indiscriminado de autorización como 500 |
| UpdateOwnProfileAction / UpdateProfileRequest | Flujo de autoservicio separado; contraseña actual para cambiar correo o contraseña; rechazo explícito de claves de autoridad; sin fotos/empresas de Asisnet |
| EnsureRoleListAccess / EnsurePolicyManager | Entrada de catálogo separada de gestión/candidatos; validación exacta del guard; no copiar lectura legacy de admin ajeno |
| NewUserAccountNotification | Excluida: no enviar contraseñas ni marcar correos verificados por creación administrativa; nueva verificación firmada en cola después del commit |
| Seeders comerciales y ROLE_LEVELS fijos | Excluidos; DomiYa sólo declara admin/cliente y 14 permisos técnicos versionados |

Funcionalidades nuevas, no atribuidas a Asisnet: protección de último admin en todo el ciclo de cuenta; consulta acotada de elegibilidad; contrato genérico de persistencia; métodos incrementales; inspección completa de InnoDB; bootstrap/recuperación auditados; registro público limitado; borrado lógico; generación de sesión y contratos HTTP del paquete.

## Fronteras que permanecen

La implementación no elimina ni sustituye P/Q/D, footprints actuales, jerarquías, consumidores/miembros, guard exacto ni el rechazo de transacciones ambientales MariaDB. RbacMutationAuthorization y el motor original siguen siendo la autoridad; UserAuthorityRules extrae el preflight común de asignaciones y no persiste. No hay contexto público para anidar escrituras sin autorización. Las primitivas Spatie usadas directamente pertenecen a infraestructura (catálogo/bootstrap/registro limitado) o al tramo ya autorizado del paquete, no a controladores administrativos alternativos.

UserAccountWriter es una extensión confiable del anfitrión, no una barrera frente a PHP malicioso. Tokens, sesiones, auditoría y demás tablas declaradas comparten conexión y motor transaccional. Las notificaciones se envían después del commit. DomiYa activa el HTTP opcional tras pasar las pruebas del paquete; otros anfitriones conservan enabled=false.

## Trazabilidad de seguridad

| Invariante solicitada | Evidencia principal |
|---|---|
| SEC-01 autoescalada; SEC-02 P≠D; SEC-03 gestor≠admin | Motor original adaptado, AuthorizedWritesTest, AdministrationApiTest, AccountsApiTest |
| SEC-04 retirar autoridad antes de capturar credenciales | UserLifecycleTest y AccountsApiTest; preflight sobre objetivo actual |
| SEC-05/06 roles compartidos y nivel propio | AuthorizedWritesTest y motor original conservados |
| SEC-07 credenciales de cuenta privilegiada; SEC-08 creación dentro de D previo | UserLifecycleTest y AccountsApiTest |
| SEC-09/10 último admin suspendido/eliminado | UserLifecycleTest con elegibilidad personalizada; AccountsApiTest con rollback de tokens; carrera MariaDB remove-admin |
| SEC-11 tokens/sesiones suspendidos | AccountsApiTest, ActiveAccount y generación auth_version |
| SEC-12 guard ajeno | ConfiguredGuardTest, CustomSchemaTest, AdministrationApiTest y AccountsApiTest |
| SEC-13 autoridad concurrente y SEC-14 atomicidad | MariaDbConcurrencyTest con procesos separados; triggers/fallos después de save; suites de lifecycle |
| SEC-15 perfil propio; SEC-16 registro sin claves RBAC | AccountsApiTest y allowlists de DomiYa |
| SEC-17 revocación antes de nueva escritura | Candidatos stale y carreras create/suspend/increment/last-admin |
| SEC-18 lecturas autorizadas | Catálogos/candidatos/gestionables con entradas separadas; lectura de usuarios limitada por footprint |

El informe de resultados actuales, los incidentes de prueba y los límites están en status.es.md. Esta matriz describe cobertura concreta, no una certificación universal de seguridad ni de toda combinación de estados.

