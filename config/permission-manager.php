<?php

return [
    'guard' => 'web',
    // Null deniega administración global hasta que el anfitrión configure la identidad explícita.
    'admin_role' => null,
    'user_model' => null,
    // Null usa permission.models.role; la clase debe incorporar HasDelegationPolicy.
    'role_model' => null,
    'levels' => ['min' => 1, 'max' => 100, 'allow_same_owned_role' => false],
    'reserved_role_names' => [],
    'policy_manager_roles' => [],
    // Entrada funcional adicional opcional para gestores; identidad y D siguen siendo obligatorios.
    'policy_management_ability' => null,
    'sensitive_role_names' => [],
    // Nombres de permisos definidos por el anfitrión, no un catálogo de permisos.
    'abilities' => [
        'roles.view' => [],
        'permissions.view' => [],
        'policies.view' => [],
        'users.view' => [],
        'users.create' => [],
        'users.update' => [],
        'users.suspend' => [],
        'users.reactivate' => [],
        'users.delete' => [],
        'roles.create' => [],
        'roles.update' => [],
        'roles.permissions' => [],
        'users.roles' => [],
        'users.permissions' => [],
        'accounts.control' => [],
    ],
    'http' => ['enabled' => false, 'prefix' => 'api/v1/permission-manager', 'middleware' => ['auth']],
    // Roles existentes que recibirán políticas estructurales vacías; no ejecutar al arrancar.
    'provisioning' => [],
    // Tablas anfitrionas adicionales que participan en la transacción: auditoría, sesiones y tokens.
    'transaction_tables' => [],
    // Los eventos están disponibles; la persistencia obligatoria requiere un adaptador anfitrión.
    'audit' => ['required' => false],
];
