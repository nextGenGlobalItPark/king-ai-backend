<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Defines the role/permission catalog for Vidio King.
 *
 * Two roles:
 *   - admin: full access to everything (panel + all API endpoints).
 *   - user:  API-only, with read access to the catalog data and full
 *            access to their own generations and history.
 *
 * Both roles are registered against BOTH the 'web' guard (Filament panel)
 * and the 'sanctum' guard (API). spatie's HasRoles trait checks
 * permissions against the current guard, so we must duplicate.
 *
 * IDEMPOTENT: this seeder is safe to run multiple times. Each run:
 *   - Creates any new permissions that don't exist yet.
 *   - Re-syncs the role->permission mapping (so adding/removing a
 *     permission from a role here applies on next deploy).
 *   - Does NOT touch user->role assignments, so existing users keep
 *     their roles.
 *
 * To run: `php artisan db:seed --class=RolesAndPermissionsSeeder`
 * Or include in DatabaseSeeder.run() so `php artisan db:seed` picks it up.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Permissions assigned to the 'admin' role.
     * Admin gets every permission in the system — the role definition
     * is built dynamically by taking the union of all permission names
     * in this seeder, so adding a new permission anywhere automatically
     * grants it to admin.
     */
    private const ADMIN_PERMISSIONS = '*';

    /**
     * Permissions assigned to the 'user' role.
     */
    private const USER_PERMISSIONS = [
        // Catalog read access — users need this to know what they can generate
        'view-templates',
        'view-categories',
        'view-ai-features',
        'view-ai-feature-configs',
        'view-data-sources',

        // Generation
        'generate-content',
        'view-own-history',

        // Their own device tokens
        'manage-own-devices',
    ];

    /**
     * The full canonical list of permissions in the system.
     * Add new permissions here when extending the auth model.
     */
    private const ALL_PERMISSIONS = [
        // Filament panel
        'access-admin-panel',

        // User management (admin-only)
        'manage-users',
        'view-users',

        // Templates
        'view-templates',
        'manage-templates',

        // Categories
        'view-categories',
        'manage-categories',

        // AI Features
        'view-ai-features',
        'manage-ai-features',

        // AI Feature Configs
        'view-ai-feature-configs',
        'manage-ai-feature-configs',

        // Data Sources
        'view-data-sources',
        'manage-data-sources',

        // Vendor Status Mappings (admin-only catalog)
        'manage-task-status',

        // AI Generation
        'generate-content',
        'view-own-history',
        'view-all-history',

        // Devices
        'manage-own-devices',
    ];

    /**
     * Guards the package should register roles/permissions under.
     * Both are registered so a role check works regardless of which
     * guard authenticated the request.
     */
    private const GUARDS = ['web', 'sanctum'];

    public function run(): void
    {
        // Reset cached roles/permissions FIRST. Without this, fresh writes
        // are invisible to in-process permission checks for the next 24h.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        DB::transaction(function () {
            foreach (self::GUARDS as $guard) {
                // 1. Ensure all permissions exist for this guard.
                foreach (self::ALL_PERMISSIONS as $permName) {
                    Permission::firstOrCreate([
                        'name' => $permName,
                        'guard_name' => $guard,
                    ]);
                }

                // 2. Sync the admin role: ALL permissions.
                $adminRole = Role::firstOrCreate([
                    'name' => 'admin',
                    'guard_name' => $guard,
                ]);
                $adminRole->syncPermissions(
                    Permission::where('guard_name', $guard)->get()
                );

                // 3. Sync the user role: USER_PERMISSIONS only.
                $userRole = Role::firstOrCreate([
                    'name' => 'user',
                    'guard_name' => $guard,
                ]);
                $userRole->syncPermissions(
                    Permission::whereIn('name', self::USER_PERMISSIONS)
                        ->where('guard_name', $guard)
                        ->get()
                );
            }
        });

        // Final cache reset so next request sees the new state.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->command?->info('Roles + permissions seeded for guards: ' . implode(', ', self::GUARDS));
    }
}
