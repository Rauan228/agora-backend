<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Session-level LLM cost totals (admin meter only). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->unsignedInteger('tokens_in')->default(0)->after('handed_off_at');
            $table->unsignedInteger('tokens_out')->default(0)->after('tokens_in');
            $table->decimal('cost_usd', 12, 8)->default(0)->after('tokens_out');
            $table->unsignedInteger('llm_calls')->default(0)->after('cost_usd');
            $table->json('cost_summary')->nullable()->after('llm_calls');
        });
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->dropColumn(['tokens_in', 'tokens_out', 'cost_usd', 'llm_calls', 'cost_summary']);
        });
    }
};
