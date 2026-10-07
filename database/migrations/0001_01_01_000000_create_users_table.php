<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // パスワードは持たない。本番ログインは Google、開発はデモログインのみ。
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('email')->unique();
            $table->string('role', 20)->default('employee');
            $table->boolean('is_active')->default(true);
            $table->string('google_id')->nullable()->unique();
            // 役割変更・無効化で加算し、既存セッションを失効させる
            $table->unsignedInteger('session_version')->default(1);
            $table->unsignedInteger('revision')->default(1);
            $table->rememberToken();
            $table->timestamps();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('users');
    }
};
