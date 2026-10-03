<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Contracts\TransactionalAuditStore;
use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Ahinest\LaravelPermissionManager\Support\AuditContext;
use Ahinest\LaravelPermissionManager\Support\AuditState;
use Ahinest\LaravelPermissionManager\Support\Models;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;

/**
 * Construye diferencias permitidas y coordina almacén síncrono y eventos de auditoría.
 */
class AuthorityAuditTrail
{
    /**
     * Resuelve y valida el adaptador síncrono de auditoría para la conexión RBAC.
     *
     * Devuelve null solo cuando no existe vinculación y la persistencia es opcional.
     *
     * @throws LogicException Si la auditoría obligatoria carece de adaptador, tablas o conexión correcta.
     */
    public function store(Connection $connection): ?TransactionalAuditStore
    {
        if (! app()->bound(TransactionalAuditStore::class)) {
            if (config('permission-manager.audit.required', false)) {
                throw new LogicException('Mandatory authority auditing requires a TransactionalAuditStore binding.');
            }

            return null;
        }
        $store = app(TransactionalAuditStore::class);
        if ($store->connection() !== $connection || $store->tables() === []) {
            throw new LogicException('Audit persistence must declare its tables and use the same RBAC connection instance.');
        }

        return $store;
    }

    /**
     * Calcula diferencias efectivas, persiste el historial configurado y emite AuthorityAudit.
     *
     * Devuelve null si no hay cambios. Solo acepta estados construidos mediante listas
     * permitidas; exige un contexto transaccional activo. Una excepción del adaptador o
     * listener se propaga al escritor para rollback; el evento no certifica el commit.
     *
     * @throws LogicException Si falta contexto o el adaptador altera la conexión/transacción.
     */
    public function record(int|string|null $actorId, string $resourceType, int|string $resourceId, string $operation, array $before, array $after): ?AuthorityAudit
    {
        $changes = AuditState::differences($before, $after);
        if ($changes === []) {
            return null;
        }
        $context = app(AuditContext::class);
        $connection = $context->connection();
        // Las actualizaciones incluyen solo campos cambiados; la creación usa un estado previo vacío.
        $fields = array_keys($changes);
        $event = new AuthorityAudit(
            (string) Str::uuid(), $actorId, $actorId === null ? 'infrastructure' : 'user', $resourceType, $resourceId, $operation,
            array_intersect_key($before, array_flip($fields)), array_intersect_key($after, array_flip($fields)), $changes,
            now()->utc()->format('Y-m-d\TH:i:s.u\Z'), Models::guard(), $context->correlationId(),
        );
        $level = $connection->transactionLevel();
        $pdo = $connection->getPdo();
        $this->store($connection)?->record($event, $connection);
        if ($connection->transactionLevel() !== $level || $connection->getPdo() !== $pdo) {
            throw new LogicException('Audit adapters must not change transaction ownership or the PDO connection.');
        }
        event($event);

        return $event;
    }
}
