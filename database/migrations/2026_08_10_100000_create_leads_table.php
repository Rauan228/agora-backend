<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Лиды на обзвон (онбординг поставщиков).
 * Источник → карточка → статусы звонка → (опционально) supplier_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leads', function (Blueprint $table) {
            $table->id();

            $table->string('company_name');
            $table->string('phone', 50)->nullable();
            $table->string('phone_normalized', 32)->nullable()->index();
            $table->string('phone_extra', 50)->nullable();
            $table->string('email')->nullable();
            $table->string('website')->nullable();
            $table->string('city')->nullable();
            $table->string('region')->nullable()->index();
            $table->string('inn', 12)->nullable()->index();
            $table->string('contact_person')->nullable();

            // corrugated-boxes и т.д.
            $table->string('category_slug')->nullable()->index();

            // manual | csv | kontur | website | other
            $table->string('source', 32)->default('manual')->index();
            $table->string('source_url', 1000)->nullable();
            $table->string('source_query')->nullable();
            $table->string('external_id')->nullable()->index();

            // new | to_call | no_answer | callback | interested | sent_kp | onboarded | rejected | wrong_number | duplicate
            $table->string('call_status', 32)->default('new')->index();
            $table->text('notes')->nullable();
            $table->text('call_notes')->nullable();
            $table->timestamp('last_called_at')->nullable();
            $table->timestamp('next_call_at')->nullable();

            $table->foreignId('supplier_id')->nullable()->constrained()->nullOnDelete();
            $table->json('meta')->nullable();

            $table->timestamps();

            $table->index(['call_status', 'region']);
            $table->index(['source', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('leads');
    }
};
