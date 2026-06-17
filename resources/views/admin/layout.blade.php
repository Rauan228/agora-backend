<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Админка') — Agora</title>
    <script src="https://cdn.tailwindcss.com"></script>
    {{-- Alpine.js + плагин collapse для плавных сворачиваний --}}
    <script defer src="https://cdn.jsdelivr.net/npm/@alpinejs/collapse@3.x.x/dist/cdn.min.js"></script>
    <script defer src="https://cdn.jsdelivr.net/npm/alpinejs@3.x.x/dist/cdn.min.js"></script>
    <style>
        [x-cloak] { display: none !important; }
        /* Плавное появление flash-сообщений и контента */
        @keyframes fadeInUp { from { opacity: 0; transform: translateY(6px); } to { opacity: 1; transform: translateY(0); } }
        .animate-fade-in-up { animation: fadeInUp .25s ease-out; }
    </style>
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
            <div x-data="{ show: true }" x-show="show" x-init="setTimeout(() => show = false, 4000)"
                 x-transition.opacity.duration.500ms
                 class="mb-4 rounded-lg bg-green-100 border border-green-300 text-green-800 px-4 py-3 flex items-center justify-between animate-fade-in-up">
                <span>{{ session('status') }}</span>
                <button @click="show = false" class="text-green-600 hover:text-green-900 transition-colors">✕</button>
            </div>
        @endif

        <div class="animate-fade-in-up">
            @yield('content')
        </div>
    </main>
</body>
</html>
