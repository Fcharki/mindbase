<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false);

            // Pinned-first ordering is (is_pinned desc, created_at desc) per
            // workspace, which this pair serves directly.
            $table->index(['workspace_id', 'is_pinned']);
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'is_pinned']);
            $table->dropColumn('is_pinned');
        });
    }
};
