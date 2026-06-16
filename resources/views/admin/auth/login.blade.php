@extends('admin.layout')

@section('title', 'Вход')

@section('content')
<div class="max-w-sm mx-auto mt-16 bg-white rounded-lg shadow p-6">
    <h1 class="text-xl font-bold mb-4 text-center">Вход в админку</h1>

    @if ($errors->any())
        <div class="mb-4 rounded bg-red-100 border border-red-300 text-red-800 px-4 py-2 text-sm">
            {{ $errors->first() }}
        </div>
    @endif

    <form method="POST" action="{{ route('admin.login.attempt') }}" class="space-y-4">
        @csrf
        <div>
            <label class="block text-sm font-medium mb-1">Email</label>
            <input type="email" name="email" value="{{ old('email') }}" required autofocus
                   class="w-full border rounded px-3 py-2">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Пароль</label>
            <input type="password" name="password" required
                   class="w-full border rounded px-3 py-2">
        </div>
        <label class="flex items-center gap-2 text-sm">
            <input type="checkbox" name="remember" value="1"> Запомнить меня
        </label>
        <button class="w-full bg-gray-900 text-white rounded py-2 hover:bg-gray-700">Войти</button>
    </form>
</div>
@endsection
