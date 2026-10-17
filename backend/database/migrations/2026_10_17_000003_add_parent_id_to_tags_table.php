<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            // Null means a root tag. A parent must live in the same workspace,
            // enforced in the model rather than by a composite FK, because
            // (workspace_id, id) is not unique in this schema.
            $table->foreignId('parent_id')
                ->nullable()
                ->constrained('tags')
                ->nullOnDelete();

            $table->index(['workspace_id', 'parent_id']);
        });
    }

    public function down(): void
    {
        Schema::table('tags', function (Blueprint $table) {
            $table->dropForeign(['parent_id']);
            $table->dropIndex(['workspace_id', 'parent_id']);
            $table->dropColumn('parent_id');
        });
    }
};
