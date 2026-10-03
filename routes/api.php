<?php

use Ahinest\LaravelPermissionManager\Http\AuthorityController as C;
use Ahinest\LaravelPermissionManager\Http\RequireActor;
use Illuminate\Support\Facades\Route;

Route::prefix(config('permission-manager.http.prefix'))->middleware([...config('permission-manager.http.middleware', ['auth']), RequireActor::class])->group(function () {
    Route::get('capabilities', [C::class, 'capabilities']);
    Route::get('roles', [C::class, 'roles']);
    Route::get('roles/manageable', [C::class, 'manageableRoles']);
    Route::post('roles', [C::class, 'createRole']);
    Route::put('roles/{role}', [C::class, 'updateRole'])->whereNumber('role');
    Route::put('roles/{role}/permissions', [C::class, 'rolePermissions'])->whereNumber('role');
    Route::put('roles/{role}/delegation-policy', [C::class, 'assignPolicy'])->whereNumber('role');
    Route::get('permissions', [C::class, 'permissions']);
    Route::get('policies', [C::class, 'policies']);
    Route::get('policies/manageable', [C::class, 'manageablePolicies']);
    Route::post('policies', [C::class, 'createPolicy']);
    Route::get('policies/{policy}', [C::class, 'policy'])->whereNumber('policy');
    Route::put('policies/{policy}', [C::class, 'updatePolicy'])->whereNumber('policy');
    Route::put('policies/{policy}/permissions', [C::class, 'policyPermissions'])->whereNumber('policy');
    Route::get('policies/{policy}/assignable-roles', [C::class, 'assignablePolicyRoles'])->whereNumber('policy');
    Route::get('users/{user}/authority', [C::class, 'userAuthority']);
    Route::get('users/{user}/assignable-roles', [C::class, 'assignableRoles']);
    Route::get('users/{user}/delegable-permissions', [C::class, 'delegablePermissions']);
    Route::put('users/{user}/roles', [C::class, 'replaceRoles']);
    Route::put('users/{user}/permissions', [C::class, 'replacePermissions']);
    Route::put('users/{user}/roles/{role}', [C::class, 'assignRole'])->whereNumber('role');
    Route::delete('users/{user}/roles/{role}', [C::class, 'removeRole'])->whereNumber('role');
    Route::put('users/{user}/permissions/{permission}', [C::class, 'grantPermission'])->whereNumber('permission');
    Route::delete('users/{user}/permissions/{permission}', [C::class, 'revokePermission'])->whereNumber('permission');
});
