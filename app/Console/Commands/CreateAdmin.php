<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

#[Signature('app:create-admin {email?} {password?} {--name=Admin}')]
#[Description('Создаёт или обновляет пользователя-администратора для входа в админку')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = $this->argument('email') ?: $this->ask('Email администратора');
        $password = $this->argument('password') ?: $this->secret('Пароль');
        $name = $this->option('name');

        $validator = Validator::make(
            compact('email', 'password'),
            [
                'email' => ['required', 'email'],
                'password' => ['required', 'string', 'min:8'],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }
            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        $this->info("Администратор {$user->email} готов. Можно входить в /admin.");

        return self::SUCCESS;
    }
}
