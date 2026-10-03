# API opcional de Laravel Permission Manager

[English version](api.md)

Prefijo configurable: `/api/v1/permission-manager`. Desactivada por defecto: `http.enabled=false`. Al habilitarla, configurar middleware de autenticación del anfitrión (por ejemplo, autenticación de sesión o Sanctum y elegibilidad propia). Enviar `Accept: application/json`. Los servicios públicos vuelven a autorizar cada modificación.

Respuestas de datos: `{ "data": ... }`. Creación: 201; consultas/modificaciones: 200. Errores: 401 sin autenticación, 403 sin autoridad, 404 recurso inexistente, 409 conflicto SQL de unicidad/deadlock/espera, 422 payload o invariantes inválidas. No reintentar ciegamente: refrescar contexto. Los fallos inesperados de base se reportan internamente y retornan un mensaje genérico 500. Configurar APP_DEBUG=false en despliegues.

## Rutas

| Método | Ruta relativa | Payload / resultado |
|---|---|---|
| GET | /capabilities | Mapa de entradas funcionales, claves literales como `users.create` |
| GET | /roles | Catálogo autorizado: nivel, P, Q activo y capacidades |
| GET | /roles/manageable | Descubrimiento con roles.update, independiente del catálogo |
| POST | /roles | name, level, description opcional; sin guard, permisos o policy en este payload |
| PUT | /roles/{role} | name, level, description opcionales; no renombrar admin |
| PUT | /roles/{role}/permissions | `{"ids":[1,2]}`: reemplazar P |
| PUT | /roles/{role}/delegation-policy | `{"delegation_policy_id":3}` o null |
| GET | /permissions | Catálogo del guard; sólo lectura |
| GET | /policies | Catálogo autorizado y capacidades por política |
| GET | /policies/manageable | Lectura para gestores configurados, independiente del catálogo |
| GET | /policies/{policy} | Detalle autorizado |
| POST | /policies | name, slug; active=false por defecto, permissions=[IDs] opcional |
| PUT | /policies/{policy} | name y/o active; `{"active":false}` desactiva; slug y permissions no admitidos |
| PUT | /policies/{policy}/permissions | `{"ids":[1,2]}`: reemplazar Q almacenado |
| GET | /policies/{policy}/assignable-roles | Destinos de asociación; requiere política activa y gestor |
| GET | /users/{user}/authority | Roles, directos, heredados, efectivos y capacidades; sin atributos de cuenta |
| GET | /users/{user}/assignable-roles | Candidatos para agregar rol; user admite `new` |
| GET | /users/{user}/delegable-permissions | grantable y revocable; user admite `new` |
| PUT | /users/{user}/roles | `{"ids":[1,2]}`: reemplazo completo |
| PUT | /users/{user}/roles/{role} | Agregar sin perder membresías concurrentes |
| DELETE | /users/{user}/roles/{role} | Retirar conservando las demás |
| PUT | /users/{user}/permissions | `{"ids":[1,2]}`: reemplazo directo |
| PUT | /users/{user}/permissions/{permission} | Concesión directa incremental |
| DELETE | /users/{user}/permissions/{permission} | Revocación directa incremental |

Todos los IDs de autoridad son enteros positivos; [] es revocación explícita. La activación de una política vuelve a comprobar su contenido almacenado, consumidores y miembros. Ningún flag can_* enviado por el cliente participa en la autorización. Capacidades de metadatos no prometen que sea válido cualquier cambio de nombre o nivel.

Un operador puede acceder a candidatos sin tener permiso de catálogo. La información del usuario objetivo se limita además por su footprint y jerarquía. Las lecturas no constituyen un snapshot durable: una revocación posterior invalida una selección previamente mostrada.

La API no crea ni edita cuentas: esa capa pertenece al anfitrión y usa AuthorizedUserMutation. No incluye endpoints para administrar el catálogo de permisos, aprovisionar infraestructura ni recuperar administradores.

El anfitrión puede vincular `AdministrativeIdentity` para restringir identidades administrativas. Esas reglas se aplican igualmente por servicios PHP, reemplazos, incrementos y operaciones compuestas; el privilegio global no las omite. Los candidatos de roles también excluyen transferencias prohibidas. Un adaptador anfitrión puede impedir transferir, suspender o borrar una identidad reservada. Esas invariantes son explícitas y no constituyen valores predeterminados del paquete.
