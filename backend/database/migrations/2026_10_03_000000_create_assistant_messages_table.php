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
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();

            $table->text('question');

            // Null until the job finishes, which is the normal state for most of a
            // question's life here.
            $table->longText('answer')->nullable();

            // Kept with the answer: re-running retrieval at read time would cite
            // whatever is indexed now, not what the model saw.
            $table->json('sources')->nullable();

            // Without this a polling client cannot tell "still working" from
            // "gave up", and a failure looks like an answer that never arrived.
            $table->string('status', 16)->default('pending');

            // Only on failure, so the client can show why.
            $table->string('error', 255)->nullable();

            $table->timestamps();

            // History is always read newest first.
            $table->index(['workspace_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
