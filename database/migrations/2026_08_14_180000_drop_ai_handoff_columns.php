<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('ai_sessions')) {
            DB::table('ai_sessions')->where('status', 'handed_off')->update(['status' => 'closed']);
        }

        $drop = array_values(array_filter([
            Schema::hasColumn('ai_sessions', 'handoff_contact') ? 'handoff_contact' : null,
            Schema::hasColumn('ai_sessions', 'handoff_note') ? 'handoff_note' : null,
            Schema::hasColumn('ai_sessions', 'handed_off_at') ? 'handed_off_at' : null,
        ]));
        if ($drop !== []) {
            Schema::table('ai_sessions', function (Blueprint $table) use ($drop) {
                $table->dropColumn($drop);
            });
        }
    }

    public function down(): void
    {
        Schema::table('ai_sessions', function (Blueprint $table) {
            $table->string('handoff_contact')->nullable();
            $table->text('handoff_note')->nullable();
            $table->timestamp('handed_off_at')->nullable();
        });
    }
};
