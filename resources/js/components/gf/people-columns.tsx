import { Empty } from '@/components/gf/page';
import { TaskCard } from '@/components/gf/task-card';
import { byDue } from '@/components/gf/task-list-section';
import type { Member, Task } from '@/lib/types';

/** 人ごとの列（一番端に「未割当」）。複数担当のタスクは、それぞれの担当者の列に出る。 */
export function PeopleColumns({
    tasks,
    members,
    today,
    memberName,
    projectName,
    onOpen,
}: {
    tasks: Task[];
    members: Member[];
    today: string;
    memberName: (id: number) => string;
    projectName: (id: number | null) => string;
    onOpen: (task: Task) => void;
}) {
    const columns: { key: string; title: string; tasks: Task[] }[] = [
        ...members
            .filter((m) => m.role !== 'tv' && m.is_active)
            .map((m) => ({
                key: String(m.id),
                title: m.name,
                tasks: tasks
                    .filter((t) => t.assignee_ids.includes(m.id))
                    .sort(byDue),
            })),
        {
            key: 'none',
            title: '未割当',
            tasks: tasks.filter((t) => t.assignee_ids.length === 0).sort(byDue),
        },
    ];

    return (
        <div className="flex gap-3 overflow-x-auto pb-2">
            {columns.map((col) => (
                <section
                    key={col.key}
                    aria-label={col.title}
                    className="flex w-72 shrink-0 flex-col gap-2 rounded-lg bg-muted/50 p-2"
                >
                    <h2 className="flex items-center justify-between px-1 text-sm font-semibold">
                        <span>{col.title}</span>
                        <span className="text-muted-foreground">
                            {col.tasks.length}
                        </span>
                    </h2>
                    {col.tasks.length === 0 ? (
                        <Empty>なし</Empty>
                    ) : (
                        col.tasks.map((t) => (
                            <TaskCard
                                key={t.id}
                                task={t}
                                today={today}
                                memberName={memberName}
                                projectLabel={projectName(t.project_id)}
                                onOpen={onOpen}
                            />
                        ))
                    )}
                </section>
            ))}
        </div>
    );
}
