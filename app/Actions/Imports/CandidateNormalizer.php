<?php

namespace App\Actions\Imports;

use App\Enums\Visibility;
use App\Models\Project;
use App\Models\User;
use App\Support\Access;
use Carbon\CarbonImmutable;

/**
 * 候補 1 件を正規化する。不明な案件・担当者・期限は推測で補わず、null にして警告を付ける。
 */
class CandidateNormalizer
{
    public const SOURCE_TYPES = ['email', 'minutes', 'voice', 'manual', 'other'];

    /**
     * @param  array<string, mixed>  $item
     * @return array{payload: array<string, mixed>, warnings: list<string>}
     */
    public function normalize(array $item): array
    {
        $warnings = [];
        $limits = config('gf.limits');

        $title = trim((string) ($item['title'] ?? ''));
        if ($title === '') {
            $warnings[] = 'タイトルが空です。確定前に入力してください。';
        }
        if (mb_strlen($title) > $limits['title']) {
            $title = mb_substr($title, 0, $limits['title']);
            $warnings[] = 'タイトルが長すぎるため切り詰めました。';
        }

        $description = isset($item['description']) ? trim((string) $item['description']) : null;
        if ($description !== null && mb_strlen($description) > $limits['task_description']) {
            $description = mb_substr($description, 0, $limits['task_description']);
            $warnings[] = '説明が長すぎるため切り詰めました。';
        }

        $projectId = null;
        if (isset($item['project_id']) && $item['project_id'] !== '') {
            $project = is_numeric($item['project_id']) ? Project::query()->active()->find((int) $item['project_id']) : null;
            if ($project === null) {
                $warnings[] = '不明な案件が指定されていたため、案件は未設定にしました。';
            } else {
                $projectId = $project->id;
            }
        }

        $assigneeIds = [];
        foreach ((array) ($item['assignee_ids'] ?? []) as $rawId) {
            $user = is_numeric($rawId) ? User::query()->assignable()->find((int) $rawId) : null;
            if ($user === null) {
                $warnings[] = '不明な担当者を除外しました。';

                continue;
            }
            if ($user->isContractor() && ! Access::canViewState($user, Visibility::Shared->value, $projectId, null)) {
                $warnings[] = "{$user->name} さんは案件の権限がないため担当者から除外しました。";

                continue;
            }
            $assigneeIds[] = $user->id;
        }
        $assigneeIds = array_values(array_unique($assigneeIds));
        sort($assigneeIds);

        $startDate = $this->date($item['start_date'] ?? null, '開始日', $warnings);
        $dueDate = $this->date($item['due_date'] ?? null, '期限', $warnings);
        if ($startDate !== null && $dueDate !== null && $startDate > $dueDate) {
            $warnings[] = '開始日が期限より後のため、両方を未設定にしました。';
            $startDate = $dueDate = null;
        }

        $sourceType = (string) ($item['source_type'] ?? 'other');
        if (! in_array($sourceType, self::SOURCE_TYPES, true)) {
            $sourceType = 'other';
        }

        return [
            'payload' => [
                'title' => $title,
                'description' => $description === '' ? null : $description,
                'project_id' => $projectId,
                'assignee_ids' => $assigneeIds,
                'start_date' => $startDate,
                'due_date' => $dueDate,
                'source_type' => $sourceType,
            ],
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    /** @param list<string> $warnings */
    private function date(mixed $value, string $label, array &$warnings): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $value);
            if ($parsed !== null && $parsed->format('Y-m-d') === $value) {
                return $value;
            }
        }
        $warnings[] = "{$label}の形式が不明なため未設定にしました（YYYY-MM-DD のみ受け付けます）。";

        return null;
    }
}
