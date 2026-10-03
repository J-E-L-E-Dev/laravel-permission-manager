# Proceso de publicación

[English version](releasing.md)

Esta guía describe la publicación de una versión estable Composer. La versión documentada es **1.0.0**. Usar solo el directorio del paquete como raíz Git, dejando fuera aplicaciones anfitrionas y consumidores descartables. Composer obtiene las versiones de tags Git; no añadir version a composer.json.

## Validación

Ejecutar desde el código fuente con dependencias de desarrollo:

```bash
composer validate --strict
composer audit
composer test
composer analyse
vendor/bin/pint --test
php tests/Support/run-mariadb.php
```

Proporcionar credenciales explícitas de servidor descartable para MariaDB según [seguridad](security.es.md). Comprobar también sintaxis PHP productiva, enlaces, compatibilidad y consumidor independiente. Exigir CI satisfactorio sobre el commit de publicación; validar YAML localmente no demuestra ejecución remota.

## Código fuente y archivo

Revisar git status --short, git ls-files y git diff --cached. Las reglas ignore no retiran archivos ya indexados. Excluir vendor, archivos de entorno, composer.lock local, cachés, bases, logs, cobertura, credenciales y temporales.

El código fuente conserva pruebas, CI, análisis estático e historial interno; gitattributes los excluye de distribución. Los archivos conservan composer.json, LICENSE, ambos README, CHANGELOG, src, config, rutas, database/migrations y documentación pública bilingüe.

```bash
git archive --format=zip --output=../permission-manager-source.zip HEAD
composer archive --format=zip --dir=../dist --file=permission-manager-1.0.0
```

Inspeccionar ambos archivos e instalar el de Composer en una aplicación Laravel separada. Usar nombres/URLs inmutables o desactivar la caché Composer al verificar un ZIP local regenerado; comparar hashes instalados con el archivo. Los scripts de desarrollo requieren el código fuente.

## Tag y registro

Revisar versión/fecha del CHANGELOG, contratos públicos, límites de compatibilidad y commit. Tras autorizar la publicación, crear el tag anotado y enviarlo al remoto aprobado:

```bash
git tag -a 1.0.0 -m "Release 1.0.0"
git push origin main
git push origin 1.0.0
```

Usar la rama de publicación aprobada si no es main. Registrar el repositorio en Packagist o actualizar su entrada existente. Confirmar metadatos, referencias source/dist y tag; verificar después una instalación limpia:

```bash
composer require ahinest/laravel-permission-manager:^1.0
```

Verificar autodiscovery, configuración, orden de migraciones, diagnóstico, autorización, auditoría y rollback con datos descartables. Los comandos son instrucciones de mantenimiento, no evidencia de que ya se hayan ejecutado.

## Migrar consumidores de path a un tag

En una rama revisada, retirar únicamente la entrada path del paquete y sustituir dev-main por ^1.0. Ejecutar:

```bash
composer update ahinest/laravel-permission-manager --with-dependencies
```

Confirmar que composer.lock referencia el tag previsto y vendor ya no usa junction/symlink. Ejecutar las suites anfitrionas de integración, autorización y auditoría. Revisar diferencias de migraciones publicadas sin republicar las antiguas ni hacer rollback destructivo sobre una base persistente. Respaldar y aplicar explícitamente solo cambios de esquema realmente nuevos.
