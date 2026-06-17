@csrf

@if ($errors->any())
    <div class="mb-4 rounded bg-red-100 border border-red-300 text-red-800 px-4 py-3">
        <ul class="list-disc list-inside text-sm">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    </div>
@endif

<div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div>
        <label class="block text-sm font-medium mb-1">Коммерческое название *</label>
        <input type="text" name="commercial_name" required
               value="{{ old('commercial_name', $supplier->commercial_name) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Юридическое название</label>
        <input type="text" name="legal_name"
               value="{{ old('legal_name', $supplier->legal_name) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">ИНН *</label>
        <input type="text" name="inn" required inputmode="numeric"
               value="{{ old('inn', $supplier->inn) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
        <p class="text-xs text-gray-500 mt-1">10 цифр (юрлицо) или 12 (ИП)</p>
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Фактический адрес регистрации</label>
        <input type="text" name="legal_address"
               value="{{ old('legal_address', $supplier->legal_address) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Города отгрузки</label>
        <input type="text" name="cities_csv" id="cities_csv"
               value="{{ old('cities_csv', implode(', ', $selectedCities ?? [])) }}"
               placeholder="Москва, Санкт-Петербург, Казань"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
        <p class="text-xs text-gray-500 mt-1">Через запятую. Новые города создадутся автоматически.</p>
        {{-- hidden-поля cities[] заполняются из строки перед отправкой --}}
        <div id="cities_hidden"></div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Контактное лицо</label>
        <input type="text" name="contact_person"
               value="{{ old('contact_person', $supplier->contact_person) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Телефон</label>
        <input type="text" name="phone"
               value="{{ old('phone', $supplier->phone) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email"
               value="{{ old('email', $supplier->email) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Сайт</label>
        <input type="url" name="website" placeholder="https://…"
               value="{{ old('website', $supplier->website) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Telegram</label>
        <input type="text" name="telegram" placeholder="@username или ссылка"
               value="{{ old('telegram', $supplier->telegram) }}"
               class="w-full border rounded-lg px-3 py-2 focus:ring-2 focus:ring-gray-900 focus:border-gray-900 outline-none transition">
    </div>

    <div class="md:col-span-2">
        <label class="block text-sm font-medium mb-1">Логотип</label>
        @if ($supplier->logo_url)
            <img src="{{ $supplier->logo_url }}" alt="" class="h-16 mb-2 object-contain">
        @endif
        <input type="file" name="logo" accept="image/*"
               class="w-full border rounded px-3 py-2 bg-white">
        <p class="text-xs text-gray-500 mt-1">PNG, JPG, SVG, WEBP до 2 МБ. Оставьте пустым, чтобы не менять.</p>
    </div>

    <div class="md:col-span-2">
        <label class="flex items-center gap-2 text-sm">
            <input type="hidden" name="is_active" value="0">
            <input type="checkbox" name="is_active" value="1"
                   {{ old('is_active', $supplier->is_active ?? true) ? 'checked' : '' }}>
            Активен (показывать на фронте)
        </label>
    </div>
</div>

<div class="mt-6 flex gap-3">
    <button class="bg-gray-900 text-white px-5 py-2 rounded-lg shadow-sm hover:bg-gray-700 hover:shadow-md active:scale-95 transition-all duration-150">Сохранить</button>
    <a href="{{ route('admin.suppliers.index') }}" class="px-5 py-2 rounded-lg border hover:bg-gray-50 active:scale-95 transition-all duration-150">Отмена</a>
</div>

<script>
    // Превращаем строку "город, город" в массив cities[] перед отправкой формы
    document.currentScript.closest('form').addEventListener('submit', function () {
        var box = document.getElementById('cities_hidden');
        box.innerHTML = '';
        var raw = document.getElementById('cities_csv').value;
        raw.split(',').map(function (s) { return s.trim(); }).filter(Boolean).forEach(function (city) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = 'cities[]';
            input.value = city;
            box.appendChild(input);
        });
    });
</script>
