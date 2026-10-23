<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 管理者が設定した利用者だけがパスワードで入れる。未設定（null）の利用者は Google／デモのみ。
        Schema::table('users', function (Blueprint $table) {
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password');
        });
    }
};
