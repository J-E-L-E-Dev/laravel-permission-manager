# Modelo de autorización

[English version](authorization-model.md)

P(rol) son los permisos funcionales Spatie. Q(rol) contiene los permisos de su política activa; es vacío si no existe o está inactiva. D(usuario) es la unión de Q de los roles persistidos del guard configurado. Los permisos directos y la condición de gestor no amplían D. El admin exacto tiene D igual al catálogo actual de ese guard, sin grants físicos ni Gate::before funcional global.

Un reemplazo verifica actuales ∪ finales ⊆ D_pre, incluidos retirados y conservados. Reemplazar roles valida P y Q activa de todos los roles entrantes/salientes, identidad y jerarquía. Los cambios de contenido/vínculo de políticas comprueban el contenido almacenado incluso inactivo. El estado candidato se calcula antes de persistir. Los snapshots son valores internos inmutables: no construirlos desde HTTP ni reutilizarlos para autorizar escrituras posteriores.

La jerarquía usa un rango entero inclusivo configurable; menor significa mayor autoridad. No se proporcionan roles comerciales ni rol implícito. Niveles null/fuera de rango no confieren autoridad jerárquica. Por defecto sólo se administran niveles estrictamente inferiores. `allow_same_owned_role=true` permite el mismo rol propio a igual nivel, sin eliminar controles de identidad sensible, miembros, política propia ni promoción. Otros roles del mismo nivel siguen bloqueados. Un nivel alto nunca concede admin global por sí solo.

Reservados: admin, gestores y `reserved_role_names`. Sensibles: reservados más el resultado fresco de `SensitiveRoleResolver`. Ser reservado no convierte al rol en gestor. Los nombres nuevos usan segmentos ASCII minúsculos; identidad privilegiada y guard se comparan exactamente. No-admin no renombra identidades existentes ni transfiere roles sensibles. Nombres ambiguos entre guards se rechazan al componer escrituras; no se declara soporte multiguard general.

Cambiar P o nivel comprueba todos los usuarios que comparten el rol. Cambiar contenido/actividad de política comprueba todos sus consumidores y miembros. El footprint incluye permisos directos, P, Q activa e identidades cualitativas. No-admin no cambia la política de ningún rol propio, incluso a la misma o a null. Un no-op también exige autorización.

Los nombres de permisos anfitriones en `abilities` controlan entrada independientemente de D. Mapeos vacíos deniegan operadores ordinarios. Gestores pueden entrar a operaciones de políticas, sujetos a alcance, jerarquía y consumidores. El bypass admin se limita a los servicios administrativos del paquete.
