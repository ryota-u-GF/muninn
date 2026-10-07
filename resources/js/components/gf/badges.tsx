import { Lock } from 'lucide-react';
import type { TaskStatus } from '@/lib/types';
import { STATUS_LABELS } from '@/lib/types';
import { cn } from '@/lib/utils';

const STATUS_CLASS: Record<TaskStatus, string> = {
    todo: 'bg-slate-100 text-slate-700 dark:bg-slate-800 dark:text-slate-200',
    doing: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-100',
    done: 'bg-emerald-100 text-emerald-800 dark:bg-emerald-900 dark:text-emerald-100',
};

export function StatusBadge({ status }: { status: TaskStatus }) {
    return (
        <span
            className={cn(
                'inline-flex items-center rounded px-1.5 py-0.5 text-xs font-medium',
                STATUS_CLASS[status],
            )}
        >
            {STATUS_LABELS[status]}
        </span>
    );
}

export function PrivateBadge() {
    return (
        <span
            className="inline-flex items-center gap-0.5 rounded bg-amber-100 px-1.5 py-0.5 text-xs text-amber-800 dark:bg-amber-900 dark:text-amber-100"
            title="非公開（作成者本人のみ表示）"
        >
            <Lock className="size-3" />
            非公開
        </span>
    );
}

export function DueLabel({
    due,
    status,
    today,
}: {
    due: string | null;
    status: TaskStatus;
    today: string;
}) {
    if (!due) {
        return <span className="text-xs text-muted-foreground">期限なし</span>;
    }
    const overdue = status !== 'done' && due < today;
    const isToday = status !== 'done' && due === today;
    const [, m, d] = due.split('-');

    return (
        <span
            className={cn(
                'text-xs',
                overdue && 'font-semibold text-red-600 dark:text-red-400',
                isToday && 'font-semibold text-orange-600 dark:text-orange-400',
                !overdue && !isToday && 'text-muted-foreground',
            )}
        >
            {overdue ? '期限超過 ' : isToday ? '本日期限 ' : '期限 '}
            {Number(m)}/{Number(d)}
        </span>
    );
}

export function MemberChips({
    ids,
    name,
}: {
    ids: number[];
    name: (id: number) => string;
}) {
    if (ids.length === 0) {
        return <span className="text-xs text-muted-foreground">未割当</span>;
    }

    return (
        <span className="flex flex-wrap gap-1">
            {ids.map((id) => (
                <span
                    key={id}
                    className="rounded-full bg-muted px-2 py-0.5 text-xs"
                >
                    {name(id)}
                </span>
            ))}
        </span>
    );
}
