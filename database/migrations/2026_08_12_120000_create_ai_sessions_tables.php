<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * AI-подбор: сессии чата покупателя + сообщения + последний structured query.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_sessions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('client_key', 64)->nullable()->index(); // anon fingerprint
            $table->json('structured_query')->nullable();
            $table->json('last_match_ids')->nullable(); // offer ids shortlist
            $table->string('status', 32)->default('active'); // active|handed_off|closed
            $table->string('handoff_contact')->nullable();
            $table->text('handoff_note')->nullable();
            $table->timestamp('handed_off_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ai_messages', function (Blueprint $table) {
            $table->id();
            $table->uuid('ai_session_id');
            $table->foreign('ai_session_id')->references('id')->on('ai_sessions')->cascadeOnDelete();
            $table->string('role', 16); // user|assistant|system
            $table->text('content');
            $table->json('meta')->nullable(); // structured_query, offer_ids, reasons, llm_used
            $table->timestamps();

            $table->index(['ai_session_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_sessions');
    }
};
