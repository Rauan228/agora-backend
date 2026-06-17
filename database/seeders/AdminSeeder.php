<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Создаёт только администратора (без тестовых поставщиков).
 * Данные берутся из ENV, чтобы не хранить прод-пароль в коде:
 *   ADMIN_EMAIL, ADMIN_PASSWORD (на Railway — в Variables).
 * Идемпотентен: повторный запуск обновляет пароль, не плодит юзеров.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = env('ADMIN_EMAIL', 'admin@agora.local');
        $password = env('ADMIN_PASSWORD', 'password');

        User::updateOrCreate(
            ['email' => $email],
            [
                'name' => 'Admin',
                'password' => Hash::make($password),
            ],
        );
    }
}
