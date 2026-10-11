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
        Schema::table('documents', function (Blueprint $table) {
            // Plain text pulled out of the uploaded file. Kept on the row rather
            // than re-parsed per reindex, since parsing a PDF is the expensive
            // half and an entry is reindexed on every edit.
            $table->text('extracted_text')->nullable()->after('checksum');

            // pending | ready | empty | unsupported | failed, so the UI and the
            // logs can tell "not parsed yet" apart from "nothing to parse".
            $table->string('extraction_status')->default('pending')->after('extracted_text');
            $table->string('extraction_error')->nullable()->after('extraction_status');
            $table->timestamp('extracted_at')->nullable()->after('extraction_error');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn([
                'extracted_text',
                'extraction_status',
                'extraction_error',
                'extracted_at',
            ]);
        });
    }
};
