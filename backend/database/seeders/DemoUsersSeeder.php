<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DemoUsersSeeder extends Seeder
{
    /**
     * Seed four additional demo users, all with password "Password@123".
     */
    public function run(): void
    {
        $users = [
            ['username' => 'sana-park', 'email' => 'sana@example.com'],
            ['username' => 'omar-haddad', 'email' => 'omar@example.com'],
            ['username' => 'lina-chen', 'email' => 'lina@example.com'],
            ['username' => 'marco-rossi', 'email' => 'marco@example.com'],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                [
                    'username' => $user['username'],
                    'password' => Hash::make('Password@123'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}
