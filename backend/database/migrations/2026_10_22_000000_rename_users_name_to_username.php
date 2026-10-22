<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('name', 'username');
        });

        $used = [];

        DB::table('users')
            ->orderBy('id')
            ->get(['id', 'username'])
            ->each(function (object $user) use (&$used): void {
                $base = trim(substr(Str::slug((string) $user->username, '-'), 0, 26), '-');

                if ($base === '') {
                    $base = 'user';
                }

                if (strlen($base) < 3) {
                    $base = 'user-'.$user->id;
                }

                $candidate = $base;
                $suffix = 2;

                while (isset($used[$candidate])) {
                    $suffixText = '-'.$suffix++;
                    $candidate = substr($base, 0, 30 - strlen($suffixText)).$suffixText;
                }

                $used[$candidate] = true;

                DB::table('users')
                    ->where('id', $user->id)
                    ->update(['username' => $candidate]);
            });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('username');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->renameColumn('username', 'name');
        });
    }
};
