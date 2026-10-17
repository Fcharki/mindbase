<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            // 'up', 'down' or null (no opinion yet). Kept on the message so a
            // rating always belongs to the exact answer that was rated.
            $table->string('feedback', 8)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropColumn('feedback');
        });
    }
};
