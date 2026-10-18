<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 利用者本人の Meet のメモ（カレンダーの予定に付いた Google ドキュメント）の連携。
        // 更新トークンは暗号化して保存する（モデル側の encrypted キャスト）。
        Schema::create('meet_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('google_email', 255);
            $table->text('refresh_token');
            // メモとみなす添付ドキュメントのタイトルに含まれる語（カンマ区切り）
            $table->string('title_keywords', 200)->default('Gemini,メモ,議事録,Notes');
            $table->unsignedSmallInteger('initial_days')->default(3);
            $table->boolean('auto_sync')->default(true);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(10);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meet_connections');
    }
};
