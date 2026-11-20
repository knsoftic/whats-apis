<?php

namespace Database\Seeders;

use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        self::ensure();
    }

    /**
     * Cheap check used at runtime (registration): seed only when a role is missing.
     */
    public static function ensureRolesExist(): void
    {
        if (Role::where('guard_name', 'web')->count() < count(Permissions::roles())) {
            self::ensure();
        }
    }

    /**
     * Idempotent: creates missing roles/permissions and syncs role permissions.
     */
    public static function ensure(): void
    {
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        foreach (Permissions::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        foreach (Permissions::roleMap() as $roleName => $permissions) {
            Role::findOrCreate($roleName, 'web')->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
