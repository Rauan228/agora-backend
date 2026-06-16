<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Админка') — Agora</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 text-gray-900 min-h-screen">
    @auth
        <nav class="bg-gray-900 text-white">
            <div class="max-w-6xl mx-auto px-4 py-3 flex items-center justify-between">
                <a href="{{ route('admin.suppliers.index') }}" class="font-bold text-lg">Agora · Админка</a>
                <div class="flex items-center gap-4 text-sm">
                    <a href="{{ route('admin.suppliers.index') }}" class="hover:underline">Поставщики</a>
                    <span class="text-gray-400">{{ auth()->user()->email }}</span>
                    <form method="POST" action="{{ route('admin.logout') }}">
                        @csrf
                        <button class="bg-gray-700 hover:bg-gray-600 px-3 py-1 rounded">Выйти</button>
                    </form>
                </div>
            </div>
        </nav>
    @endauth

    <main class="max-w-6xl mx-auto px-4 py-6">
        @if (session('status'))
            <div class="mb-4 rounded bg-green-100 border border-green-300 text-green-800 px-4 py-3">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </main>
</body>
</html>
