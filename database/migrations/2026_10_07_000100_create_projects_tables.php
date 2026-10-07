<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->integer('sort_order')->default(0);
            $table->timestamp('archived_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
            $table->index(['archived_at', 'sort_order']);
        });

        Schema::create('project_access', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('project_id')->constrained('projects')->cascadeOnDelete();
            $table->string('permission', 10);
            $table->foreignId('granted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->unique(['user_id', 'project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('project_access');
        Schema::dropIfExists('projects');
    }
};
