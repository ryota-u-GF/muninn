<?php

namespace App\Http\Requests;

final class TaskAttributes
{
    public const NAMES = [
        'title' => 'タイトル',
        'description' => '説明',
        'status' => '状態',
        'visibility' => '公開区分',
        'project_id' => '案件',
        'start_date' => '開始日',
        'due_date' => '期限',
        'assignee_ids' => '担当者',
        'sort_order' => '並び順',
        'source_type' => '情報源の種類',
        'source_url' => '情報源URL',
        'expected_revision' => '版',
        'body' => '本文',
        'meeting_date' => '会議日',
        'participants' => '参加者',
        'kind' => '種類',
        'name' => '名称',
    ];
}
