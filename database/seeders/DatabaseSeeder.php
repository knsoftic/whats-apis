<?php

namespace Database\Seeders;

use App\Models\User;
use App\Support\Permissions;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class DatabaseSeeder extends Seeder
{
    /**
     * Creates roles/permissions and the first Super Admin.
     *
     * Credentials come from .env (ADMIN_EMAIL / ADMIN_PASSWORD). If no password
     * is set, a random one is generated and printed once in the console.
     */
    public function run(): void
    {
        $this->call(RolesAndPermissionsSeeder::class);

        $config = config('app.seed_admin');
        $email = $config['email'];
        $password = $config['password'] ?: Str::password(16, symbols: false);

        $admin = User::firstOrNew(['email' => $email]);

        if (! $admin->exists) {
            $admin->fill([
                'name' => $config['name'],
                'password' => $password,
                'is_active' => true,
            ]);
            $admin->email_verified_at = now();
            $admin->save();

            $this->command?->warn("Super Admin created: {$email}");

            if (! $config['password']) {
                $this->command?->warn("Generated password (shown once, change it after login): {$password}");
            }
        }

        $admin->syncRoles([Permissions::ROLE_SUPER_ADMIN]);
    }
}
