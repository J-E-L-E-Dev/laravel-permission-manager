<?php

namespace Ahinest\LaravelPermissionManager\Services;

use Ahinest\LaravelPermissionManager\Support\AuditContext;
use Closure;
use Illuminate\Support\Facades\DB;
use LogicException;
use Spatie\Permission\PermissionRegistrar;

/**
 * Frontera de serialización para escrituras RBAC cooperantes, auditoría y caché.
 * El callback sigue siendo responsable de autorizar su operación con datos persistidos.
 */
class RbacWriteTransaction
{
    /**
     * Ejecuta una operación confiable bajo la frontera transaccional y el lock RBAC común.
     *
     * Valida auditoría e infraestructura; rechaza transacciones externas MariaDB/MySQL.
     * Reserva el escritor SQLite antes de leer autoridad. El callback debe autorizar su
     * operación usando datos frescos: este método por sí solo no autoriza al usuario.
     * La caché Spatie se invalida después del commit o rollback; un fallo de caché posterior
     * al commit no revierte datos y requiere reconciliación antes de reintentar.
     *
     * @param  Closure  $operation  Operación de dominio que se ejecuta tras adquirir el bloqueo.
     * @return mixed Resultado del callback, sin transformar.
     *
     * @throws LogicException Si el protocolo, transacción, auditoría o coordinación son inválidos.
     */
    public function run(Closure $operation): mixed
    {
        app(AuthorityAuditTrail::class)->store(DB::connection());
        if (! in_array(DB::getDriverName(), ['mysql', 'mariadb', 'sqlite'], true)) {
            throw new LogicException('Only SQLite and MySQL/MariaDB transaction protocols are implemented.');
        }
        // MySQL REPEATABLE READ no debe reutilizar una instantánea creada por el llamador externo.
        // El llamador debe entrar sin una transacción externa activa.
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true) && DB::transactionLevel() !== 0) {
            throw new LogicException('RBAC writes must own their transaction and acquire the coordination lock before reads.');
        }

        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            app(InfrastructureInspector::class)->assertReady();
        }

        try {
            return DB::transaction(function () use ($operation) {
                if (DB::getDriverName() === 'sqlite') {
                    // SQLite no tiene SELECT FOR UPDATE: reserva el escritor antes de leer autoridad.
                    DB::table('lpm_write_locks')->where('id', 1)->update(['id' => 1]);
                }
                if (! DB::table('lpm_write_locks')->where('id', 1)->lockForUpdate()->first()) {
                    throw new LogicException('Missing RBAC coordination row.');
                }

                return app(AuditContext::class)->within(DB::connection(), $operation);
            });
        } finally {
            // Un observador síncrono puede cargar la caché Spatie con datos aún no confirmados.
            // Invalida únicamente después de que la base confirme o revierta la transacción.
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
}
