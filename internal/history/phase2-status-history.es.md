> HISTÓRICO: resultado de fase 2, sustituido por fase 2.1. No utilizar el antiguo bootstrap por ID; el contrato vigente está en status.es.md y en la guía de DomiYa.

# Entrega de fase 2 — 2026-09-21

## Resultado

Se amplió el paquete existente y se implementó el módulo de usuarios de DomiYa. La versión continúa en desarrollo local; no hubo publicación Packagist, despliegue, commit ni tag. Asisnet se utilizó como referencia de lectura y sus 81 hashes históricos siguen coincidiendo.

| Área | Estado implementado |
|---|---|
| Motor P/Q/D, jerarquía, identidades y consumidores | Conservado; servicios de autorización existentes siguen siendo la base |
| Cuentas + autoridad en una transacción | AuthorizedUserMutation, UserOperation, UserAccountWriter y evento de auditoría |
| Último administrador elegible | Protege retiro de rol, cambios de elegibilidad, suspensión y borrado; consulta candidatos en bloques de 100 |
| Incrementos | assignRole/removeRole/grantDirectPermission/revokeDirectPermission, con lectura dentro del lock |
| Consultas | Catálogos, recursos gestionables, roles/capacidades/candidatos contextuales, directos/heredados/efectivos y concesión/revocación |
| HTTP del paquete | Opcional, desactivado por defecto; activado explícitamente en DomiYa con autenticación y cuenta activa |
| Aprovisionamiento | Comando con dry-run, plan completo, conflictos, idempotencia y preservación sin P→Q |
| Infraestructura | Inspección de InnoDB en todas las tablas Spatie, usuario, paquete y extras declarados |
| DomiYa auth | Registro limitado, Sanctum Bearer/cookie+CSRF, perfil, cambios/reset de contraseña, verificación firmada de correo, logout/logout-all |
| DomiYa administración | Crear/editar/suspender/reactivar/borrar lógicamente, usando la frontera del paquete |
| Revocación y auditoría | Tokens, sesiones, remember_token y generación de sesión; auditoría atómica sin secretos |
| Bootstrap/recuperación | CLI sobre cuenta activa/verificada existente, confirmación, ausencia de otro admin elegible y motivo auditado |

## Inspección y línea base real

Se compararon los ZIP disponibles sin sobrescribir las carpetas: 66 archivos de DomiYa iguales y sólo log distinto; 57 archivos del paquete iguales y sólo caché PHPUnit distinta. Asisnet estaba extraído, sin ZIP localizado en los directorios inspeccionados. Se volvieron a leer fuentes de cuentas/auth/provisioning/candidatos y se compararon los 81 hashes del inventario previo; phase2-source-inventory.json registra la revisión adicional.

Antes de modificar código se ejecutaron nuevamente: paquete 153 pruebas / 432 aserciones; DomiYa 3 / 5; MariaDB 8 / 44. La base domi_ya ya existía con cinco migraciones aplicadas, corrigiendo la observación de ausencia de base de la primera entrega.

## Verificación final ejecutada

| Comando / alcance | Resultado observado |
|---|---|
| php vendor/bin/pest --compact, paquete | **177 passed, 530 assertions**, 31.62 s |
| php artisan test --compact, DomiYa | **23 passed, 117 assertions**, 7.27 s |
| php tests/Support/run-permission-manager-mariadb.php | **15 passed, 81 assertions**, MariaDB 10.6.12, 331.51 s |
| Composer require/update Sanctum | Sanctum 4.3.3 instalado; una dependencia nueva, ninguna otra actualización |
| composer validate --strict, ambos proyectos | Válidos; paquete sin Git usa fallback informativo de versión raíz |
| Pint sobre código del paquete y DomiYa | Formato aplicado y Pint --test passed en ambos proyectos |
| php -l sobre src/app, config, database, routes y tests | **98 archivos**, cero errores de sintaxis |
| Independencia del código productivo del paquete | Sin namespaces App, ApiApplication, nombres comerciales de proyectos o rutas Windows incrustadas |
| php artisan migrate, DomiYa local | Dos migraciones aditivas aplicadas; las cinco originales se conservaron |
| php artisan route:list --path=api --json | 42 rutas API registradas |
| php artisan permission-manager:check | RBAC infrastructure ready, con las tablas adicionales del anfitrión |
| php artisan domiya:provision-access | Catálogo técnico instalado; 2 roles, 14 permisos, 0 usuarios y 0 políticas |
| php artisan permission-manager:provision --dry-run | Plan vacío sin conflictos: DomiYa no configura estructuras Q automáticas |

Las suites no se suman por subconjuntos. Los 107 casos originales del motor están incluidos en las 177 pruebas del paquete. No se ejecutó PHPStan/Psalm: no estaban configurados; el lint PHP y Pint no se presentan como análisis estático de tipos.

MariaDB usa una base aleatoria lpm_test_<hex>, procesos separados y barreras, y elimina esa base exacta en finally. Mantiene los ocho casos previos y agrega siete: creación frente a revocación de entrada, suspensión frente a cambio de rol, incremento simultáneo de permisos, incremento simultáneo de roles, retiro de admin frente a pérdida concurrente de elegibilidad de otro, rollback tras guardar cuenta y rechazo de pivot Spatie no transaccional. El primer escritor de las carreras es un fixture de infraestructura que coopera con el lock; no se afirma serialización de SQL arbitrario.

Incidentes resueltos durante la verificación: el argumento ^4.0 de Composer se interpretó inicialmente como 4.0 exacto en Windows, Composer revirtió ese intento y se declaró el constraint correcto en JSON; tres fixtures usaban Role::findByName sin guard después de autenticar Sanctum y se corrigieron a web explícito; se corrigió una aserción de JSON sobre claves literales con puntos; tras una interrupción MariaDB no respondía, se reinició con su configuración local XAMPP; la prueba MyISAM inicialmente falló al preparar un índice demasiado largo y se ajustó únicamente la tabla descartable antes de repetir la suite completa con éxito. No se eliminaron comprobaciones para obtener estos resultados.

## Archivos y responsabilidades

El inventario del paquete está en file-inventory.md. Los contratos y la secuencia transaccional se detallan en integration.es.md; todos los endpoints y payloads, en api.es.md; origen/exclusiones y matriz SEC-01..18, en audit.es.md.

DomiYa incorporó:

- app/Services: Authentication, UserAdministration, AccountWriter, AccountAccess y AccessCatalog.
- app/Http: AuthController y UserController bajo Api, ActiveAccount y UserResource; las entradas se validan explícitamente mediante Laravel y allowlists de AccountValidation/AccountWriter.
- app/Models/User: HasRoles conservado; HasApiTokens, SoftDeletes, active/auth_version, correo verificable y serialización protegida.
- app/Console/Commands: ProvisionAccess y BootstrapAdmin con recuperación explícita.
- app/Notifications/VerifyAccountEmail: cola después del commit.
- AppServiceProvider: contrato del adaptador, listener de auditoría, rate limiting y URL de reset.
- bootstrap/app.php y routes/api.php: API REST, Sanctum stateful y errores sin trazas/SQL públicos.
- config/permission-manager.php: mapeos técnicos, HTTP autenticado y tablas transaccionales; config/sanctum.php oficial; FRONTEND_URL en config/app.php y ejemplos de entorno.
- Migraciones 2026_09_21_070000_add_account_lifecycle y 2026_09_21_103139_create_personal_access_tokens_table.
- DatabaseSeeder: ya no crea cuenta de ejemplo; sólo aprovisiona el catálogo.
- AccountsApiTest y ampliación del helper MariaDB; composer.json/lock con Sanctum.
- README y docs/users-and-auth.es.md para instalación, API, Android/web, correo, catálogo y bootstrap.

## Límites y próximos pasos de uso

No hay cuenta administrativa predeterminada. Para utilizar la API administrativa debe registrarse/verificarse una cuenta y ejecutarse el bootstrap sobre su ID bajo control del servidor. No se eligió un propietario ni se creó una cuenta real durante la implementación.

El correo real, worker de colas, dominio/HTTPS y orígenes del frontend deben configurarse en el entorno de uso. Las pruebas usan notificaciones simuladas; no se enviaron emails ni se implementó el cliente Android, la SPA o su pantalla de recuperación. El backend y sus flujos están implementados y probados.

El paquete mantiene una sola conexión/guard RBAC, claves enteras de roles/permisos y no soporta teams/wildcards. Se verificó SQLite y MariaDB 10.6.12; no un servidor MySQL 8 independiente. No hay benchmark de carga, optimización de capacidades por lotes ni garantía para resolvers/listeners/SQL no cooperantes. Los contratos del adaptador y listeners de auditoría son código confiable del anfitrión. Las listas completas y las proyecciones por recurso pueden necesitar optimización antes de manejar catálogos grandes; las comprobaciones de escritura no deben debilitarse.

No quedan endpoints de creación/edición de permisos ni bypass HTTP de recuperación. Las futuras funciones comerciales (pedidos, vendedores, repartidores, empresas, tiendas) permanecen fuera de esta fase.



