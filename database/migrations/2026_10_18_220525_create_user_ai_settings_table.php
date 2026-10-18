<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 利用者ごとの AI 設定。API キーは暗号化して保存する（モデル側の encrypted キャスト）。
        Schema::create('user_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30)->default('anthropic');
            $table->text('api_key');
            $table->string('model', 60);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_ai_settings');
    }
};
