<?php

namespace Ahinest\LaravelPermissionManager\Events;

/**
 * Notificación de una invocación aceptada sobre autoridad o ciclo de cuenta.
 * Se emite dentro de la transacción; los efectos externos deben esperar al commit.
 */
final readonly class UserAuthorityChanged
{
    /**
     * Describe una invocación de autoridad de usuario aceptada dentro de la transacción.
     *
     * actorId y targetId identifican las cuentas; operation conserva el nombre de operación.
     * audit puede ser null si no cambió la autoridad; correlationId vincula la transacción.
     * accountChanged informa cambios de atributos sin revelar valores y puede ser null
     * en emisiones de tres argumentos. Los efectos externos deben esperar al commit.
     */
    public function __construct(
        public int|string $actorId,
        public int|string $targetId,
        public string $operation,
        public ?AuthorityAudit $audit = null,
        public ?string $correlationId = null,
        public ?bool $accountChanged = null,
    ) {}
}
