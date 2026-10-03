# Propiedad de migraciones e instalación parcial

[English version](migrations.md)

Se respetan los nombres configurados de tablas y claves pivot/morph de Spatie. Las tablas del paquete tienen nombres fijos: lpm_delegation_policies, lpm_policy_permission, lpm_write_locks y lpm_schema_columns. Las cuatro solicitan InnoDB explícitamente; SQLite ignora esa declaración. Las tablas Spatie existentes y las participantes del anfitrión/auditoría deben ser transaccionales. El diagnóstico informa motores incompatibles sin cambiarlos automáticamente.

Instalar primero el esquema Spatie. La extensión añade description/level nullable al rol y description al permiso cuando faltan, además de delegation_policy_id al rol. El diario de propiedad registra solo columnas realmente añadidas. Se conservan usuarios, roles, permisos, asignaciones de roles, permisos directos y relaciones rol-permiso existentes. Las columnas level/description preexistentes se mantienen. Una delegation_policy_id o tabla propia ya existente se rechaza antes de cualquier reparación automática.

Revertir una migración completada elimina filas de políticas, membresías Q, asociaciones rol-política, fila de coordinación, diario y únicamente las columnas registradas. Permanecen las tablas Spatie originales y las columnas description/level del anfitrión. Los valores de columnas añadidas por el paquete se pierden. No utilizar este rollback como atajo de recuperación sobre una base productiva persistente.

## DDL MariaDB interrumpido

El DDL puede confirmarse independientemente del registro de migraciones. Un fallo entre ADD COLUMN y el INSERT del diario puede dejar una columna real sin registro de propiedad. Por ello, el diario por sí solo no demuestra propiedad tras una instalación interrumpida. Repetir up rechaza la colisión en lugar de adoptar o eliminar estructuras.

1. Detener escritores administrativos y conservar respaldo de esquema/datos, registro de migraciones, configuración y evidencia del esquema anterior.
2. Ejecutar permission-manager:check; inspeccionar el registro de migraciones, SHOW CREATE TABLE de las tablas afectadas, motores, claves foráneas, diario y fila de lock. El diagnóstico comprueba tablas/motores/lock, no toda posible inconsistencia de columnas o restricciones.
3. Comparar cada estructura con el esquema previo. Si no puede establecerse el origen, detenerse y solicitar una migración revisada. No inferir propiedad por un nombre conocido ni por ausencia de una fila del diario.
4. Elegir una restauración revisada del respaldo previo o una reparación explícita hacia adelante. Limitar cualquier limpieza a estructuras cuya creación por el intento fallido esté demostrada. Preservar filas/pivotes Spatie y columnas anfitrionas. No existe comando de reparación automática.
5. Volver a comprobar infraestructura, restricciones, conteos, autorización y auditoría antes de reanudar escrituras. Revisar el registro de migraciones solo cuando el esquema coincida con el estado previsto.

La regresión MariaDB descartable interrumpe la ejecución justo después de añadir permissions.description y antes de registrarla. Comprueba que la columna permanece, el diagnóstico falla, el reintento rechaza la colisión y se conservan los roles originales. La recuperación exclusiva de la fixture elimina columnas cuya ausencia previa fue registrada, reinstala y revierte normalmente. Esto verifica esa ventana de fallo, no todos los fallos DDL ni cualquier esquema externo.
