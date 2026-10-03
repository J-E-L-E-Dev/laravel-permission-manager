# Análisis estático y CI

[English version](static-analysis.md)

PHPStan/Larastan son dependencias de desarrollo. `composer analyse` ejecuta nivel 5 sobre src, config, migraciones y rutas. No existe baseline generado ni supresión general de propiedades, argumentos o retornos inválidos. Larastan arranca Testbench sin aplicación anfitriona.

Los parámetros públicos de usuario conservan Eloquent Model. PHP no expresa una intersección con el trait HasRoles. Una excepción admite únicamente roles, permissions, syncRoles y syncPermissions sobre ese tipo Model exacto dentro de src. Models::user valida HasRoles en ejecución; las pruebas de configuración lo comprueban. Otros métodos/clases indefinidos siguen produciendo errores.

El pequeño stub RBAC describe el esquema extendido de Role y la relación HasDelegationPolicy requerida. No añade comportamiento; Models::role valida subclase/trait. Las relaciones asumen modelos Permission configurados y claves enteras. Colecciones/relaciones tienen genéricos explícitos. Los atributos anfitriones opcionales usan getAttribute. Las consultas escalares usan whereKey()->firstOrFail(), conservando identidad persistida sin la ambigüedad escalar/array de find.

La regla opcional de optimización de colecciones de Larastan está desactivada: modelKeys trabaja intencionalmente sobre modelos hidratados y sus casts/scopes. Esto no suprime errores de autorización/tipos. La validación y pruebas en ejecución siguen siendo necesarias; nivel 5 no demuestra corrección de todos los valores mixed o anulables.

CI comprueba Composer, resolución, pruebas, análisis, Pint y sintaxis en PHP 8.2/8.3/8.4, lowest/latest. Un servicio MariaDB 10.6 separado ejecuta el helper de bases descartables del paquete con credenciales de prueba. No lee configuración de aplicaciones ni bases persistentes. Consultar [estado de verificación](status.es.md) para distinguir configuración local de ejecución remota.

La configuración de análisis detecta si el stub MorphTo de Larastan ya declara BelongsTo. Un stub de compatibilidad se carga solo en versiones antiguas que carecen de esa declaración, evitando duplicados en versiones actuales. Ambas resoluciones, mínima y actual, tienen verificación de análisis.

DocumentationTest inspecciona métodos nombrados de src y database/migrations mediante tokens nativos, sin ejecutar las migraciones. Exige PHPDoc propio con descripción, cubre interfaces, traits, constructores y visibilidades, y excluye closures. También verifica parejas docs/*.md y ambos README. Las fixtures del detector comprueban atributos, referencias y rechazo de comentarios pertenecientes a otra declaración. La equivalencia técnica entre traducciones y la calidad del español requieren revisión editorial; la prueba no pretende detectar automáticamente el idioma.
