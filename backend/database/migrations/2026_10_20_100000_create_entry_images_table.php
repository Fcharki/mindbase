<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('entry_images', function (Blueprint $table) {
            $table->id();

            // Public route key. Sequential ids would make every uploaded image
            // enumerable, while the UUID is what the content HTML references.
            $table->uuid('uuid')->unique();

            $table->foreignId('workspace_id')
                ->constrained()
                ->cascadeOnDelete();

            // Set when the entry that references the image is saved. Nullable
            // because an upload can happen before a new entry exists.
            $table->foreignId('knowledge_entry_id')
                ->nullable()
                ->constrained()
                ->cascadeOnDelete();

            $table->foreignId('uploaded_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->string('disk');
            $table->string('path');
            $table->string('original_name');
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->string('checksum', 64);

            $table->timestamps();

            $table->index(['workspace_id', 'knowledge_entry_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('entry_images');
    }
};
