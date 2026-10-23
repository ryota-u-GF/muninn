<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // メール・議事録の取得と AI の判定は外部システムに移したため、連携トークンと AI の API キーを消す。
        // mail_messages は、既存の候補が指す元のメール・メモのリンクのために残す。
        Schema::dropIfExists('gmail_connections');
        Schema::dropIfExists('meet_connections');
        Schema::dropIfExists('user_ai_settings');
    }

    /** 構造だけ戻す（消した連携トークン・API キーは戻らない） */
    public function down(): void
    {
        Schema::create('gmail_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('google_email', 255);
            $table->text('refresh_token');
            $table->string('scope_mode', 10)->default('inbox');
            $table->string('label_name', 100)->nullable();
            $table->boolean('exclude_promotions')->default(true);
            $table->unsignedSmallInteger('initial_days')->default(3);
            $table->boolean('auto_sync')->default(true);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(10);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('meet_connections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('google_email', 255);
            $table->text('refresh_token');
            $table->string('title_keywords', 200)->default('Gemini,メモ,議事録,Notes');
            $table->unsignedSmallInteger('initial_days')->default(3);
            $table->boolean('auto_sync')->default(true);
            $table->unsignedSmallInteger('sync_interval_minutes')->default(10);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_error', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('user_ai_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('provider', 30)->default('anthropic');
            $table->text('api_key');
            $table->string('model', 60);
            $table->timestamps();
        });
    }
};
