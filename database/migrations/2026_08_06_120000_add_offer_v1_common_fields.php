<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Общие V1-поля оффера из xlsx Стаса (лист 02), которых ещё не было.
 * specs категории по-прежнему в JSON offers.specs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->string('sku')->nullable()->after('offer_title');
            $table->string('supplier_product_code', 100)->nullable()->after('sku');
            $table->unsignedInteger('order_step')->default(1)->after('moq_value');
            $table->boolean('price_hidden')->default(false)->after('price_value');
            $table->boolean('custom_manufacturing')->default(false)->after('branding_available');

            $table->index('sku');
        });
    }

    public function down(): void
    {
        Schema::table('offers', function (Blueprint $table) {
            $table->dropIndex(['sku']);
            $table->dropColumn([
                'sku',
                'supplier_product_code',
                'order_step',
                'price_hidden',
                'custom_manufacturing',
            ]);
        });
    }
};
