<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\User;

class DemoUsersSeeder extends Seeder
{
    /**
     * Seed four additional demo users, all with password "Password@123".
     */
    public function run(): void
    {
        $users = [
            ['name' => 'Sana Park', 'email' => 'sana@example.com'],
            ['name' => 'Omar Haddad', 'email' => 'omar@example.com'],
            ['name' => 'Lina Chen', 'email' => 'lina@example.com'],
            ['name' => 'Marco Rossi', 'email' => 'marco@example.com'],
        ];

        foreach ($users as $user) {
            User::firstOrCreate(
                ['email' => $user['email']],
                [
                    'name' => $user['name'],
                    'password' => Hash::make('Password@123'),
                    'email_verified_at' => now(),
                ]
            );
        }
    }
}