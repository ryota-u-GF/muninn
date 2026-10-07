<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Enums\Visibility;
use App\Http\Controllers\Controller;
use App\Http\Resources\TvBoardResource;
use App\Models\Task;
use App\Support\Clock;
use Illuminate\Http\Request;

/**
 * TV 専用の読み取り API。共有・未削除・有効案件のタスクのうち、
 * 進行中／本日期限／開始日≦今日≦期限 のもの（完了は除く）。
 */
class TvController extends Controller
{
    public function index(Request $request): TvBoardResource
    {
        $today = Clock::today();

        $tasks = Task::query()
            ->with(['assignees:id,name', 'project:id,name'])
            ->where('visibility', Visibility::Shared->value)
            ->where('status', '!=', TaskStatus::Done->value)
            ->where(fn ($q) => $q->whereNull('project_id')->orWhereHas('project', fn ($p) => $p->whereNull('archived_at')))
            ->where(fn ($q) => $q
                ->where('status', TaskStatus::Doing->value)
                ->orWhereDate('due_date', $today)
                ->orWhere(fn ($r) => $r->whereDate('start_date', '<=', $today)->whereDate('due_date', '>=', $today)))
            ->orderByRaw('due_date is null')->orderBy('due_date')->orderBy('id')
            ->limit(300)->get();

        return new TvBoardResource(['tasks' => $tasks, 'today' => $today]);
    }
}
