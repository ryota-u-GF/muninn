<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 外部システムから受け取った候補の、元のメール・議事録を開くリンク
        Schema::table('import_candidates', function (Blueprint $table) {
            $table->string('source_url', 2048)->nullable()->after('external_id');
        });
    }

    public function down(): void
    {
        Schema::table('import_candidates', function (Blueprint $table) {
            $table->dropColumn('source_url');
        });
    }
};
