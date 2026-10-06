<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')->constrained()->cascadeOnDelete();

            // Nullable rather than required: the author is kept for display, so
            // deleting a user must not take their comments down with them and
            // leave a discussion with holes in it. The resource renders these as
            // "Deleted user".
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            // The discussion is read oldest-first and always scoped to one entry,
            // so the listing order is the only query this index has to serve.
            $table->index(['knowledge_entry_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
