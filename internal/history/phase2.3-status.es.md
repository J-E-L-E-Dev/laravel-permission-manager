# Fase 2.3 — cierre de auditoría y preparación de publicación

Verificado el 22 de septiembre de 2026. **1.0.0 es una propuesta todavía sin publicar**. La preparación local está completa con las limitaciones indicadas; no equivale a una certificación absoluta de seguridad.

## 1. Línea base

Se inspeccionaron el paquete existente y su integración anfitriona antes de modificar código, sin reconstruir el sistema desde otra aplicación.

| Suite | Inicial: pruebas / aserciones | Final |
|---|---:|---:|
| Paquete, SQLite | 205 / 641 | 216 / 692 |
| DomiYa | 75 / 342 | 75 / 342 |
| Paquete, MariaDB | 15 / 81 | 21 / 127 |
| Identidad reservada, MariaDB | 5 / 49 | 5 / 49 |
| Auditoría anfitriona, MariaDB | 5 / 44 | 5 / 44 |

Entorno inicial: PHP 8.2.28, Composer 2.8.11, Laravel 12.69.2, Spatie 6.25.0 y MariaDB 10.6.12. El primer intento MariaDB del paquete excedió 600 segundos y se conservó como fallo; la repetición con límite de 1.800 segundos pasó antes de modificar código. La línea base de auditoría anfitriona se observó en terminal; las demás líneas base y todas las suites finales tienen registros externos. Un código de salida cero del manejador de excepciones no se trató como prueba satisfactoria.

## 2. Hallazgos P-01 a P-05

| Hallazgo | Estado y límite |
|---|---|
| P-01, instalación independiente | Resuelto localmente con Composer VCS aislado y ZIP; falta el futuro tag remoto. |
| P-02, compatibilidad | Matriz PHP 8.2/8.3/8.4 ejecutada; combinaciones fallidas y no verificadas documentadas. |
| P-03, análisis estático | Nivel 5 sin errores; excepciones acotadas y justificadas. CI preparado, sin ejecución remota. |
| P-04, migraciones | InnoDB explícito, conservación de datos y recuperación de DDL parcial comprobados. |
| P-05, documentación | Enlaces autónomos, historial conservado, índice real y distribución revisados. |

## 3. Consumidor independiente

Aplicación Laravel externa al paquete y a DomiYa, sin código anfitrión ni repositorio Composer path. La instalación inicial desde Git local pasó 19 comprobaciones por escenario. La instalación final desde el ZIP generado por Composer pasó **26 comprobaciones en instalación limpia y 26 con datos Spatie existentes**.

Se comprobaron autodiscovery, configuración, publicación efectiva, migraciones mediante Artisan, Role del paquete, User propio con HasRoles, diagnóstico, aprovisionamiento sin concesiones P→Q, servicios autorizados, auditoría opcional/obligatoria, rechazo sin adaptador, rollback ante fallo y ausencia de duplicados en operaciones sin cambios. HTTP está desactivado de origen; las rutas registradas para la prueba responden 401 a invitados, 200 al administrador autenticado y 403 al operador sin autoridad.

Spatie se migra primero y después se publica/migra la extensión. Artisan revierte solo la extensión y la aplica de nuevo, preservando usuarios, roles, permisos y las tres relaciones originales. Cada escenario usa su base SQLite y directorio de migraciones descartables. Se documentó que repetir la publicación puede crear archivos con otro timestamp: no republicar migraciones instaladas.

## 4. Compatibilidad comprobada

| PHP | Dependencias actuales | Mínimas resolubles |
|---|---|---|
| 8.2.28 | Laravel 12.69.2 / Spatie 6.25.0: 216 / 692 | Laravel 12.0.1 / Spatie 6.25.0: 216 / 692 |
| 8.3.35 | Mismo conjunto actual: 216 / 692 | Resolución propia del runtime: 216 / 692 |
| 8.4.26 | Mismo conjunto actual: 216 / 692 | Resolución propia del runtime: 216 / 692 |

Los PHP portables proceden del registro oficial Windows PHP y se verificaron por SHA-256, sin reemplazar el runtime anfitrión. El análisis estático pasó con dependencias actuales en las tres versiones y con resoluciones mínimas. Reutilizar inicialmente el lock mínimo de PHP 8.2 falló en la comprobación de plataforma de PHP 8.3/8.4 por nette/schema; resolver bajo cada runtime corrigió el conjunto y las comprobaciones finales pasaron.

El consumidor histórico Laravel 12.0.0 con dependencias mínimas pasó 19 verificaciones por escenario. **Laravel 12.0.0 con transitivas actuales resolvió en Composer pero falló en Artisan durante discovery**; no se declara combinación verificada. Los mínimos históricos reportaron avisos de seguridad: 38 en 10 paquetes del consumidor y 42 en 12 del entorno de desarrollo. PHP 8.4 emitió además deprecaciones de dependencias antiguas, conservadas en los registros. No son recomendaciones productivas. La resolución actual no reportó avisos durante instalación.

Spatie 6.25.0 fue el mínimo y la versión permitida más reciente resuelta. MariaDB real se comprobó únicamente en 10.6.12/InnoDB; no se verificó MySQL real ni otras versiones MariaDB. Los rangos Composer no certifican todas sus combinaciones transitivas. Véase [compatibilidad](compatibility.md).

## 5. Composer

Se conservaron nombre, MIT, autoload, provider y rangos PHP ^8.2, Illuminate ^12.0 y Spatie ^6.25. Se declararon PDO, filter y JSON. PHPStan ^2.1 y Larastan ^3.0 son herramientas de desarrollo, con comandos de análisis y MariaDB. El manifiesto no contiene rutas anfitrionas ni versión ficticia. Composer valida estrictamente el paquete y DomiYa; el lock local se excluye de distribución.

## 6. Análisis estático

PHPStan/Larastan nivel 5 sobre src, config, migraciones y rutas: **sin errores**. Se precisaron genéricos, relaciones, configuración y consultas escalares sin cambiar firmas nativas públicas. Una excepción limitada permite cuatro métodos de HasRoles sobre Model; se desactivó la sugerencia opcional de optimización de colecciones que interfería con modelos hidratados y casts. No hay baseline masivo ni supresión global. Un stub BelongsTo se carga solo si falta en Larastan antiguo. Véase [análisis estático](static-analysis.md).

## 7. CI

GitHub Actions configura seis combinaciones PHP × lowest/latest con Composer, pruebas, PHPStan, Pint y sintaxis, más una tarea MariaDB 10.6. El helper portable exige credenciales explícitas, comprueba el servidor, crea una base aleatoria y elimina únicamente esa base. No lee configuración anfitriona. YAML validado localmente; ejecución remota pendiente. Composer moderno puede elegir mínimos diferentes por su política de seguridad.

## 8. Migraciones

Las cuatro tablas propias solicitan InnoDB, incluida lpm_schema_columns; SQLite sigue funcionando. Se mantienen nombres configurables y diario de propiedad. Rollback conserva tablas Spatie y columnas preexistentes; elimina políticas y columnas de la extensión.

Las pruebas incluyen motor predeterminado MyISAM y fallo después de un ALTER confirmado, antes del registro de propiedad. Se comprueban diagnóstico incompleto, rechazo de repetición conflictiva y recuperación controlada únicamente de elementos cuya ausencia previa demuestra la fixture. No hay reparación automática de estructuras reales. Véase [migraciones](migrations.md).

## 9. Seguridad, auditoría y caché

Se conservaron pruebas de autoridad delegable, jerarquía, gestores, autoescalamiento, roles compartidos, revocación concurrente, último administrador, guard, HTTP y catálogo no mutable. No se eliminaron pruebas ni relajaron expectativas. P/Q/D sigue consultando estado persistido bajo el bloqueo común.

Se reprodujo antes del cambio un defecto de caché: un listener podía cargar permisos dentro de una transacción que luego fallaba. Dos regresiones fallaron previamente. RbacWriteTransaction ahora invalida la caché en finally, después del commit o rollback. Once pruebas nuevas cubren concesión/revocación, membresías, permisos directos, políticas y fallo del adaptador obligatorio: 205 casos se convierten en 216.

Los fallos síncronos de auditoría obligatoria revierten modificación e historial en la misma conexión. Dos pruebas con procesos reales comprueban diferencias coherentes después del bloqueo y supresión de un segundo cambio idéntico. Se conservaron controles de secretos, correlación, conexión y duplicados.

Modelos ya cargados requieren refresh; otros workers pueden conservar estado local. Un fallo del backend de caché después del commit no deshace ese commit: reconciliar antes de reintentar. Adaptadores/listeners son código confiable; efectos externos requieren after-commit. Véase [seguridad](security.es.md) y [auditoría](auditing.es.md).

## 10. Verificación final

Las cinco suites de la tabla inicial pasaron. La MariaDB ampliada se ejecutó en la copia aislada, con igualdad de hashes comprobada para sus 64 archivos src/config/database/routes/tests respecto del paquete entregado. Pasaron PHPStan, Pint del paquete y anfitrión, Composer estricto y sintaxis de 65 archivos PHP. Cada combinación funcional de la matriz pasó 216 pruebas y 692 aserciones. SQLite no se presenta como evidencia de concurrencia MariaDB.

Consumidores, registros y manifiestos están en el directorio externo de verificación de la entrega, fuera de la distribución. Timeouts, fallos previos e intentos interrumpidos se distinguen de los resultados finales.

## 11. Documentación

Actualizados README bilingüe, instalación, integración, seguridad, auditoría y changelog. Se añadieron referencias de API pública, compatibilidad, análisis, recuperación y publicación. Los enlaces públicos se comprueban contra archivos dentro del paquete; no dependen de rutas Windows ni de documentos anfitriones. El historial e inventarios anteriores se conservan en internal/history del repositorio fuente y se excluyen del ZIP.

## 12. Distribución

Índice Git real revisado: **105 archivos**. Archivo Composer: **64 archivos**, con manifiesto, LICENSE, README bilingüe, CHANGELOG, src, config, rutas, migración y documentación pública. Se genera también archivo Git y manifiesto de contenido fuera del paquete; el ZIP se instala realmente mediante Composer.

Se excluyen vendor, .env y variantes, lock local, cachés, bases, logs, cobertura y temporales. Tests, CI, PHPStan e historial permanecen en Git, fuera de distribución. Se añadieron exclusiones explícitas a gitattributes al comprobar que gitignore no evita por sí solo que Composer recorra vendor. No se indexaron credenciales productivas; las de CI pertenecen al servicio descartable.

## 13. Contratos públicos

Documentadas firmas y semántica de AdministrativeIdentity, ActorEligibility, SensitiveRoleResolver, TransactionalAuditStore y UserAccountWriter, junto con servicios, UserOperation, AuthorityAudit y UserAuthorityChanged. No cambiaron firmas nativas de contratos; implementaciones anfitrionas siguen pasando las pruebas. El núcleo interno no reemplaza los servicios autorizados. Véase [API pública](public-api.md).

## 14. Riesgos pendientes

Faltan CI remoto e instalación desde tag publicado. MySQL, otras MariaDB y PHP fuera de la matriz no están verificados. Laravel 12.0.0 con transitivas actuales falla; usar resoluciones verificadas y mantenidas. Los mínimos con avisos son experimentos funcionales, no soporte productivo recomendado.

No se cubren escrituras SQL externas que eviten el bloqueo, conexiones transaccionales diferentes, actores Passport client credentials, teams, wildcards ni claves Spatie no enteras. El DDL parcialmente confirmado requiere recuperación informada. Sin adaptador persistente, la auditoría no promete historial durable. No se afirma seguridad absoluta.

## 15. DomiYa y Asisnet

No se cambiaron login, código, configuración, Composer ni migraciones publicadas de DomiYa; conserva su dependencia path. Las pruebas usan bases descartables. Una inspección de solo lectura de domi_ya confirmó nueve migraciones, cero usuarios, dos roles, catorce permisos y cuatro tablas propias InnoDB. No se ejecutaron migraciones, rollback ni reparación sobre esa base ni se creó un administrador real. Asisnet no se modificó.

## 16. Preparación para publicación

**Preparado localmente para revisar la propuesta 1.0.0**, con las limitaciones anteriores. Existe Git aislado con commits locales, sin remoto ni tags. No hubo Packagist ni despliegue. Tras revisión y autorización separada: ejecutar CI remoto, revisar el commit, crear el tag aprobado y verificar instalación. El cambio posterior del path anfitrión por una versión etiquetada está explicado en [publicación](releasing.md); no se realizó en esta fase.
