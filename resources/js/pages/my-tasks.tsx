import { Plus } from 'lucide-react';
import { usePage } from '@inertiajs/react';
import { useState } from 'react';
import { ErrorBanner, Loading, Page } from '@/components/gf/page';
import { byDue, TaskListSection } from '@/components/gf/task-list-section';
import { useTaskEditor } from '@/components/gf/use-task-editor';
import { Button } from '@/components/ui/button';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useTasks } from '@/hooks/use-tasks';
import { tokyoYmd } from '@/lib/dates';

/** 自分のタスク：担当タスクと自分の非公開タスク */
export default function MyTasks() {
    const { auth } = usePage().props;
    const internal =
        auth.user?.role === 'admin' || auth.user?.role === 'employee';
    const ref = useReferenceData();
    const today = tokyoYmd();
    const [showOldDone, setShowOldDone] = useState(false);
    const { tasks, loading, error, reload, upsert } = useTasks(
        `mine=1${showOldDone ? '&include_old_done=1' : ''}`,
    );
    const editor = useTaskEditor({ ...ref, onChanged: upsert });

    return (
        <Page
            title="自分のタスク"
            actions={
                <>
                    <label className="flex items-center gap-1 text-sm">
                        <input
                            type="checkbox"
                            checked={showOldDone}
                            onChange={(e) => setShowOldDone(e.target.checked)}
                        />
                        古い完了も表示
                    </label>
                    {internal && (
                        <Button
                            size="sm"
                            onClick={() =>
                                editor.create({
                                    visibility: 'private',
                                    assignee_ids: [auth.user!.id],
                                })
                            }
                        >
                            <Plus className="mr-1 size-4" />
                            非公開タスク
                        </Button>
                    )}
                </>
            }
        >
            <ErrorBanner message={error} onRetry={() => void reload()} />
            {loading && tasks.length === 0 ? (
                <Loading />
            ) : (
                <div className="space-y-6">
                    <TaskListSection
                        title="進行中"
                        tasks={tasks
                            .filter((t) => t.status === 'doing')
                            .sort(byDue)}
                        today={today}
                        {...ref}
                        onOpen={editor.open}
                    />
                    <TaskListSection
                        title="未着手"
                        tasks={tasks
                            .filter((t) => t.status === 'todo')
                            .sort(byDue)}
                        today={today}
                        {...ref}
                        onOpen={editor.open}
                    />
                    <TaskListSection
                        title="完了"
                        tasks={tasks
                            .filter((t) => t.status === 'done')
                            .sort(byDue)}
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
