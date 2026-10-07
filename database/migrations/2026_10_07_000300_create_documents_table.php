<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->string('visibility', 10)->default('shared');
            $table->string('kind', 10)->default('document');
            $table->string('title', 300);
            // 本文は最大 100,000 文字（utf8mb4 で最大 400KB）→ mediumText
            $table->mediumText('body')->nullable();
            $table->date('meeting_date')->nullable();
            $table->text('participants')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->softDeletes();
            $table->timestamps();
            $table->index(['kind', 'meeting_date']);
            $table->index(['visibility', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
