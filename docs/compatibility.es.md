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

## Resolución de desarrollo

Dependencias de desarrollo: Testbench ^10.0, Pest ^3.8, Pint ^1.24, PHPStan ^2.1 y Larastan ^3.0. Composer debe elegir un Larastan 3.x compatible con Laravel 12; Larastan 3.0.0 requiere Laravel 11. No se debe asumir que todos los mínimos fijados pueden coexistir.

El conjunto mínimo utilizó Laravel 12.0.1, Testbench 10.0.0, Larastan 3.1.0 y PHPStan 2.1.3. Resolver en cada runtime PHP: el lock de PHP 8.2 contenía un nette/schema cuyo límite superior de PHP fallaba en las comprobaciones de plataforma de runtimes nuevos. La resolución separada produjo comprobaciones satisfactorias en PHP 8.3/8.4. Las dependencias antiguas emiten deprecaciones en PHP 8.4.

## Seguridad de dependencias y CI

Los conjuntos mínimos históricos del consumidor y desarrollo reportaron respectivamente 38 avisos en 10 paquetes y 42 en 12. Son experimentos descartables de compatibilidad, no recomendaciones productivas. El conjunto actual no reportó avisos al instalarse en la fecha de verificación. El éxito funcional no certifica seguridad de dependencias; mantenerlas actualizadas y ejecutar composer audit en la aplicación consumidora.

La matriz CI resuelve lowest/latest en PHP 8.2/8.3/8.4. La política de seguridad Composer puede elegir mínimos diferentes. Los rangos declarados no demuestran que funcione toda combinación transitiva. La ejecución remota del workflow no forma parte de la evidencia registrada; consultar [estado de verificación](status.es.md).
