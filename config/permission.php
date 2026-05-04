<?php

/**
 * spatie/laravel-permission configuration.
 *
 * This is the stock config from the package, inlined so it can be
 * version-controlled. The migration in
 * 2026_05_04_000006_create_permission_tables.php reads from this file,
 * so the two must be consistent.
 *
 * Notes specific to this project:
 *   - 'teams' is false. We don't have multi-tenancy.
 *   - 'guard_name' for our use is 'web' (Filament) and 'sanctum'
 *     (API). We register the same role/permission names against both
 *     guards in the seeder, so checks work uniformly.
 */
return [

    'models' => [
        'permission' => Spatie\Permission\Models\Permission::class,
        'role' => Spatie\Permission\Models\Role::class,
    ],

    'table_names' => [
        'roles' => 'roles',
        'permissions' => 'permissions',
        'model_has_permissions' => 'model_has_permissions',
        'model_has_roles' => 'model_has_roles',
        'role_has_permissions' => 'role_has_permissions',
    ],

    'column_names' => [
        'role_pivot_key' => null,
        'permission_pivot_key' => null,
        'model_morph_key' => 'model_id',
        'team_foreign_key' => 'team_id',
    ],

    /*
     * Multi-tenant permissions. Off — we have a single-tenant app.
     */
    'teams' => false,

    /*
     * The class to dispatch on the role/permission events. Off by default.
     */
    'use_passport_client_credentials' => false,

    'display_permission_in_exception' => false,
    'display_role_in_exception' => false,

    'enable_wildcard_permission' => false,

    'cache' => [
        /*
         * 24 hours by default. The cache is automatically flushed when roles
         * or permissions are updated.
         */
        'expiration_time' => DateInterval::createFromDateString('24 hours'),

        'key' => 'spatie.permission.cache',

        'store' => 'default',
    ],
];
