<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->restrictOnDelete();
            $table->string('title', 300);
            $table->text('description')->nullable();
            $table->string('status', 10)->default('todo');
            $table->string('visibility', 10)->default('shared');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->date('start_date')->nullable();
            $table->date('due_date')->nullable();
            $table->double('sort_order')->default(0);
            $table->string('source_type', 30)->nullable();
            $table->string('source_url', 2048)->nullable();
            $table->string('created_via', 30)->default('manual');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('completed_at')->nullable();
            $table->softDeletes();
            $table->timestamps();
            $table->index(['visibility', 'created_by']);
            $table->index(['project_id', 'status']);
            $table->index('due_date');
        });

        Schema::create('task_assignees', function (Blueprint $table) {
            $table->foreignId('task_id')->constrained('tasks')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->primary(['task_id', 'user_id']);
            $table->index('user_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_assignees');
        Schema::dropIfExists('tasks');
    }
};
