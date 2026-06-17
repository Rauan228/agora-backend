@extends('admin.layout')

@section('title', 'Поставщики')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">Поставщики</h1>
    <a href="{{ route('admin.suppliers.create') }}"
       class="bg-gray-900 text-white px-4 py-2 rounded-lg shadow-sm hover:bg-gray-700 hover:shadow-md active:scale-95 transition-all duration-150">
        + Добавить
    </a>
</div>

{{-- Панель поиска и фильтров --}}
<form method="GET" x-data="{ open: {{ ($city || $status) ? 'true' : 'false' }} }" class="bg-white rounded-lg shadow-sm p-4 mb-5">
    <div class="flex flex-col sm:flex-row gap-2">
        <div class="relative flex-1">
            <input type="text" name="q" value="{{ $search }}"
                   placeholder="Поиск по названию, ИНН, телефону, email…"
                   class="border rounded-lg pl-10 pr-3 py-2 w-full focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
            <svg class="w-5 h-5 text-gray-400 absolute left-3 top-2.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/>
            </svg>
        </div>

        <button type="submit"
                class="bg-gray-900 text-white px-5 py-2 rounded-lg hover:bg-gray-700 active:scale-95 transition-all duration-150">
            Искать
        </button>

        <button type="button" @click="open = !open"
                class="px-4 py-2 rounded-lg border hover:bg-gray-50 active:scale-95 transition-all duration-150 flex items-center gap-1">
            Фильтры
            <svg class="w-4 h-4 transition-transform duration-200" :class="open && 'rotate-180'"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7"/>
            </svg>
        </button>

        {{-- Кнопка «Сбросить» — видна только когда есть активные фильтры --}}
        @if ($hasFilters)
            <a href="{{ route('admin.suppliers.index') }}"
               class="px-4 py-2 rounded-lg border border-red-200 text-red-600 hover:bg-red-50 active:scale-95 transition-all duration-150 flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
                Сбросить
            </a>
        @endif
    </div>

    {{-- Расширенный фильтр (сворачиваемый, с плавной анимацией) --}}
    <div x-show="open" x-collapse x-cloak class="mt-4 pt-4 border-t grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-700">Город отгрузки</label>
            <select name="city" class="border rounded-lg px-3 py-2 w-full focus:ring-2 focus:ring-gray-900 outline-none transition">
                <option value="">— любой —</option>
                @foreach ($allCities as $c)
                    <option value="{{ $c }}" @selected($city === $c)>{{ $c }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1 text-gray-700">Статус</label>
            <select name="status" class="border rounded-lg px-3 py-2 w-full focus:ring-2 focus:ring-gray-900 outline-none transition">
                <option value="">— все —</option>
                <option value="active" @selected($status === 'active')>Только активные</option>
                <option value="inactive" @selected($status === 'inactive')>Только неактивные</option>
            </select>
        </div>
    </div>
</form>

{{-- Счётчик результатов --}}
<div class="text-sm text-gray-500 mb-2">
    Найдено: {{ $suppliers->total() }}
    @if ($hasFilters) <span class="text-gray-400">(с учётом фильтров)</span> @endif
</div>

<div class="bg-white rounded-lg shadow-sm overflow-hidden">
    <table class="w-full text-sm">
        <thead class="bg-gray-50 text-left text-gray-600">
            <tr>
                <th class="px-4 py-3">Логотип</th>
                <th class="px-4 py-3">Коммерческое название</th>
                <th class="px-4 py-3">ИНН</th>
                <th class="px-4 py-3">Города отгрузки</th>
                <th class="px-4 py-3">Активен</th>
                <th class="px-4 py-3 text-right">Действия</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($suppliers as $supplier)
                <tr class="hover:bg-gray-50 transition-colors duration-150">
                    <td class="px-4 py-3">
                        @if ($supplier->logo_url)
                            <img src="{{ $supplier->logo_url }}" alt="" class="h-10 w-10 object-contain">
                        @else
                            <span class="text-gray-400">—</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 font-medium">
                        {{ $supplier->commercial_name }}
                        <div class="text-gray-500 text-xs">{{ $supplier->legal_name }}</div>
                    </td>
                    <td class="px-4 py-3">{{ $supplier->inn }}</td>
                    <td class="px-4 py-3">{{ $supplier->cities->pluck('name')->implode(', ') ?: '—' }}</td>
                    <td class="px-4 py-3">
                        @if ($supplier->is_active)
                            <span class="inline-flex items-center gap-1 text-green-700 bg-green-50 px-2 py-0.5 rounded-full text-xs">● да</span>
                        @else
                            <span class="inline-flex items-center gap-1 text-gray-400 bg-gray-50 px-2 py-0.5 rounded-full text-xs">● нет</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('admin.suppliers.edit', $supplier) }}"
                           class="text-blue-600 hover:text-blue-800 hover:underline transition-colors">Изменить</a>
                        <form method="POST" action="{{ route('admin.suppliers.destroy', $supplier) }}"
                              class="inline" onsubmit="return confirm('Удалить поставщика?')">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600 hover:text-red-800 hover:underline ml-2 transition-colors">Удалить</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-10 text-center text-gray-500">
                        @if ($hasFilters)
                            По заданным фильтрам ничего не найдено.
                            <a href="{{ route('admin.suppliers.index') }}" class="text-blue-600 hover:underline">Сбросить фильтры</a>
                        @else
                            Поставщиков пока нет.
                        @endif
                    </td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $suppliers->links() }}
</div>
@endsection
