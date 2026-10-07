import { Empty } from '@/components/gf/page';
import { TaskCard } from '@/components/gf/task-card';
import type { Task } from '@/lib/types';

export function TaskListSection({
    title,
    tasks,
    today,
    memberName,
    projectName,
    onOpen,
    emptyText = '該当するタスクはありません',
    tone,
}: {
    title: string;
    tasks: Task[];
    today: string;
    memberName: (id: number) => string;
    projectName: (id: number | null) => string;
    onOpen: (task: Task) => void;
    emptyText?: string;
    tone?: 'danger' | 'warn';
}) {
    return (
        <section aria-label={title} className="space-y-2">
            <h2
                className={
                    tone === 'danger'
                        ? 'font-semibold text-red-600'
                        : tone === 'warn'
                          ? 'font-semibold text-orange-600'
                          : 'font-semibold'
                }
            >
                {title}（{tasks.length}）
            </h2>
            {tasks.length === 0 ? (
                <Empty>{emptyText}</Empty>
            ) : (
                <div className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3">
                    {tasks.map((t) => (
                        <TaskCard
                            key={t.id}
                            task={t}
                            today={today}
                            memberName={memberName}
                            projectLabel={projectName(t.project_id)}
                            onOpen={onOpen}
                        />
                    ))}
                </div>
            )}
        </section>
    );
}

export function byDue(a: Task, b: Task): number {
    return (
        (a.due_date ?? '9999').localeCompare(b.due_date ?? '9999') ||
        a.id - b.id
    );
}
