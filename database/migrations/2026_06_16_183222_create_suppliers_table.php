<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Поставщики (компании).
 *
 * Поля заданы аналитиком. Сопоставление «формулировка аналитика → колонка»:
 *   Коммерческое название компании   → commercial_name
 *   Юридическое название компании    → legal_name
 *   ИНН                              → inn (уникальный)
 *   Фактический адрес регистрации    → legal_address
 *   Города отгрузки                  → many-to-many через city_supplier (не здесь)
 *   Логотип                          → logo_path (путь к загруженному файлу)
 *   Контактные данные                → contact_person / phone / email / website
 *
 * Контакты намеренно разбиты на отдельные поля (а не одна строка),
 * чтобы по ним можно было искать и валидировать. Когда аналитик уточнит
 * состав контактов — добавим/уберём поля отдельной миграцией.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table) {
            $table->id();

            // Названия компании
            $table->string('commercial_name');              // коммерческое название
            $table->string('legal_name')->nullable();       // юридическое название

            // Реквизиты
            $table->string('inn', 12)->unique();            // ИНН: 10 цифр (юрлицо) или 12 (ИП)
            $table->string('legal_address')->nullable();    // фактический адрес регистрации

            // Логотип — храним относительный путь к файлу в storage
            $table->string('logo_path')->nullable();

            // Контактные данные (разбиты на поля)
            $table->string('contact_person')->nullable();   // контактное лицо
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();

            // Признак публикации на фронте (черновик/опубликован)
            $table->boolean('is_active')->default(true);

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
