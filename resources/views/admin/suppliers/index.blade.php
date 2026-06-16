@extends('admin.layout')

@section('title', 'Поставщики')

@section('content')
<div class="flex items-center justify-between mb-4">
    <h1 class="text-2xl font-bold">Поставщики</h1>
    <a href="{{ route('admin.suppliers.create') }}"
       class="bg-gray-900 text-white px-4 py-2 rounded hover:bg-gray-700">+ Добавить</a>
</div>

<form method="GET" class="mb-4">
    <input type="text" name="q" value="{{ $search }}" placeholder="Поиск по названию или ИНН…"
           class="border rounded px-3 py-2 w-full max-w-md">
</form>

<div class="bg-white rounded-lg shadow overflow-hidden">
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
                <tr>
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
                            <span class="text-green-700">да</span>
                        @else
                            <span class="text-gray-400">нет</span>
                        @endif
                    </td>
                    <td class="px-4 py-3 text-right whitespace-nowrap">
                        <a href="{{ route('admin.suppliers.edit', $supplier) }}"
                           class="text-blue-600 hover:underline">Изменить</a>
                        <form method="POST" action="{{ route('admin.suppliers.destroy', $supplier) }}"
                              class="inline" onsubmit="return confirm('Удалить поставщика?')">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-600 hover:underline ml-2">Удалить</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="6" class="px-4 py-6 text-center text-gray-500">Поставщиков пока нет.</td>
                </tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="mt-4">
    {{ $suppliers->links() }}
</div>
@endsection
