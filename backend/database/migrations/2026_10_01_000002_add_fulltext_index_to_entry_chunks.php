<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds the full-text half of the hybrid search index.
     *
     * This mirrors knowledge_entries_search_idx: chunk bodies are already plain
     * text, so the expression is a plain to_tsvector with no markup stripping.
     * The keyword is what lets the lexical side match exact identifiers such as
     * error codes or function names, which a vector search cannot do.
     *
     * Left untouched on other drivers, since the test suite runs on sqlite and
     * GIN expression indexes are Postgres only.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "CREATE INDEX entry_chunks_search_idx
                ON entry_chunks USING GIN (to_tsvector('english', body))"
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('DROP INDEX IF EXISTS entry_chunks_search_idx');
    }
};
