<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 変更履歴・監査記録。アプリからは追記のみ（App\Models\Revision で更新・削除を禁止）。
        Schema::create('revisions', function (Blueprint $table) {
            $table->id();
            $table->string('entity_type', 30);
            $table->unsignedBigInteger('entity_id');
            $table->unsignedInteger('version');
            $table->foreignId('actor_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->string('action', 30);
            // この版（after）の案件・公開区分・所有者。履歴の閲覧可否判定に使う。
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('visibility', 10)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            // 変更前（before）の案件・公開区分
            $table->unsignedBigInteger('before_project_id')->nullable();
            $table->string('before_visibility', 10)->nullable();
            $table->json('before')->nullable();
            $table->json('after')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['entity_type', 'entity_id', 'version']);
            $table->index(['entity_type', 'created_at']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('revisions');
    }
};
