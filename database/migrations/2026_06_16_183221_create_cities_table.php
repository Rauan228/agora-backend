<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Справочник городов отгрузки.
 *
 * Города отгрузки у поставщика — это список (одна компания отгружает
 * из нескольких городов), поэтому выносим их в отдельную таблицу-справочник
 * и связываем many-to-many через city_supplier. Так можно искать
 * поставщиков по городу и не хранить города строкой через запятую.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();   // название города
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cities');
    }
};
