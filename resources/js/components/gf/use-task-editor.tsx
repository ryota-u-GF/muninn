import { useState } from 'react';
import { HistoryDialog } from '@/components/gf/history-dialog';
import { TaskDialog } from '@/components/gf/task-dialog';
import { api } from '@/lib/api';
import type { Member, Project, Task } from '@/lib/types';

/** タスクの作成・詳細編集・履歴ダイアログをまとめて扱う */
export function useTaskEditor({
    projects,
    members,
    projectName,
    memberName,
    onChanged,
}: {
    projects: Project[];
    members: Member[];
    projectName: (id: number | null) => string;
    memberName: (id: number) => string;
    onChanged: (task: Task) => void;
}) {
    const [editing, setEditing] = useState<Task | null>(null);
    const [creating, setCreating] = useState<Partial<Task> | null>(null);
    const [history, setHistory] = useState<Task | null>(null);

    const element = (
        <>
            <TaskDialog
                open={editing !== null || creating !== null}
                task={editing}
                defaults={creating ?? undefined}
                projects={projects}
                members={members}
                onClose={() => {
                    setEditing(null);
                    setCreating(null);
                }}
                onSaved={onChanged}
                onOpenHistory={(t) => {
                    setEditing(null);
                    setHistory(t);
                }}
            />
            {history && (
                <HistoryDialog
                    open
                    entity="tasks"
                    id={history.id}
                    title={history.title}
                    currentRevision={history.revision}
                    canRestore={history.can.restore}
                    projectName={projectName}
                    memberName={memberName}
                    onClose={() => setHistory(null)}
                    onRestored={async () => {
                        const res = await api<{ data: Task }>(
                            'GET',
                            `/api/tasks/${history.id}`,
                        );
                        onChanged(res.data);
                        setHistory(res.data);
                    }}
                />
            )}
        </>
    );

    return {
        open: (task: Task) => setEditing(task),
        create: (defaults: Partial<Task> = {}) => setCreating(defaults),
        element,
    };
}
