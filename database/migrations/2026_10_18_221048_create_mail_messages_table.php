<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 取り込み済みメールの最小限の記録。本文は保存しない（二重取り込み防止と、候補の出どころ表示用）。
        Schema::create('mail_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('gmail_message_id', 64);
            $table->string('account_email', 255)->nullable();
            $table->string('subject', 500)->nullable();
            $table->string('sender', 255)->nullable();
            $table->timestamp('received_at')->nullable();
            $table->string('status', 15)->default('processed');
            $table->unsignedSmallInteger('candidate_count')->default(0);
            $table->timestamps();
            $table->unique(['owner_id', 'gmail_message_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_messages');
    }
};
