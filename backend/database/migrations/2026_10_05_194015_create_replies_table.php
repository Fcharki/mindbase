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
        Schema::create('replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained()->cascadeOnDelete();

            // Same reasoning as comments.user_id: an author is kept for display
            // and is not required for the reply to remain readable.
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();

            $table->text('body');

            $table->timestamps();

            // Replies are read oldest-first within one comment. Timestamps have
            // second precision, so two replies written in the same second would
            // otherwise come back in an arbitrary order — the listings tiebreak
            // on id, which is monotonic and reflects insertion order.
            $table->index(['comment_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('replies');
    }
};
