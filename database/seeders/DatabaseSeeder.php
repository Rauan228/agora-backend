<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Администратор для входа в админку
        User::updateOrCreate(
            ['email' => 'admin@agora.local'],
            [
                'name' => 'Admin',
                'password' => Hash::make('password'),
            ],
        );

        $this->call([
            SupplierSeeder::class,
        ]);
    }
}
