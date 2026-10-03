# Frontera de seguridad

[English version](security.md)

## Escrituras coordinadas

Cada escritura administrativa adquiere la fila común antes de leer autoridad fresca y persistir cambios. MariaDB/MySQL exige InnoDB en toda tabla participante y rechaza transacciones externas para evitar instantáneas REPEATABLE READ antiguas. SQLite reserva el escritor con un update porque no dispone de SELECT FOR UPDATE. La ausencia de coordinación es un error, nunca se repara silenciosamente.

El lock global prioriza integridad sobre rendimiento. No se promete benchmark de carga ni reintento automático de deadlocks. Implementar un driver no acredita soporte probado para todo servidor; consultar [compatibilidad](compatibility.es.md).

| Operación | Frontera |
|---|---|
| Administración autenticada | Servicio autorizado con operador persistido explícito |
| Catálogo/bootstrap del anfitrión | Infraestructura privilegiada, no autoridad implícita de usuario |
| Migraciones/seeders | Infraestructura controlada que puede cambiar autoridad fuera de servicios |
| Llamadas Spatie/Eloquent directas | No se interceptan ni autorizan automáticamente |
| SQL/Tinker/administrador de base | Fuera de los controles del paquete |

No exponer assignRole/syncRoles/syncPermissions/givePermissionTo directamente en endpoints administrativos propios ni fabricar un actor admin. Las primitivas de lectura no autorizan divulgar catálogo o autoridad del objetivo. Los listeners anfitriones no deben modificar RBAC ocultamente después de autorizar. Los resolvers de identidad deben devolver estado fresco y coordinar cambios concurrentes. PHP/SQL arbitrario confiable queda fuera de los controles.

## Cobertura de seguridad

Testbench utiliza un User anfitrión de prueba sin campos comerciales. Las pruebas cubren alcance actuales ∪ finales, P ∪ Q activa, jerarquía, identidades sensibles, múltiples roles, IDs inválidos, nombres personalizados de tablas/pivotes/morph, miembros/consumidores compartidos, autoescalamiento sucesivo, elegibilidad del último administrador, autorización HTTP, rollback de cuentas y conservación de datos Spatie existentes.

El descubrimiento contextual es informativo; cada escritura repite la autorización. Ser gestor no concede autoridad funcional o delegable adicional. Las reglas de identidad reservada complementan P/Q/D, incluido el control indirecto mediante roles compartidos. El catálogo de permisos no tiene endpoint administrativo de modificación.

El anfitrión controla revocación de sesiones/tokens, borrado lógico, bootstrap y recuperación auditada. Debe verificar sus adaptadores e integraciones externas. SMTP y aplicaciones móviles/web externas no están cubiertos de extremo a extremo. No se soportan teams, wildcards, guards múltiples simultáneos, claves Spatie no enteras ni transacciones entre conexiones. Las capacidades consultan recursos individualmente; no se garantiza rendimiento por lotes.

## Pruebas MariaDB descartables

Desde el código fuente, proporcionar credenciales de un servidor dedicado a pruebas:

```text
LPM_TEST_HOST=127.0.0.1
LPM_TEST_PORT=3306
LPM_TEST_USERNAME=<test-user>
LPM_TEST_PASSWORD=<test-password>
```

```bash
php tests/Support/run-mariadb.php
```

El helper valida MariaDB, crea una base aleatoria, ejecuta pruebas y elimina esa base exacta en finally. No lee archivos de entorno anfitriones. El usuario de pruebas necesita permiso para crear/eliminar bases descartables.

Para una base descartable preparada manualmente, el ejecutor directo protegido utiliza:

```text
LPM_DISPOSABLE_DATABASE=1
LPM_TEST_DATABASE=lpm_test_<16 lowercase hexadecimal characters>
```

```bash
php vendor/bin/pest -c phpunit.mariadb.xml --compact
```

Proporcionar las mismas variables de conexión. La suite valida la base real y el patrón de nombre, con 16 caracteres hexadecimales minúsculos, y elimina todas sus tablas. Nunca utilizar datos persistentes de una aplicación.

Procesos PHP separados y barreras comprueban revocación, desactivación/desvinculación de políticas, consumidores/miembros cambiados, asignaciones simultáneas, creación contra revocación, suspensión contra cambios de autoridad, carreras del último admin, diferencias/no-op y rollback de auditoría, pivotes no InnoDB y DDL interrumpido. Los escritores cargan deliberadamente relaciones antiguas antes de esperar. Las fixtures privilegiadas del primer escritor usan el mismo lock; las pruebas no serializan SQL arbitrario no cooperante. Consultar resultados en [estado de verificación](status.es.md).

## Caché de permisos y rollback

La frontera invalida la caché Spatie en memoria/almacén después del commit o rollback. Las pruebas cubren observadores síncronos que cargan permisos no confirmados, fallo de auditoría obligatoria, cambios P satisfactorios, membresías/permisos directos y políticas. P/Q/D autoriza escrituras desde persistencia bajo lock, nunca desde esa caché.

Las instancias Eloquent retenidas pueden conservar relaciones antiguas; utilizar el modelo fresco retornado o recargarlas. No existe garantía universal de coherencia para otros procesos prolongados o lectores no cooperantes. La disponibilidad de caché es una dependencia operativa: un error posterior al commit no deshace la escritura confirmada. Reconciliar estado e historial antes de reintentar. Los efectos externos esperan al commit.

Consultar [auditoría](auditing.es.md), [migraciones](migrations.es.md) y [análisis estático](static-analysis.es.md) para confianza del adaptador, recuperación DDL y límites del análisis.
