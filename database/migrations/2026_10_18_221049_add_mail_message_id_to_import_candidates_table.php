<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('import_candidates', function (Blueprint $table) {
            $table->foreignId('mail_message_id')->nullable()->after('task_id')->constrained('mail_messages')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('import_candidates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('mail_message_id');
        });
    }
};
