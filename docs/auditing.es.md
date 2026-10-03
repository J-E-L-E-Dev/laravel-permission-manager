# Auditoría de autoridad

[English version](auditing.md)

El paquete emite `Events\AuthorityAudit`, un evento inmutable común con un nombre de operación explícito. El anfitrión decide dónde conservarlo mediante `Contracts\TransactionalAuditStore`. No se instala una tabla de auditoría en el paquete ni se depende de modelos o tablas de una aplicación concreta.

## Operaciones cubiertas

| Servicio / método | operation | Estado auditado |
|---|---|---|
| RoleManagementService::create | role.created | name, guard_name, level, description; sin relaciones adicionales |
| RoleManagementService::update | role.updated | Sólo campos efectivamente distintos |
| RoleManagementService::syncPermissions | role.permissions.changed | Conjunto P anterior/final, agregados y retirados |
| DelegationPolicyService::create | delegation_policy.created | name, slug, active y Q inicial, incluso vacío |
| DelegationPolicyService::update | delegation_policy.updated | Cambios de name y active |
| DelegationPolicyService::syncPermissions | delegation_policy.permissions.changed | Q almacenado anterior/final, agregados y retirados |
| DelegationPolicyService::assignToRole | role.delegation_policy.changed | delegation_policy_id anterior/nuevo; null identifica asociación/desasociación |
| UserPermissionManagementService | user.roles.replace/add/remove, user.permissions.replace/add/remove | Roles o permisos directos realmente cambiados |
| AuthorizedUserMutation::execute | user.create/update/suspend/reactivate/delete | Una diferencia conjunta de roles y direct_permissions, si los modifica |
| DelegationPolicyProvisioningService::run | delegation_policy.created y role.delegation_policy.changed | Sólo creación/enlace efectivos, actor de infraestructura y correlación común |

El aprovisionamiento estructural es una operación privilegiada de infraestructura, no una ruta autorizada de usuario. Su actorId es null y actorType es infrastructure: no se inventa la identidad de un operador humano. Dry-run, conflictos y filas unchanged no generan auditorías de éxito.

Los conjuntos se leen de persistencia después del bloqueo y antes/después de la escritura; nunca desde relaciones precargadas. Los IDs de roles/permisos se normalizan, deduplican y ordenan numéricamente. Se conserva la autorización existente sobre guard, P/Q/D, jerarquía, contenido anterior/final, consumidores, miembros e identidades protegidas. Una misma política para un rol, conjuntos equivalentes o atributos iguales no generan AuthorityAudit. Una modificación estructural genera un evento por recurso/cambio, no uno por cada miembro.

## Contrato del evento

Propiedades públicas readonly, sin modelos Eloquent:

| Propiedad | Contenido |
|---|---|
| eventId | UUID distinto para ese evento |
| actorId | int\|string\|null; null sólo para infraestructura |
| actorType | user o infrastructure |
| resourceType | role, delegation_policy o user |
| resourceId | int\|string histórico del recurso |
| operation | Nombre de operación de la tabla anterior |
| before / after | Mapas de campos relevantes que efectivamente cambian; before vacío para una creación estructural |
| changes | Por campo: before, after; los conjuntos incluyen added y removed |
| occurredAt | Fecha UTC ISO 8601 con microsegundos |
| guard | Guard RBAC configurado |
| correlationId | UUID de la operación transaccional, independiente de HTTP/sesiones |

Ejemplo de changes de sincronización de P: `{"permissions":{"before":[1,2],"after":[1,3],"added":[3],"removed":[2]}}`. Un cambio de nivel y descripción aparece en un único evento con dos campos. Q siempre usa el recurso delegation_policy; no se confunde con P del rol ni con permisos efectivos de usuarios.

AuditState construye allowlists explícitas. Los eventos de usuarios contienen únicamente IDs de roles/directos; no guardan nombre, correo, atributos del usuario, contraseñas, hashes, tokens, cookies, configuración ni payloads HTTP. Comparar internamente los atributos de una cuenta para detectar si cambió no los incorpora al evento. No se amplía el soporte de claves de Spatie: los IDs de roles/permisos siguen siendo enteros; el contrato conserva IDs históricos de usuario enteros o cadenas si el esquema del anfitrión los soporta.

## Persistencia y frontera transaccional

La configuración predeterminada es `audit.required=false`, sin adaptador: se emiten eventos pero **no se promete historial persistente automático**. Al vincular TransactionalAuditStore, el paquete llama siempre al adaptador síncrono antes de emitir AuthorityAudit, incluso si los observadores Laravel están simulados o desactivados. `audit.required=true` exige que la vinculación exista; en caso contrario rechaza la escritura.

El contrato declara `connection(): Illuminate\Database\Connection`, `tables(): array` y `record(AuthorityAudit $event, Connection $connection): void`. Se exige la misma instancia de conexión que RBAC, una transacción activa y tablas declaradas. InfrastructureInspector incluye esas tablas y comprueba existencia e InnoDB en MariaDB/MySQL antes de escribir. El método record recibe la conexión activa que debe usar para insertar. Las excepciones se propagan; no hay fallback silencioso a cola o a un log externo.

La secuencia es: bloqueo común → autorización/estado anterior fresco → modificación → diferencia efectiva → inserción obligatoria → evento Laravel síncrono → resto de listeners → commit. Una excepción de persistencia o de un listener posterior revierte tanto la modificación como sus registros en la misma transacción. Un evento observado durante esa transacción **no anuncia que el commit ya ocurrió**.

El adaptador es código confiable: no puede confirmar/revertir transacciones, usar otra conexión o producir efectos externos. El paquete verifica conexión declarada y comprueba que el nivel transaccional/PDO no cambie al retornar. Estas comprobaciones no pueden deshacer un commit que un adaptador incorrecto haya realizado, ni demostrar que un adaptador arbitrario insertó realmente sus datos. No atribuir atomicidad a una implementación que incumpla el contrato. Los adaptadores independientes de prueba insertan por la conexión recibida y verifican rollback real.

## Ejemplo en una aplicación Laravel independiente

Crear una migración del anfitrión, antes de habilitar la auditoría obligatoria:

```php
Schema::create('authority_history', function (Blueprint $table) {
    $table->engine = 'InnoDB';
    $table->id();
    $table->uuid('event_id')->unique();
    $table->json('payload');
});
```

Implementar su adaptador síncrono:

```php
use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;

final class HostAuditStore implements TransactionalAuditStore
{
    public function connection(): Connection { return DB::connection(); }
    public function tables(): array { return ['authority_history']; }
    public function record(AuthorityAudit $event, Connection $connection): void
    {
        $connection->table('authority_history')->insert([
            'event_id' => $event->eventId,
            'payload' => json_encode($event, JSON_THROW_ON_ERROR),
        ]);
    }
}
```

En register del ServiceProvider: `$this->app->bind(TransactionalAuditStore::class, HostAuditStore::class);`. En permission-manager.php: `'audit' => ['required' => true]`. Ejecutar `php artisan permission-manager:check`. El payload serializado aquí es el evento con allowlist, no un modelo Eloquent.

Los listeners adicionales pueden observar el evento, pero no deben insertar otra copia del historial obligatorio. Para observabilidad externa, registrar un listener que espere al commit, por ejemplo:

```php
Event::listen(AuthorityAudit::class, function (AuthorityAudit $event) {
    DB::afterCommit(function () use ($event) {
        // Despachar aquí una tarea de observabilidad o notificación.
        // No sustituye el historial obligatorio ya persistido.
    });
});
```

Una cola posterior al commit no garantiza entrega externa exactamente una vez ni supervivencia ante la caída del proceso entre commit y envío. Si se necesita esa garantía adicional, el anfitrión debe implementar un outbox transaccional y consumidores idempotentes; no está incluido en el paquete.

## Compatibilidad y operaciones compuestas

`new UserAuthorityChanged(actorId, targetId, operation)` sigue siendo válido. Se mantienen sus propiedades originales y se añaden parámetros opcionales audit, correlationId y accountChanged. El evento anterior sigue notificando las invocaciones aceptadas, incluso cuando una sincronización no cambia nada; **AuthorityAudit** representa diferencias efectivas. No convertir cada evento anterior en un segundo registro RBAC.

Una creación con roles/directos iniciales produce un único user.create, no asignaciones independientes. Una actualización compuesta produce un único user.update con los conjuntos cambiados. Si no cambian asignaciones, no produce AuthorityAudit; el anfitrión conserva la operación de cuenta separadamente. accountChanged indica si el adaptador realmente modificó atributos persistidos (excluyendo timestamps automáticos); no expone sus valores. correlationId enlaza ese evento con el registro RBAC. Para emisiones manuales antiguas, los campos nuevos son null y el anfitrión puede conservar su conducta histórica.

El anfitrión puede separar historial de cuenta, autoridad e infraestructura. Una creación compuesta puede tener un efecto de cuenta y otro de asignaciones con la misma correlación; no deben ser dos copias de la misma asignación. La recuperación administrativa conserva su auditoría propia.

## Consulta, conservación y límites

Conservar identificadores históricos sin FK con borrado en cascada. Indexar recurso, actor y correlación; ordenar por fecha e ID local. El anfitrión debe autorizar las consultas futuras, controlar retención y copias de seguridad. El paquete no ofrece endpoints de modificación, borrado o consulta pública de auditorías. Los registros permiten reconstruir cambios concretos, no snapshots de todos los permisos efectivos a cada instante.

No se auditan automáticamente SQL, Tinker, primitivas Spatie/Eloquent externas, cambios de catálogo fuera de los servicios, migraciones ni escrituras no cooperantes con el bloqueo. No se añade auditoría de rechazos genérica: una excepción de autorización no produce un evento de éxito ni se sustituye por un fallo de registro de intentos. Las auditorías de infraestructura existentes son un mecanismo distinto.

Las pruebas cubren datos frescos, no-op, privacidad, compatibilidad, conexión incorrecta/ausente, fallo obligatorio, rollback posterior al evento, correlación y efectos tras commit; MariaDB comprueba carreras reales. Consultar los resultados ejecutados en [estado de verificación](status.es.md).
