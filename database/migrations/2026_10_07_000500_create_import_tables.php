<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('import_candidates', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('source', 30);
            // 候補内容。却下時は null にして本文を消す
            $table->json('payload')->nullable();
            $table->json('warnings')->nullable();
            $table->string('status', 15)->default('pending');
            $table->unsignedInteger('revision')->default(1);
            $table->foreignId('task_id')->nullable()->constrained('tasks')->nullOnDelete();
            // 重複防止用の最小限の記録（内容のハッシュ）
            $table->string('fingerprint', 64);
            $table->timestamps();
            $table->index(['owner_id', 'status']);
            $table->index(['owner_id', 'fingerprint']);
        });

        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('idempotency_key', 100);
            $table->string('request_hash', 64);
            $table->json('result')->nullable();
            $table->timestamps();
            $table->unique(['owner_id', 'idempotency_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
        Schema::dropIfExists('import_candidates');
    }
};
