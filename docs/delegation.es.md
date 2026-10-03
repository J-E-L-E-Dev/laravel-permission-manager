# Servicios de delegación

[English version](delegation.md)

Usar `Services\DelegationPolicyService` con operador anfitrión explícito y persistido:

```php
$policy = $policies->create($actor, ['name' => 'Support scope', 'slug' => 'support-scope']);
$policies->syncPermissions($actor, $policy, $existingPermissionIds);
$policies->update($actor, $policy, ['active' => true]);
$policies->assignToRole($actor, $role, $policy->id);
$policies->assignToRole($actor, $role, null); // Valida también el contenido saliente.
```

Políticas inactivas por defecto; una creación autorizada puede indicar active=true explícitamente. El nombre es editable por separado, el slug no se modifica. Reemplazar por [] es válido y comprueba la autoridad retirada. Una política inactiva no puede asignarse, tampoco por admin. No hay eliminación de políticas. Una política puede compartirse: modificar su contenido afecta a todos los consumidores; asociar afecta sólo al rol elegido.

Los IDs deben ser enteros PHP positivos y existir en el guard configurado; llamadas internas no aceptan strings numéricos. Los nombres arbitrarios no crean permisos. Modificar políticas no copia P en Q ni crea membresías de usuarios.

`PermissionDelegationService::snapshot`, `delegablePermissionIds`, `capabilities`, `roleUsagePermissions` y `roleDelegablePermissions` son primitivas de lectura para código confiable. No autorizan la divulgación administrativa por HTTP. AuthorityQueryService proporciona las consultas autorizadas y la API HTTP opcional las utiliza; ver api.es.md. No exponer modelos o footprints sin autorización.

Los comandos `permission-manager:provision --dry-run` y `permission-manager:provision` están implementados. La configuración provisioning enumera roles existentes; el plan completo detecta conflictos y se recalcula bajo el lock. Crea políticas estructurales vacías, preserva contenido/nombre/actividad administrados y no enlaza una política poblada a un rol desvinculado de forma implícita. No crea permisos ni roles. Sigue siendo una operación privilegiada de infraestructura, sin endpoint HTTP; consultar integration.es.md.

