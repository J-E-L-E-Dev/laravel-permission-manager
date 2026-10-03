<?php

namespace Ahinest\LaravelPermissionManager\Support;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Support\Str;
use LogicException;

/**
 * Contexto interno de conexión y correlación; no contiene operador, credenciales ni bypass.
 */
class AuditContext
{
    private ?Connection $connection = null;

    private ?string $correlationId = null;

    /**
     * Asocia conexión y un UUID de correlación a la operación y restaura el contexto anterior al salir.
     *
     * No abre ni confirma transacciones; el llamador debe mantener la frontera RBAC activa.
     */
    public function within(Connection $connection, Closure $operation): mixed
    {
        $previous = [$this->connection, $this->correlationId];
        $this->connection = $connection;
        $this->correlationId = (string) Str::uuid();
        try {
            return $operation();
        } finally {
            [$this->connection, $this->correlationId] = $previous;
        }
    }

    /**
     * Devuelve el UUID de la operación actual, o null fuera de un contexto coordinado.
     */
    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    /**
     * Obtiene la conexión del contexto y exige una transacción activa.
     *
     * @throws LogicException Si no hay contexto o la transacción ya terminó.
     */
    public function connection(): Connection
    {
        if (! $this->connection || $this->connection->transactionLevel() < 1) {
            throw new LogicException('Authority audit requires an active RBAC transaction.');
        }

        return $this->connection;
    }
}
