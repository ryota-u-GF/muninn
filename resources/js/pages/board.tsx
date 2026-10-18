import { usePage } from '@inertiajs/react';
import { Plus, Search } from 'lucide-react';
import { useEffect, useMemo, useRef, useState } from 'react';
import { toast } from 'sonner';
import { Empty, ErrorBanner, Loading, Page } from '@/components/gf/page';
import { TaskCard } from '@/components/gf/task-card';
import { useTaskEditor } from '@/components/gf/use-task-editor';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect } from '@/components/ui/textarea';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useTasks } from '@/hooks/use-tasks';
import { api, ApiError } from '@/lib/api';
import { sortOrderFor } from '@/lib/board';
import { tokyoYmd } from '@/lib/dates';
import type { Task, TaskStatus } from '@/lib/types';
import { STATUS_LABELS } from '@/lib/types';
import { cn } from '@/lib/utils';

const STATUSES: TaskStatus[] = ['todo', 'doing', 'done'];

export default function Board() {
    const { auth } = usePage().props;
    const me = auth.user!;
    const internal = me.role === 'admin' || me.role === 'employee';
    const ref = useReferenceData();
    const today = tokyoYmd();

    const [search, setSearch] = useState('');
    const [q, setQ] = useState('');
    const [assignee, setAssignee] = useState('');
    const [projectFilter, setProjectFilter] = useState('');
    const [showAllDone, setShowAllDone] = useState(false);
    const [dragId, setDragId] = useState<number | null>(null);
    // dragover は再描画前に届くことがあるため ref でも保持する
    const dragRef = useRef<number | null>(null);
    const [dropTarget, setDropTarget] = useState<string | null>(null);

    useEffect(() => {
        const t = window.setTimeout(() => setQ(search.trim()), 300);

        return () => window.clearTimeout(t);
    }, [search]);

    const query = useMemo(() => {
        const p = new URLSearchParams();
        if (q) p.set('q', q);
        if (assignee) p.set('assignee_id', assignee);
        if (showAllDone) p.set('include_old_done', '1');

        return p.toString();
    }, [q, assignee, showAllDone]);

    const { tasks, setTasks, loading, error, reload, upsert } = useTasks(query);
    const editor = useTaskEditor({ ...ref, onChanged: upsert });

    const sections = useMemo(() => {
        const list: { id: number | null; name: string; canAdd: boolean }[] =
            ref.projects
                .filter((p) => !p.archived)
                .map((p) => ({
                    id: p.id,
                    name: p.name,
                    canAdd: p.can_edit_content,
                }));
        if (internal) {
            list.push({ id: null, name: '会社共通（案件なし）', canAdd: true });
        }

        return list.filter(
            (s) =>
                projectFilter === '' ||
                String(s.id ?? 'none') === projectFilter,
        );
    }, [ref.projects, internal, projectFilter]);

    const column = (projectId: number | null, status: TaskStatus) =>
        tasks
            .filter((t) => t.project_id === projectId && t.status === status)
            .sort((a, b) => a.sort_order - b.sort_order || a.id - b.id);

    const drop = async (
        projectId: number | null,
        status: TaskStatus,
        beforeId: number | null,
    ) => {
        setDropTarget(null);
        const task = tasks.find((t) => t.id === dragRef.current);
        dragRef.current = null;
        setDragId(null);
        if (!task || beforeId === task.id) {
            return;
        }
        if (projectId !== task.project_id && !internal) {
            toast.error('外注は案件をまたいでタスクを移動できません。');

            return;
        }
        const target = column(projectId, status).filter(
            (t) => t.id !== task.id,
        );
        const sortOrder = sortOrderFor(target, beforeId);
        const previous = tasks;
        // 楽観的に反映し、失敗したら戻す
        setTasks((list) =>
            list.map((t) =>
                t.id === task.id
                    ? {
                          ...t,
                          status,
                          project_id: projectId,
                          sort_order: sortOrder,
                      }
                    : t,
            ),
        );
        const payload: Record<string, unknown> = {
            status,
            sort_order: sortOrder,
            expected_revision: task.revision,
        };
        if (projectId !== task.project_id) {
            payload.project_id = projectId;
        }
        try {
            const res = await api<{ data: Task }>(
                'PATCH',
                `/api/tasks/${task.id}`,
                payload,
            );
            upsert(res.data);
        } catch (e) {
            setTasks(previous);
            if (e instanceof ApiError && e.isConflict) {
                toast.error(
                    '他の人が先に更新しました。最新の状態を読み込みました。',
                );
                void reload();
            } else {
                toast.error(
                    e instanceof ApiError
                        ? e.firstError()
                        : '移動できませんでした。',
                );
            }
        }
    };

    return (
        <Page
            title="タスクボード"
            actions={
                <>
                    <div className="relative">
                        <Search className="absolute top-2.5 left-2 size-4 text-muted-foreground" />
                        <Input
                            aria-label="タスクを検索"
                            placeholder="キーワードで探す"
                            className="w-56 pl-8"
                            value={search}
                            onChange={(e) => setSearch(e.target.value)}
                        />
                    </div>
                    <NativeSelect
                        aria-label="担当者で絞り込み"
                        className="w-40"
                        value={assignee}
                        onChange={(e) => setAssignee(e.target.value)}
                    >
                        <option value="">担当者：すべて</option>
                        <option value="me">自分</option>
                        <option value="none">未割当</option>
                        {ref.members
                            .filter((m) => m.role !== 'tv')
                            .map((m) => (
                                <option key={m.id} value={m.id}>
                                    {m.name}
                                </option>
                            ))}
                    </NativeSelect>
                    <NativeSelect
                        aria-label="案件で絞り込み"
                        className="w-44"
                        value={projectFilter}
                        onChange={(e) => setProjectFilter(e.target.value)}
                    >
                        <option value="">案件：すべて</option>
                        {ref.projects.map((p) => (
                            <option key={p.id} value={p.id}>
                                {p.name}
                            </option>
                        ))}
                        {internal && <option value="none">会社共通</option>}
                    </NativeSelect>
                    <label className="flex items-center gap-1 text-sm">
                        <input
                            type="checkbox"
                            checked={showAllDone}
                            onChange={(e) => setShowAllDone(e.target.checked)}
                        />
                        古い完了も表示
                    </label>
                </>
            }
        >
            <ErrorBanner
                message={error ?? ref.error}
                onRetry={() => {
                    void reload();
                    void ref.reload();
                }}
            />
            {(loading && tasks.length === 0) || !ref.loaded ? (
                <Loading />
            ) : sections.length === 0 ? (
                <Empty>
                    表示できる案件がありません。
                    {!internal && '管理者に案件の権限付与を依頼してください。'}
                </Empty>
            ) : (
                <div className="space-y-6">
                    {sections.map((section) => (
                        <section
                            key={section.id ?? 'none'}
                            aria-label={section.name}
                            data-testid={`board-section-${section.id ?? 'none'}`}
                        >
                            <div className="mb-2 flex items-center justify-between">
                                <h2 className="font-semibold">
                                    {section.name}
                                </h2>
                                {section.canAdd && (
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        onClick={() =>
                                            editor.create({
                                                project_id: section.id,
                                            })
                                        }
                                    >
                                        <Plus className="mr-1 size-4" />
                                        タスク
                                    </Button>
                                )}
                            </div>
                            <div className="grid gap-3 md:grid-cols-3">
                                {STATUSES.map((status) => {
                                    const items = column(section.id, status);
                                    const key = `${section.id ?? 'none'}:${status}`;

                                    return (
                                        <div
                                            key={status}
                                            data-testid={`column-${key}`}
                                            onDragOver={(e) => {
                                                if (dragRef.current !== null) {
                                                    e.preventDefault();
                                                    setDropTarget(key);
                                                }
                                            }}
                                            onDragLeave={() =>
                                                setDropTarget((k) =>
                                                    k === key ? null : k,
                                                )
                                            }
                                            onDrop={(e) => {
                                                e.preventDefault();
                                                const card = (
                                                    e.target as HTMLElement
                                                ).closest('[data-task-id]');
                                                const beforeId = card
                                                    ? Number(
                                                          card.getAttribute(
                                                              'data-task-id',
                                                          ),
                                                      )
                                                    : null;
                                                void drop(
                                                    section.id,
                                                    status,
                                                    beforeId,
                                                );
                                            }}
                                            className={cn(
                                                'flex min-h-24 flex-col gap-2 rounded-lg bg-muted/50 p-2',
                                                dropTarget === key &&
                                                    'ring-2 ring-blue-400',
                                            )}
                                        >
                                            <div className="flex items-center justify-between px-1 text-xs font-semibold text-muted-foreground">
                                                <span>
                                                    {STATUS_LABELS[status]}
                                                </span>
                                                <span>{items.length}</span>
                                            </div>
                                            {items.map((t) => (
                                                <TaskCard
                                                    key={t.id}
                                                    task={t}
                                                    today={today}
                                                    memberName={ref.memberName}
                                                    onOpen={editor.open}
                                                    draggable={t.can.update}
                                                    dragging={dragId === t.id}
                                                    onDragStart={(task, e) => {
                                                        e.dataTransfer.setData(
                                                            'text/plain',
                                                            String(task.id),
                                                        );
                                                        e.dataTransfer.effectAllowed =
                                                            'move';
                                                        dragRef.current =
                                                            task.id;
                                                        setDragId(task.id);
                                                    }}
                                                    onDragEnd={() => {
                                                        dragRef.current = null;
                                                        setDragId(null);
                                                        setDropTarget(null);
                                                    }}
                                                />
                                            ))}
                                            {status === 'done' &&
                                                !showAllDone && (
                                                    <p className="px-1 text-[11px] text-muted-foreground">
                                                        直近7日より前の完了は「古い完了も表示」で確認できます
                                                    </p>
                                                )}
                                        </div>
                                    );
                                })}
                            </div>
                        </section>
                    ))}
                </div>
            )}
            {editor.element}
        </Page>
    );
}
