import { addDays, diffDays } from '@/lib/dates';
import type { Member, Project, Task } from '@/lib/types';

export type ScheduleMode = 'project' | 'person';

export type ScheduleGroup = {
    key: string;
    label: string;
    tasks: Task[]; // 日付のあるタスク（行として描画）
    undated: number; // 日付未設定の件数（日付を捏造しない）
};

/** 開始日・期限のどちらか一方しかない場合は、その 1 日だけの棒にする */
export function taskSpan(
    task: Pick<Task, 'start_date' | 'due_date'>,
): { start: string; end: string } | null {
    const start = task.start_date ?? task.due_date;
    const end = task.due_date ?? task.start_date;
    if (!start || !end) {
        return null;
    }

    return start <= end ? { start, end } : { start: end, end: start };
}

/** 表示範囲内での列位置。範囲外なら null */
export function barPosition(
    span: { start: string; end: string },
    rangeStart: string,
    rangeEnd: string,
): { offset: number; length: number } | null {
    if (span.end < rangeStart || span.start > rangeEnd) {
        return null;
    }
    const s = span.start < rangeStart ? rangeStart : span.start;
    const e = span.end > rangeEnd ? rangeEnd : span.end;

    return { offset: diffDays(rangeStart, s), length: diffDays(s, e) + 1 };
}

/** 期間が未指定なら、今日の前後とすべてのタスクの日付を含む範囲にする（最大 2 年） */
export function autoRange(
    tasks: Task[],
    today: string,
): { from: string; to: string } {
    let from = addDays(today, -14);
    let to = addDays(today, 45);
    for (const t of tasks) {
        const span = taskSpan(t);
        if (!span) {
            continue;
        }
        if (span.start < from) from = span.start;
        if (span.end > to) to = span.end;
    }
    if (diffDays(from, to) > 730) {
        to = addDays(from, 730);
    }

    return { from, to };
}

export function buildGroups(
    tasks: Task[],
    mode: ScheduleMode,
    projects: Project[],
    members: Member[],
): ScheduleGroup[] {
    const sortTasks = (list: Task[]) =>
        [...list].sort((a, b) => {
            const sa = taskSpan(a)?.start ?? '9999';
            const sb = taskSpan(b)?.start ?? '9999';

            return sa.localeCompare(sb) || a.id - b.id;
        });

    const make = (key: string, label: string, list: Task[]): ScheduleGroup => ({
        key,
        label,
        tasks: sortTasks(list.filter((t) => taskSpan(t) !== null)),
        undated: list.filter((t) => taskSpan(t) === null).length,
    });

    if (mode === 'project') {
        const groups = projects.map((p) =>
            make(
                `p${p.id}`,
                p.name,
                tasks.filter((t) => t.project_id === p.id),
            ),
        );
        const common = tasks.filter((t) => t.project_id === null);
        if (common.length > 0) {
            groups.push(make('p-none', '会社共通', common));
        }

        return groups;
    }

    // 人別：複数担当のタスクは各担当者の行に表示
    const groups = members
        .filter((m) => m.role !== 'tv')
        .map((m) =>
            make(
                `u${m.id}`,
                m.name,
                tasks.filter((t) => t.assignee_ids.includes(m.id)),
            ),
        );
    const unassigned = tasks.filter((t) => t.assignee_ids.length === 0);
    if (unassigned.length > 0) {
        groups.push(make('u-none', '未割当', unassigned));
    }

    return groups.filter((g) => g.tasks.length > 0 || g.undated > 0);
}
