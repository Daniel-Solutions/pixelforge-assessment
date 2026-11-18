<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Idempotent: safe to run on every deploy. Accounts are created only when their password is configured.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $admin = config('pixelforge.admin');

        if (is_string($admin['email']) && is_string($admin['password']) && $admin['password'] !== '') {
            $this->account($admin['email'], (string) $admin['name'], $admin['password'], isAdmin: true);
        }

        $demo = config('pixelforge.demo');

        if (is_string($demo['email']) && is_string($demo['password']) && $demo['password'] !== '') {
            $this->account($demo['email'], 'Demo User', $demo['password'], isAdmin: false);
        }
    }

    private function account(string $email, string $name, string $password, bool $isAdmin): void
    {
        $user = User::query()->firstOrNew(['email' => $email]);

        if (! $user->exists) {
            $user->fill(['name' => $name, 'password' => $password, 'email_verified_at' => now()]);
        }

        $user->is_admin = $isAdmin;
        $user->save();
    }
}
