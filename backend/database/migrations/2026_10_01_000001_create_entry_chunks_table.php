<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $driver = DB::connection()->getDriverName();

        // PostgreSQL is told to enable the vector extension.
        if ($driver === 'pgsql') {
            DB::statement('CREATE EXTENSION IF NOT EXISTS vector');
        }

        Schema::create('entry_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('knowledge_entry_id')->constrained()->onDelete('cascade');

            // raw content chunks carry the detail the summary dropped
            $table->string('source', 16);

            $table->unsignedInteger('position');

            // Fingerprint of the entry revision these chunks came from, so a
            // late job cannot leave a mixture of two revisions behind
            $table->char('content_hash', 64);

            $table->text('body');

            $table->timestamps();

            $table->unique(['knowledge_entry_id', 'source', 'position']);
            $table->index(['knowledge_entry_id', 'source']);
        });

        // table gets an embedding column that stores vectors.
        Schema::table('entry_chunks', function (Blueprint $table) use ($driver) {
            if ($driver === 'pgsql') {
                $table->vector('embedding', config('ai.embedding_dimension'));
            } else {
                $table->text('embedding');
            }
        });

        // A vector index is created so similarity searches are fast.
        if ($driver === 'pgsql') {
            Schema::table('entry_chunks', function (Blueprint $table) {
                $table->vectorIndex('embedding');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('entry_chunks');
    }
};
