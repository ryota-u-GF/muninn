import { ErrorBanner, Loading, Page } from '@/components/gf/page';
import { byDue, TaskListSection } from '@/components/gf/task-list-section';
import { useTaskEditor } from '@/components/gf/use-task-editor';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useTasks } from '@/hooks/use-tasks';
import { formatJa, tokyoYmd } from '@/lib/dates';

/** 今日：自分の期限到来・期限超過・進行中 */
export default function Today() {
    const ref = useReferenceData();
    const today = tokyoYmd();
    const { tasks, loading, error, reload, upsert } = useTasks('mine=1');
    const editor = useTaskEditor({ ...ref, onChanged: upsert });
    const open = tasks.filter((t) => t.status !== 'done');

    const overdue = open
        .filter((t) => t.due_date !== null && t.due_date < today)
        .sort(byDue);
    const dueToday = open.filter((t) => t.due_date === today).sort(byDue);
    const doing = open
        .filter(
            (t) =>
                t.status === 'doing' &&
                !(t.due_date !== null && t.due_date <= today),
        )
        .sort(byDue);

    return (
        <Page title={`今日（${formatJa(today)}）`}>
            <ErrorBanner message={error} onRetry={() => void reload()} />
            {loading && tasks.length === 0 ? (
                <Loading />
            ) : (
                <div className="space-y-6">
                    <TaskListSection
                        title="期限超過"
                        tone="danger"
                        tasks={overdue}
                        today={today}
                        {...ref}
                        onOpen={editor.open}
                    />
                    <TaskListSection
                        title="本日期限"
                        tone="warn"
                        tasks={dueToday}
                        today={today}
                        {...ref}
                        onOpen={editor.open}
                    />
                    <TaskListSection
                        title="進行中"
                        tasks={doing}
                        today={today}
                        {...ref}
                        onOpen={editor.open}
                    />
                </div>
            )}
            {editor.element}
        </Page>
    );
}
