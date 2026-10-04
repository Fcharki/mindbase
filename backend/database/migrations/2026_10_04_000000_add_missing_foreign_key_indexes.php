<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Indexes the referencing side of every foreign key that no existing index
 * happens to lead with.
 *
 * Laravel's foreignId()->constrained() emits only a FOREIGN KEY clause.
 * Postgres does not index the referencing side of a foreign key, and neither
 * does Laravel, so a column is covered only when some other index already leads
 * with it. The composite uniques on workspace_id all do, which is why they never
 * showed up as slow; these are the columns nothing covered.
 *
 * Three of these are on hot paths:
 *
 *   knowledge_entries.workspace_id   every list, every search, every nested
 *                                    route
 *   workspace_members.user_id        User::workspaces(), which every policy
 *                                    gate calls to check membership
 *   knowledge_entry_tag.tag_id       Tag::entries() and the withCount('entries')
 *                                    in tag search
 *
 * Unindexed at demo scale and a sequential scan per request once the corpus
 * grows, at which point the cost gets blamed on the ORM rather than the schema.
 */
return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Deliberately one composite rather than workspace_id plus
        // (workspace_id, created_at). A composite serves lookups on its leading
        // column too, so a separate single-column index here would be a second
        // copy of the same left-hand prefix with nothing to show for it. This
        // also lets KnowledgeEntryController's list ordering be answered by the
        // index instead of a sort.
        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->index(['workspace_id', 'created_at']);
            $table->index('created_by');
        });

        // The pivot's primary key leads with knowledge_entry_id, so nothing
        // covers the reverse direction.
        Schema::table('knowledge_entry_tag', function (Blueprint $table) {
            $table->index('tag_id');
        });

        // The unique on this table leads with workspace_id, so it cannot serve
        // the membership lookups that run as user_id = ? on every policy check.
        Schema::table('workspace_members', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('membership_requests', function (Blueprint $table) {
            $table->index('user_id');
        });

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->index('user_id');
        });

        // Nothing queries documents by uploader today. Indexed anyway so the
        // rule "every foreign key column is indexed" holds uniformly and stays
        // true as code is added.
        Schema::table('documents', function (Blueprint $table) {
            $table->index('uploaded_by');
        });
    }

    /**
     * Reverse the migrations.
     *
     * These are plain CREATE INDEX rather than CREATE INDEX CONCURRENTLY,
     * because concurrent index builds cannot run inside the transaction that
     * wraps a migration, and a build interrupted part way leaves an invalid
     * index behind that has to be dropped by hand. That trade is the right one
     * at this size and the wrong one for a table under real write load, so if
     * any of these grow large, set $withinTransaction = false, drop the
     * Schema::table wrappers in favour of raw CREATE INDEX CONCURRENTLY, and
     * accept the invalid-index cleanup that comes with it.
     */
    public function down(): void
    {
        // Single columns are passed as an array on purpose. dropIndex() treats
        // a bare string as the index name verbatim, so dropIndex('created_by')
        // emits DROP INDEX "created_by" against an index that is actually
        // called knowledge_entries_created_by_index. The array form derives the
        // conventional name that index() generated in up().

        Schema::table('knowledge_entries', function (Blueprint $table) {
            $table->dropIndex(['workspace_id', 'created_at']);
            $table->dropIndex(['created_by']);
        });

        Schema::table('knowledge_entry_tag', function (Blueprint $table) {
            $table->dropIndex(['tag_id']);
        });

        Schema::table('workspace_members', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('membership_requests', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropIndex(['user_id']);
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropIndex(['uploaded_by']);
        });
    }
};
