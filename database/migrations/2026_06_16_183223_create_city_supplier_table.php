<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Связь many-to-many: города отгрузки ↔ поставщики.
 * Один поставщик отгружает из многих городов, один город — у многих поставщиков.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('city_supplier', function (Blueprint $table) {
            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('city_id')->constrained()->cascadeOnDelete();

            // одна пара (поставщик, город) не должна дублироваться
            $table->primary(['supplier_id', 'city_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('city_supplier');
    }
};
