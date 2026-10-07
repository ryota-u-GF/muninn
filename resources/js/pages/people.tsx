import { useState } from 'react';
import { MemberChips } from '@/components/gf/badges';
import { Empty, ErrorBanner, Loading, Page } from '@/components/gf/page';
import { TaskCard } from '@/components/gf/task-card';
import { byDue } from '@/components/gf/task-list-section';
import { useTaskEditor } from '@/components/gf/use-task-editor';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useTasks } from '@/hooks/use-tasks';
import { tokyoYmd } from '@/lib/dates';
import type { Task } from '@/lib/types';

/** 担当者一覧：人別のタスクと未割当 */
export default function People() {
    const ref = useReferenceData();
    const today = tokyoYmd();
    const [showDone, setShowDone] = useState(false);
    const { tasks, loading, error, reload, upsert } = useTasks('');
    const editor = useTaskEditor({ ...ref, onChanged: upsert });
    const visible = tasks.filter((t) => showDone || t.status !== 'done');

    const columns: { key: string; title: string; tasks: Task[] }[] = [
        ...ref.members
            .filter((m) => m.role !== 'tv' && m.is_active)
            .map((m) => ({
                key: String(m.id),
                title: `${m.name}`,
                tasks: visible
                    .filter((t) => t.assignee_ids.includes(m.id))
                    .sort(byDue),
            })),
        {
            key: 'none',
            title: '未割当',
            tasks: visible
                .filter((t) => t.assignee_ids.length === 0)
                .sort(byDue),
        },
    ];

    return (
        <Page
            title="担当者一覧"
            actions={
                <label className="flex items-center gap-1 text-sm">
                    <input
                        type="checkbox"
                        checked={showDone}
                        onChange={(e) => setShowDone(e.target.checked)}
                    />
                    完了も表示
                </label>
            }
        >
            <ErrorBanner
                message={error ?? ref.error}
                onRetry={() => void reload()}
            />
            {loading && tasks.length === 0 ? (
                <Loading />
            ) : (
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
                                        memberName={ref.memberName}
                                        projectLabel={ref.projectName(
                                            t.project_id,
                                        )}
                                        onOpen={editor.open}
                                    />
                                ))
                            )}
                        </section>
                    ))}
                </div>
            )}
            <p className="text-xs text-muted-foreground">
                複数担当のタスクは各担当者の列に表示されます。
                <MemberChips ids={[]} name={ref.memberName} />{' '}
                は担当者が設定されていないタスクです。
            </p>
            {editor.element}
        </Page>
    );
}
