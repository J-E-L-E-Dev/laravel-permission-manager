<?php

namespace Ahinest\LaravelPermissionManager\Contracts;

use Ahinest\LaravelPermissionManager\Events\AuthorityAudit;
use Illuminate\Database\Connection;

/**
 * Adaptador confiable del anfitrión para persistencia síncrona de auditoría RBAC.
 * Debe insertar en la conexión recibida, declarar todas sus tablas y propagar fallos.
 * No controla transacciones, no modifica autoridad ni ejecuta efectos externos.
 */
interface TransactionalAuditStore
{
    /**
     * Devuelve la misma instancia de conexión que usan las escrituras RBAC.
     *
     * El paquete compara identidad de objeto, no solo el nombre de conexión. No debe
     * abrir una transacción ni cambiar la conexión activa para satisfacer esta consulta.
     */
    public function connection(): Connection;

    /**
     * Declara todas las tablas que record puede escribir para inspeccionar su atomicidad.
     *
     * La lista debe ser no vacía; sus tablas deben existir y usar InnoDB en MariaDB/MySQL.
     *
     * @return list<string> Nombres de tablas participantes en la conexión RBAC.
     */
    public function tables(): array;

    /**
     * Inserta el historial de un cambio efectivo usando exclusivamente la conexión recibida.
     *
     * Se ejecuta de forma síncrona dentro de la transacción RBAC y antes del evento Laravel.
     * Debe propagar errores; no puede confirmar/revertir, cambiar PDO, usar otra conexión,
     * editar autoridad ni ejecutar efectos externos. No duplicar este historial en listeners.
     *
     * @param  AuthorityAudit  $event  Diferencias permitidas del recurso, sin secretos de cuenta.
     * @param  Connection  $connection  Instancia RBAC con una transacción activa.
     */
    public function record(AuthorityAudit $event, Connection $connection): void;
}
