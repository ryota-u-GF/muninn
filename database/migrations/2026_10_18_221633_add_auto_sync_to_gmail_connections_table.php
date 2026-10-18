<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table) {
            // アプリを開いたときに、自動で新着メールを取り込む
            $table->boolean('auto_sync')->default(true)->after('initial_days');
        });
    }

    public function down(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table) {
            $table->dropColumn('auto_sync');
        });
    }
};
