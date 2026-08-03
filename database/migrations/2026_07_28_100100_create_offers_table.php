<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Оффер (SKU) — карточка товара поставщика.
 * Общие коммерческие поля — колонки; тех. характеристики категории — specs JSON.
 * См. config/agora.php и xlsx «03 Общие поля» / «04 Тех поля».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offers', function (Blueprint $table) {
            $table->id();

            $table->foreignId('supplier_id')->constrained()->cascadeOnDelete();
            $table->foreignId('category_id')->constrained()->restrictOnDelete();

            // Общие поля (раздел 3)
            $table->string('offer_title');
            $table->decimal('price_value', 12, 2);
            $table->string('currency', 3)->default('RUB');
            $table->string('price_basis'); // шт, рулон, ...
            $table->unsignedInteger('moq_value');
            $table->string('stock_status');
            $table->unsignedSmallInteger('production_lead_days')->nullable();
            $table->unsignedSmallInteger('delivery_lead_days')->nullable();
            $table->json('delivery_regions'); // ["Москва", "МО"]
            $table->boolean('pickup_available')->default(false);
            $table->string('payment_terms');
            $table->string('vat_rate'); // 20 | 10 | 0 | Без НДС
            $table->boolean('branding_available')->default(false);
            $table->string('photo_path')->nullable();
            $table->text('description_short')->nullable();

            // Тех. поля категории (ключ → значение)
            $table->json('specs')->nullable();

            // Публикация на витрине
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['category_id', 'is_active']);
            $table->index(['supplier_id', 'is_active']);
            $table->index('stock_status');
            $table->index('price_value');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('offers');
    }
};
