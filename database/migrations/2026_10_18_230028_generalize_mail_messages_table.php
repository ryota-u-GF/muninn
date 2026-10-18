<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // メール（Gmail）に加えて、議事録（Meet のメモ）も同じ取り込み記録で扱う。
        // 重複防止は、種類ごと（メールの ID と、ドキュメントの ID は別物として扱う）。
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->renameColumn('gmail_message_id', 'external_id');
            $table->string('kind', 10)->default('mail')->after('owner_id');
        });
        // owner_id の外部キーが古い一意制約を使っているため、新しい制約を先に作ってから、古いほうを消す。
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->unique(['owner_id', 'kind', 'external_id'], 'mail_messages_owner_kind_external_unique');
        });
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropUnique('mail_messages_owner_id_gmail_message_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->renameColumn('external_id', 'gmail_message_id');
        });
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->unique(['owner_id', 'gmail_message_id'], 'mail_messages_owner_id_gmail_message_id_unique');
        });
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropUnique('mail_messages_owner_kind_external_unique');
        });
        Schema::table('mail_messages', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
