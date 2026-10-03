# Compatibilidad

[English version](compatibility.md)

## Requisitos

La versión 1.0.0 declara PHP ^8.2, Illuminate support/database/validation ^12.0 y Spatie Permission ^6.25, con PDO, filter y JSON. Instalar pdo_sqlite o pdo_mysql según la base elegida; Laravel y sus dependencias imponen requisitos adicionales de extensiones. No están soportados teams, wildcards, actores Passport client credentials, guards múltiples simultáneos ni claves Spatie no enteras.

## Combinaciones verificadas

| Entorno | Verificación |
|---|---|
| PHP 8.2.28 / Laravel 12.69.2 / Spatie 6.25.0 | Suite funcional y consumidor Laravel independiente |
| PHP 8.3.35 y 8.4.26 / mismo conjunto actual de dependencias | Suite funcional y análisis estático |
| PHP 8.2.28, 8.3.35 y 8.4.26 / Laravel 12.0.1 / Spatie 6.25.0 / dependencias mínimas de desarrollo | Suite funcional; resolver dependencias para cada runtime |
| Laravel 12.0.0 / Spatie 6.25.0 / dependencias mínimas / PHP 8.2.28 | Experimento histórico con consumidor limpio y existente |
| Laravel 12.0.0 / dependencias transitivas actuales | Composer resuelve, pero falla discovery de Artisan; no es una combinación funcional verificada |
| MariaDB 10.6.12 / InnoDB / PHP 8.2.28 | Concurrencia entre procesos, rollback de auditoría y DDL interrumpido |
| SQLite / PHP 8.2, 8.3 y 8.4 | Cobertura funcional; no sustituye concurrencia MariaDB |
| Otras versiones MariaDB, servidores MySQL reales o PHP fuera de estas filas | No verificados |

La matriz se ejecutó el 22 de septiembre de 2026. Spatie 6.25.0 fue el mínimo declarado y la versión 6.x permitida más reciente resuelta entonces; Laravel 12.69.2 fue la versión actual resuelta. Son versiones registradas, no afirmaciones sobre el registro de hoy. Los binarios Windows PHP 8.3/8.4 se verificaron con los SHA-256 oficiales. Consultar [estado de verificación](status.es.md) para suites y fechas.

## Herramientas de desarrollo

Las dependencias de desarrollo utilizan Testbench ^10.0, Pest ^3.8,
Pint ^1.24, PHPStan ^2.2.16 y Larastan ^3.12.2.

PHPStan y Larastan son herramientas de desarrollo y no se instalan como
dependencias de ejecución en las aplicaciones que utilizan este paquete.

La matriz `lowest` de CI continúa resolviendo las versiones mínimas soportadas
de las dependencias de ejecución, incluyendo PHP, Laravel y Spatie Permission.
Las herramientas de análisis estático conservan sus versiones mínimas
explícitamente soportadas para utilizar las mismas reglas de análisis en toda
la matriz de compatibilidad.

Las dependencias se resuelven independientemente en PHP 8.2, 8.3 y 8.4 porque
las restricciones transitivas de plataforma pueden variar entre runtimes.

## Seguridad de dependencias y CI

Los conjuntos mínimos históricos del consumidor y desarrollo reportaron respectivamente 38 avisos en 10 paquetes y 42 en 12. Son experimentos descartables de compatibilidad, no recomendaciones productivas. El conjunto actual no reportó avisos al instalarse en la fecha de verificación. El éxito funcional no certifica seguridad de dependencias; mantenerlas actualizadas y ejecutar composer audit en la aplicación consumidora.

La matriz CI resuelve lowest/latest en PHP 8.2/8.3/8.4. La política de seguridad Composer puede elegir mínimos diferentes. Los rangos declarados no demuestran que funcione toda combinación transitiva. La ejecución remota del workflow no forma parte de la evidencia registrada; consultar [estado de verificación](status.es.md).
