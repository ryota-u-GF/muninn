<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table) {
            // アプリを開いている間の、自動取得の間隔（分）
            $table->unsignedSmallInteger('sync_interval_minutes')->default(10)->after('auto_sync');
        });
    }

    public function down(): void
    {
        Schema::table('gmail_connections', function (Blueprint $table) {
            $table->dropColumn('sync_interval_minutes');
        });
    }
};
