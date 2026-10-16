# Estado de verificación — 1.0.0

[English version](status.md)

Versión: **1.0.0**. Fecha de revisión documental: **2026-10-16**. La verificación se limita a los entornos y suites siguientes; no constituye una certificación de seguridad.

## Entornos

| Componente | Entorno verificado |
|---|---|
| PHP | 8.2.28, 8.3.35 y 8.4.26 |
| Laravel / Spatie | Laravel 12.69.2 y resolución mínima de desarrollo 12.0.1; Spatie 6.25.0 |
| SQLite | Suites funcionales, instalación y documentación |
| MariaDB | 10.6.12 con InnoDB, concurrencia entre procesos |
| Composer | 2.8.11 |
| Análisis | PHPStan/Larastan nivel 5 |

La matriz completa PHP/dependencias actuales-mínimas se ejecutó el 2026-09-22 con 216 pruebas y 692 aserciones por combinación. Las comprobaciones documentales de 1.0.0 complementan esas pruebas funcionales. Consultar [compatibilidad](compatibility.es.md) para el experimento Laravel 12.0.0, avisos de dependencias, resolución por runtime y plataformas no verificadas.

## Suites disponibles

Verificación del 2026-10-02: **220 pruebas / 700 aserciones** en la suite del paquete y **21 pruebas / 127 aserciones** en MariaDB. Pasaron validación Composer, análisis nivel 5, Pint y sintaxis.

| Suite | Alcance |
|---|---|
| composer test | P/Q/D, jerarquía, identidad, servicios, HTTP, migraciones, caché, auditoría y documentación |
| composer analyse | PHP productivo, configuración, migraciones y rutas |
| vendor/bin/pint --test | Formato del código |
| tests/Support/run-mariadb.php | 21 pruebas / 127 aserciones: concurrencia, rollback, auditoría y DDL parcial |
| DocumentationTest | PHPDoc de métodos nombrados, parejas bilingües y fixtures de los detectores |
| Consumidor Laravel independiente | 26 comprobaciones por escenario limpio/Spatie existente |

El inventario cubre **182 métodos productivos nombrados**, todos con PHPDoc individual descriptivo. La documentación pública contiene **13 parejas inglés/español**, además de ambos README. Incluye interfaces, traits, constructores, métodos privados/protegidos y up/down de migraciones; excluye closures.

La instalación independiente utiliza Composer con VCS/archivos aislados, sin el repositorio path anfitrión. Comprueba autodiscovery, publicación, migraciones/rollback Artisan, datos Spatie conservados, User propio, servicios, HTTP opcional y rollback de auditoría obligatoria. La revisión de distribución inspecciona índice/archivo reales y compara los archivos instalados.

## CI y límites

GitHub Actions define PHP 8.2/8.3/8.4 × lowest/latest y una tarea MariaDB 10.6 separada. GitHub Actions fue verificado remotamente en PHP 8.2, 8.3 y 8.4 con resoluciones lowest/latest, además de MariaDB 10.6. La instalación desde un tag publicado no forma parte de esta verificación. Seguir el [proceso de publicación](releasing.es.md) al publicar.

No están verificados servidores MySQL reales, otras versiones MariaDB, PHP fuera de la matriz, clientes SMTP/móviles externos ni rendimiento bajo carga. Los mínimos históricos tienen avisos de seguridad; el éxito funcional no recomienda su uso productivo. SQL no cooperante, transacciones entre conexiones y adaptadores arbitrarios quedan fuera de las garantías. Los errores de caché posteriores al commit y el DDL parcialmente confirmado requieren reconciliación o recuperación revisada; consultar [seguridad](security.es.md) y [migraciones](migrations.es.md).
