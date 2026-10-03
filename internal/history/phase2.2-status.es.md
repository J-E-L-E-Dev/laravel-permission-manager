# Estado vigente — fase 2.2, 2026-09-22

La auditoría de autoridad está implementada sobre los servicios existentes. Se conserva P/Q/D, jerarquía, bloqueo RBAC e identidad reservada. El paquete sigue en desarrollo local: sin publicación, tag ni versión estable.

## Resultado

- AuthorityAudit es un evento inmutable con actor/recurso, operación, diferencias antes/después, deltas de conjuntos, guard, fecha UTC y UUID de correlación transaccional.
- TransactionalAuditStore permite persistencia síncrona del anfitrión. audit.required=true rechaza vinculación ausente; se verifica misma instancia Connection, transacción activa y tablas declaradas para inspección InnoDB. Fallos de inserción o listeners posteriores revierten cambios e historial.
- Se auditan servicios de roles, políticas, asignaciones y operaciones compuestas, además de creaciones/enlaces efectivos del aprovisionamiento estructural. No se registran no-op, dry-run ni éxito de operaciones rechazadas.
- UserAuthorityChanged conserva su constructor original y añade datos opcionales. Los consumidores nuevos distinguen invocación aceptada de diferencia efectiva; DomiYa no duplica asignaciones en account_audits.
- DomiYa persiste rbac_audits mediante RbacAuditStore. AuditAccountMutation conserva efectos de cuenta correlacionados; infrastructure_audits mantiene las intervenciones reservadas. No se añadió ninguna ruta de auditoría.

## Línea base ejecutada antes de editar

| Suite | Resultado |
|---|---|
| Paquete | 181 passed / 544 assertions, 124.26 s |
| DomiYa | 60 passed / 274 assertions, 84.06 s |
| MariaDB previa del paquete | 15 passed / 81 assertions, 489.75 s |
| MariaDB identidad reservada | 5 passed / 49 assertions, 380.74 s |

La base domi_ya tenía ocho migraciones, dos roles, catorce permisos y cero cuentas/historiales. Se revisaron íntegramente los puntos de escritura/eventos y la integración anfitriona. Asisnet sólo aportó referencias de lectura: sus acciones RBAC registran errores y sus tickets persistían eventos transaccionales; no se copiaron tablas comerciales.

## Verificación final ejecutada

| Alcance | Resultado |
|---|---|
| php vendor/bin/pest --compact, paquete | **205 passed / 641 assertions**, 35.45 s |
| php artisan test --compact, DomiYa | **75 passed / 342 assertions**, 26.58 s |
| run-rbac-audit-mariadb.php | **5 passed / 44 assertions**, 280.84 s |
| run-permission-manager-mariadb.php | **15 passed / 81 assertions**, 357.17 s |
| run-reserved-admin-mariadb.php | **5 passed / 49 assertions**, 259.18 s |
| Composer validate --strict | Ambos proyectos válidos; aviso informativo de versión raíz del paquete sin Git |
| Sintaxis PHP | 125 archivos: 60 paquete y 65 aplicación, cero errores |
| Pint --test | Ambos proyectos aprobados |
| Migración aditiva | create_rbac_audits aplicada; nueve migraciones en estado Ran |
| permission-manager:check | RBAC infrastructure ready; rbac_audits es InnoDB |
| route:list --path=api --json | 42 rutas; sin rutas nuevas |
| Asisnet | 81 hashes SHA-256 comparados, cero diferencias |
| Persistencia local | 0 usuarios, 0 identidad reservada, 0 filas de las tres auditorías; 2 roles y 14 permisos |

Se conservaron todos los casos anteriores; se añadieron 24 al paquete, 15 a DomiYa y 5 pruebas MariaDB. No sumar las suites por subconjuntos. Las carreras nuevas usan procesos independientes y barreras dentro de operaciones reales: secuencia de cambios del mismo rol, uso de Q revocada, fallo obligatorio, rollback tras emitir y repetición concurrente sin auditoría duplicada. Las tres suites MariaDB usan bases aleatorias descartables; migrate:fresh sólo se ejecuta después de verificar su nombre y conexión, nunca en domi_ya.

La comparación de tipos/privacidad/no-op/compatibilidad también se verifica en SQLite. No se ejecutaron PHPStan/Psalm, benchmarks ni MySQL 8 independiente. Lint y Pint no sustituyen una revisión de tipos o de seguridad. Durante desarrollo se detectó y corrigió doble registro del listener de cuentas; la prueba original de suspensión conservó su aserción y event:list muestra una sola entrada.

## Contratos y documentación

[Auditoría en español](auditing.es.md), [auditoría en inglés](auditing.md), [integración](integration.es.md) e [inventario](file-inventory.md). La integración anfitriona está en [rbac-auditing.es.md](../../../../domi-ya/docs/rbac-auditing.es.md); el informe con los catorce puntos requeridos está en [phase2.2-report.es.md](../../../../domi-ya/docs/phase2.2-report.es.md). Los resultados previos quedan identificados como históricos en phase2.1-status-history.es.md y phase2-status-history.es.md.

La configuración predeterminada sin adaptador ofrece eventos, no persistencia automática. El adaptador es código confiable: debe usar la conexión recibida, no confirmar transacciones ni contactar sistemas externos. La comprobación posterior no puede deshacer un commit indebido de código arbitrario. Los efectos externos esperan al commit; un outbox y entrega durable externa no están incluidos.

No se interceptan SQL/Tinker, primitivas externas Spatie/Eloquent, migraciones ni escritores no cooperantes. No se añadió auditoría genérica de rechazos, interfaz de consulta, edición/borrado público, SMTP real ni frontend. La revisión independiente de seguridad, instalación limpia, compatibilidad/versiones, documentación, licencias y preparación Composer sigue pendiente antes de publicar. No se creó ninguna cuenta real ni se modificó Asisnet.
