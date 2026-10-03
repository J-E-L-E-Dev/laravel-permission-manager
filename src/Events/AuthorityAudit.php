<?php

namespace Ahinest\LaravelPermissionManager\Events;

/**
 * Evento inmutable de un cambio efectivo de autoridad dentro de la transacción.
 * Su emisión no anuncia un commit confirmado; el payload excluye secretos de cuenta.
 */
final readonly class AuthorityAudit
{
    /**
     * Construye un evento inmutable de diferencias efectivas, todavía anterior al commit.
     *
     * eventId identifica el evento y correlationId la operación transaccional; actorId null
     * indica infraestructura. resourceType/resourceId identifican el recurso y operation
     * su modificación. before/after contienen solo campos cambiados; changes incluye
     * diferencias y altas/bajas de conjuntos. occurredAt usa UTC ISO 8601 con microsegundos
     * y guard identifica el ámbito RBAC. No incluir modelos, atributos de cuenta ni secretos.
     */
    public function __construct(
        public string $eventId,
        public int|string|null $actorId,
        public string $actorType,
        public string $resourceType,
        public int|string $resourceId,
        public string $operation,
        public array $before,
        public array $after,
        public array $changes,
        public string $occurredAt,
        public string $guard,
        public string $correlationId,
    ) {}
}
