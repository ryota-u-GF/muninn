<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 利用者本人の Gmail 連携。更新トークンは暗号化して保存する（モデル側の encrypted キャスト）。
        Schema::create('gmail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('google_email', 255);
            $table->text('refresh_token');
            // 取り込み対象：inbox（受信箱）／label（指定ラベル）
            $table->string('scope_mode', 10)->default('inbox');
            $table->string('label_name', 100)->nullable();
            $table->boolean('exclude_promotions')->default(true);
            $table->unsignedSmallInteger('initial_days')->default(3);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gmail_connections');
    }
};
