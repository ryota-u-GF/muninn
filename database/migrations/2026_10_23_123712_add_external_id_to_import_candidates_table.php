<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 外部システムから受け取った候補の、先方での ID。同じ候補を送り直されても二重に作らないために使う。
        Schema::table('import_candidates', function (Blueprint $table) {
            $table->string('external_id', 200)->nullable()->after('fingerprint');
            $table->unique(['owner_id', 'external_id'], 'import_candidates_owner_external_unique');
        });
    }

    public function down(): void
    {
        Schema::table('import_candidates', function (Blueprint $table) {
            $table->dropUnique('import_candidates_owner_external_unique');
        });
        Schema::table('import_candidates', function (Blueprint $table) {
            $table->dropColumn('external_id');
        });
    }
};
