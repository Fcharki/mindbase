<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('entry_images', function (Blueprint $table) {
            // OCR text, indexed alongside the entry's own content.
            $table->text('extracted_text')->nullable()->after('checksum');
            $table->string('extraction_status')->default('pending')->after('extracted_text');
            $table->text('extraction_error')->nullable()->after('extraction_status');
            $table->timestamp('extracted_at')->nullable()->after('extraction_error');
        });
    }

    public function down(): void
    {
        Schema::table('entry_images', function (Blueprint $table) {
            $table->dropColumn([
                'extracted_text',
                'extraction_status',
                'extraction_error',
                'extracted_at',
            ]);
        });
    }
};
