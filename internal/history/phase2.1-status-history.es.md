> HISTÓRICO: estado de fase 2.1, sustituido por fase 2.2. Consulte status.es.md para resultados vigentes.

# Estado vigente — fase 2.1, 2026-09-21

El paquete mantiene el motor P/Q/D y añade el contrato opcional AdministrativeIdentity. DomiYa reserva su identidad global por ID persistente y correo canónico, con aprovisionamiento y recuperación exclusivos de infraestructura. No se creó ninguna cuenta real, ni se publicó o desplegó el proyecto.

## Implementación

- AdministrativeIdentity y su implementación predeterminada neutra no incorporan nombres comerciales, correos ni tablas del anfitrión.
- isAdmin, snapshot, isPolicyManager y RoleHierarchy aplican la condición de identidad global del anfitrión.
- Los servicios de usuarios, roles y políticas conservan jerarquía, footprint actual, C/F, P/Q/D, miembros, consumidores y bloqueo común. Los hooks adicionales impiden transferencias de identidad en reemplazos, incrementos y operaciones compuestas.
- DomiYa vincula admin/web/nivel 1 únicamente al ID de reserved_admin_identity y al correo reservado. Las inconsistencias se rechazan; las asignaciones prohibidas también se excluyen de los candidatos.
- El CLI no acepta ID ni contraseña por argumentos. Crea exclusivamente la identidad reservada, pide contraseña oculta y atestación de correo, conserva datos en reintentos y distingue recuperación de contraseña, activación, autoridad e identidad.
- El registro público sigue asignando sólo cliente. La creación administrativa admite roles/directos autorizados o ninguna asignación y exige establecimiento de contraseña con token temporal de un solo uso enviado al destinatario después del commit.

## Línea base ejecutada antes de modificar

Paquete: 177 pruebas / 530 aserciones; DomiYa: 23 / 117; MariaDB del paquete: 15 / 81. La base persistente domi_ya tenía siete migraciones, dos roles, catorce permisos y cero usuarios. Se releyeron los flujos de Asisnet; sus 81 hashes de origen siguen coincidiendo.

## Verificación de fase 2.1

| Alcance | Resultado |
|---|---|
| Paquete, php vendor/bin/pest --compact | 181 passed, 544 assertions, 35.62 s |
| DomiYa, php artisan test --compact | 60 passed, 274 assertions, 21.64 s |
| MariaDB reservada, run-reserved-admin-mariadb.php | 5 passed, 49 assertions, 226.90 s |
| MariaDB paquete, run-permission-manager-mariadb.php | 15 passed, 81 assertions, 310.57 s |
| Composer validate --strict, ambos proyectos | Válidos |
| Sintaxis PHP | 111 archivos: 53 paquete y 58 aplicación, sin errores |
| Pint --test | Ambos proyectos aprobados |
| Migración aditiva local | Aplicada; ocho migraciones conservadas, ninguna cuenta creada |
| permission-manager:check | RBAC infrastructure ready |
| route:list --path=api --json | 42 rutas API |
| permission-manager:provision --dry-run | Plan vacío, sin cambios |
| Asisnet | 81 hashes coinciden, sin modificaciones |

Las suites no se suman por subconjuntos. Se conservaron los 177 casos anteriores del paquete; sólo se adaptaron las fixtures administrativas y los dos casos del antiguo bootstrap de DomiYa al nuevo contrato. Las cinco pruebas MariaDB adicionales usan procesos separados, barreras y una base aleatoria domiya_test_<hex>, eliminada al finalizar. Comprueban aprovisionamiento simultáneo, asignación de admin contra aprovisionamiento, modificación durante recuperación, correo contra aprovisionamiento y rollback después de guardar la cuenta. La suite previa usa otra base aleatoria lpm_test_<hex>. Nunca se ejecutó migrate:fresh en domi_ya.

## Documentación y límites

La guía vigente es [users-and-auth.es.md](../../../../domi-ya/docs/users-and-auth.es.md). El informe con los catorce puntos solicitados, archivos y comandos está en [phase2.1-report.es.md](../../../../domi-ya/docs/phase2.1-report.es.md). [integration.es.md](integration.es.md) describe el contrato; [api.es.md](api.es.md), los endpoints. [phase2-status-history.es.md](phase2-status-history.es.md) conserva el resultado histórico de fase 2 y no es una guía operativa vigente.

Sin envío SMTP real ni frontend Android/SPA probado de extremo a extremo. Configurar worker, correo, HTTPS, FRONTEND_URL y acceso al servidor antes del uso. El código confiable de infraestructura puede usar Spatie/Eloquent/SQL directamente: esas primitivas no ofrecen autorización y no deben usarse desde flujos administrativos alternativos. El lock protege escritores cooperantes; la base refuerza la unicidad canónica, no sustituye toda la política PHP. No se afirma soporte multiguard/teams, transacciones entre conexiones, MySQL 8 independiente, benchmark ni análisis PHPStan/Psalm.
