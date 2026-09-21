<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Adds Postgres GIN expression indexes backing the full-text search used
     * by the SearchService. Title + content are searched together; HTML from
     * the rich text editor is stripped before tokenisation so markup does not
     * pollute the index. Non-Postgres drivers (e.g. sqlite in tests) are left
     * untouched since ExpressionalIndexes are not supported there.
     */
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            "CREATE INDEX knowledge_entries_search_idx
                ON knowledge_entries USING GIN (
                    to_tsvector(
                        'english',
                        regexp_replace(
                            coalesce(title, '') || ' ' || coalesce(content, ''),
                            '<[^>]+>',
                            ' ',
                            'g'
                        )
                    )
                )"
        );

        DB::statement(
            "CREATE INDEX tags_search_idx
                ON tags USING GIN (to_tsvector('english', name))"
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

        DB::statement('DROP INDEX IF EXISTS knowledge_entries_search_idx');
        DB::statement('DROP INDEX IF EXISTS tags_search_idx');
    }
};
