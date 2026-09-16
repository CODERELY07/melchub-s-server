<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    /**
     * Seed the roles and permissions used across the app.
     */
    public function run(): void
    {
        // DatabaseSeeder runs under WithoutModelEvents, which also silences the
        // model events Spatie relies on to auto-invalidate its permission cache.
        // The first findOrCreate() call below loads and caches the (still empty)
        // permission list, and — because those events are suppressed — nothing
        // invalidates it as each subsequent permission is created. So the cache
        // must be forgotten again right before syncPermissions() reads it, not
        // just once at the top.
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $permissions = [
            'view loans',
            'create loans',
            'edit loans',
            'delete loans',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission);
        }

        $registrar->forgetCachedPermissions();

        Role::findOrCreate('admin')->syncPermissions($permissions);
        Role::findOrCreate('user');
    }
}
