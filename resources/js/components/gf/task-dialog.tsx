import { usePage } from '@inertiajs/react';
import { ChevronDown, History, Trash2, X } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect, Textarea } from '@/components/ui/textarea';
import { confirmDiscard } from '@/hooks/use-unsaved-guard';
import { api, ApiError } from '@/lib/api';
import type {
    Member,
    Project,
    Task,
    TaskStatus,
    Visibility,
} from '@/lib/types';
import { STATUS_LABELS } from '@/lib/types';
import { cn } from '@/lib/utils';

type Form = {
    title: string;
    description: string;
    status: TaskStatus;
    project_id: string;
    visibility: Visibility;
    start_date: string;
    due_date: string;
    assignee_ids: number[];
    source_type: string;
    source_url: string;
};

function toForm(task: Partial<Task> | null | undefined): Form {
    return {
        title: task?.title ?? '',
        description: task?.description ?? '',
        status: task?.status ?? 'todo',
        project_id:
            task?.project_id === null || task?.project_id === undefined
                ? ''
                : String(task.project_id),
        visibility: task?.visibility ?? 'shared',
        start_date: task?.start_date ?? '',
        due_date: task?.due_date ?? '',
        assignee_ids: [...(task?.assignee_ids ?? [])].sort((a, b) => a - b),
        source_type: task?.source_type ?? '',
        source_url: task?.source_url ?? '',
    };
}

function toPayload(form: Form): Record<string, unknown> {
    return {
        title: form.title,
        description: form.description || null,
        status: form.status,
        project_id: form.project_id === '' ? null : Number(form.project_id),
        visibility: form.visibility,
        start_date: form.start_date || null,
        due_date: form.due_date || null,
        assignee_ids: form.assignee_ids,
        source_type: form.source_type || null,
        source_url: form.source_url || null,
    };
}

/** 任意項目の目印 */
function Optional() {
    return (
        <span className="ml-1 text-xs font-normal text-muted-foreground">
            任意
        </span>
    );
}

function Hint({ children }: { children: React.ReactNode }) {
    return <p className="text-xs text-muted-foreground">{children}</p>;
}

/** 詳細設定の中にある項目。エラーがあれば自動で開く */
const DETAIL_KEYS = [
    'description',
    'status',
    'start_date',
    'visibility',
    'source_type',
    'source_url',
];

export function TaskDialog({
    open,
    task,
    defaults,
    projects,
    members,
    onReferenceChanged,
    onClose,
    onSaved,
    onOpenHistory,
}: {
    open: boolean;
    task: Task | null;
    defaults?: Partial<Task>;
    projects: Project[];
    members: Member[];
    onReferenceChanged?: () => void | Promise<void>;
    onClose: () => void;
    onSaved: (task: Task) => void;
    onOpenHistory?: (task: Task) => void;
}) {
    const { auth } = usePage().props;
    const me = auth.user!;
    const internal = me.role === 'admin' || me.role === 'employee';
    const isNew = task === null;

    const [base, setBase] = useState<Task | null>(task);
    const [initial, setInitial] = useState<Form>(() =>
        toForm(task ?? defaults),
    );
    const [form, setForm] = useState<Form>(initial);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [message, setMessage] = useState<string | null>(null);
    const [conflict, setConflict] = useState<Task | 'unknown' | null>(null);
    const [saving, setSaving] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const [detailOpen, setDetailOpen] = useState(!isNew);
    const [addedProjects, setAddedProjects] = useState<Project[]>([]);
    const [addingProject, setAddingProject] = useState(false);
    const [newProjectName, setNewProjectName] = useState('');
    const [projectError, setProjectError] = useState<string | null>(null);
    const isAdmin = me.role === 'admin';

    useEffect(() => {
        if (open) {
            const f = toForm(task ?? defaults);
            setBase(task);
            setInitial(f);
            setForm(f);
            setErrors({});
            setMessage(null);
            setConflict(null);
            setDetailOpen(!isNew);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, task?.id]);

    // 別のタブで案件・利用者を登録して戻ってきたら、選択肢を最新にする
    useEffect(() => {
        if (!open || !onReferenceChanged) {
            return;
        }
        const onFocus = () => void onReferenceChanged();
        window.addEventListener('focus', onFocus);

        return () => window.removeEventListener('focus', onFocus);
    }, [open, onReferenceChanged]);

    const dirty = JSON.stringify(form) !== JSON.stringify(initial);
    const canEdit = isNew || (base?.can.update ?? false);
    const canChangeProject = isNew ? true : (base?.can.change_project ?? false);
    const canChangeAssignees = internal;
    const canChangeVisibility = isNew
        ? internal
        : (base?.can.change_visibility ?? false);

    const projectOptions = useMemo(
        () =>
            [
                ...projects,
                ...addedProjects.filter(
                    (a) => !projects.some((p) => p.id === a.id),
                ),
            ].filter(
                (p) =>
                    !p.archived &&
                    (internal ||
                        p.can_edit_content ||
                        String(p.id) === form.project_id),
            ),
        [projects, addedProjects, internal, form.project_id],
    );
    const assignable = members.filter(
        (m) =>
            m.is_active &&
            m.role !== 'tv' &&
            (form.visibility === 'shared' ||
                m.id === (base?.created_by ?? me.id)),
    );

    const set = <K extends keyof Form>(key: K, value: Form[K]) =>
        setForm((f) => ({ ...f, [key]: value }));

    const close = () => {
        if (saving) {
            return;
        }
        if (confirmDiscard(dirty)) {
            onClose();
        }
    };

    const createProject = async () => {
        const name = newProjectName.trim();
        if (name === '') {
            setProjectError('案件名を入力してください。');

            return;
        }
        setProjectError(null);
        try {
            const res = await api<{ data: Project }>('POST', '/api/projects', {
                name,
            });
            setAddedProjects((list) => [...list, res.data]);
            set('project_id', String(res.data.id));
            setNewProjectName('');
            setAddingProject(false);
            toast.success(`案件「${res.data.name}」を登録しました`);
            void onReferenceChanged?.();
        } catch (e) {
            setProjectError(
                e instanceof ApiError ? e.firstError() : '登録に失敗しました。',
            );
        }
    };

    const save = async (overrideRevision?: number) => {
        setSaving(true);
        setErrors({});
        setMessage(null);
        try {
            let saved: Task;
            if (isNew) {
                const payload = toPayload(form);
                if (!internal) {
                    delete payload.source_type;
                    delete payload.source_url;
                    delete payload.visibility;
                }
                saved = (
                    await api<{ data: Task }>('POST', '/api/tasks', payload)
                ).data;
                toast.success('タスクを作成しました');
            } else {
                const current = toPayload(form);
                const before = toPayload(initial);
                const changes: Record<string, unknown> = {};
                for (const key of Object.keys(current)) {
                    if (
                        JSON.stringify(current[key]) !==
                        JSON.stringify(before[key])
                    ) {
                        changes[key] = current[key];
                    }
                }
                saved = (
                    await api<{ data: Task }>(
                        'PATCH',
                        `/api/tasks/${base!.id}`,
                        {
                            ...changes,
                            expected_revision:
                                overrideRevision ?? base!.revision,
                        },
                    )
                ).data;
                toast.success('保存しました');
            }
            setConflict(null);
            onSaved(saved);
            onClose();
        } catch (e) {
            if (e instanceof ApiError) {
                if (e.isConflict) {
                    // 入力内容は保持したまま、最新版を確認できるようにする
                    setConflict('unknown');
                    setMessage(e.message);
                    try {
                        const latest = await api<{ data: Task }>(
                            'GET',
                            `/api/tasks/${base!.id}`,
                        );
                        setConflict(latest.data);
                    } catch {
                        // 取得できなくても下書きは残す
                    }
                } else {
                    setErrors(e.errors);
                    setMessage(e.status === 422 ? e.firstError() : e.message);
                }
            } else {
                setMessage('保存に失敗しました。');
            }
        } finally {
            setSaving(false);
        }
    };

    const remove = async () => {
        if (!base) {
            return;
        }
        setSaving(true);
        try {
            const res = await api<{ data: Task }>(
                'DELETE',
                `/api/tasks/${base.id}`,
                {
                    expected_revision: base.revision,
                },
            );
            toast.success('削除しました（履歴から復元できます）');
            onSaved(res.data);
            onClose();
        } catch (e) {
            setMessage(
                e instanceof ApiError ? e.message : '削除に失敗しました。',
            );
        } finally {
            setSaving(false);
            setConfirmDelete(false);
        }
    };

    const saveHint =
        form.title.trim() === ''
            ? 'タイトルを入力すると保存できます'
            : !isNew && !dirty
              ? '変更すると保存できます'
              : null;

    const fieldError = (key: string) =>
        errors[key]?.[0] ? (
            <p className="text-xs text-red-600">{errors[key][0]}</p>
        ) : null;

    return (
        <Dialog open={open} onOpenChange={(o) => !o && close()}>
            <DialogContent
                className="max-h-[92vh] overflow-y-auto sm:max-w-2xl"
                onInteractOutside={(e) => dirty && e.preventDefault()}
            >
                <DialogHeader>
                    <DialogTitle>
                        {isNew ? 'タスクを追加' : 'タスクの詳細'}
                    </DialogTitle>
                    <DialogDescription className="sr-only">
                        {isNew
                            ? 'タイトルだけ入力すれば保存できます。担当者や期限などは後からでも変更できます。'
                            : canEdit
                              ? `版 ${base?.revision}。保存すると履歴に記録されます。`
                              : 'このタスクは閲覧のみです。'}
                    </DialogDescription>
                </DialogHeader>

                {message && (
                    <div
                        role="alert"
                        className="rounded-md border border-red-300 bg-red-50 p-3 text-sm text-red-800 dark:bg-red-950 dark:text-red-200"
                    >
                        <p>{message}</p>
                        {conflict && (
                            <div className="mt-2 space-y-2">
                                <p>入力中の内容はこの画面に残っています。</p>
                                {conflict !== 'unknown' && (
                                    <div className="rounded bg-white/70 p-2 text-xs text-foreground dark:bg-black/30">
                                        <div className="font-semibold">
                                            最新（版 {conflict.revision}）
                                        </div>
                                        <div>タイトル：{conflict.title}</div>
                                        <div>
                                            状態：
                                            {STATUS_LABELS[conflict.status]}
                                        </div>
                                        <div>
                                            期限：
                                            {conflict.due_date ?? '未設定'}
                                        </div>
                                    </div>
                                )}
                                <div className="flex flex-wrap gap-2">
                                    {conflict !== 'unknown' &&
                                        conflict.can.update && (
                                            <Button
                                                size="sm"
                                                onClick={() => {
                                                    // 最新版を基準に、自分の入力で上書き保存
                                                    const latestForm =
                                                        toForm(conflict);
                                                    setBase(conflict);
                                                    setInitial(latestForm);
                                                    void save(
                                                        conflict.revision,
                                                    );
                                                }}
                                                disabled={saving}
                                            >
                                                自分の入力で上書き保存
                                            </Button>
                                        )}
                                    {conflict !== 'unknown' && (
                                        <Button
                                            size="sm"
                                            variant="outline"
                                            onClick={() => {
                                                const latestForm =
                                                    toForm(conflict);
                                                setBase(conflict);
                                                setInitial(latestForm);
                                                setForm(latestForm);
                                                setConflict(null);
                                                setMessage(null);
                                            }}
                                        >
                                            最新の内容に切り替える（入力を破棄）
                                        </Button>
                                    )}
                                </div>
                            </div>
                        )}
                    </div>
                )}

                <fieldset disabled={!canEdit || saving} className="grid gap-4">
                    <div className="grid gap-1">
                        <Label htmlFor="task-title">
                            タイトル
                            <span className="text-red-600">（必須）</span>
                        </Label>
                        <Input
                            id="task-title"
                            value={form.title}
                            maxLength={300}
                            placeholder="例：月次報告の作成"
                            onChange={(e) => set('title', e.target.value)}
                            autoFocus={isNew}
                        />
                        {fieldError('title')}
                    </div>

                    <div className="grid gap-1">
                        <span className="text-sm font-medium">
                            担当者
                            <Optional />
                        </span>
                        {canChangeAssignees ? (
                            <div className="grid gap-2">
                                {form.assignee_ids.length > 0 && (
                                    <ul className="flex flex-wrap gap-2">
                                        {form.assignee_ids.map((id) => (
                                            <li
                                                key={id}
                                                className="flex items-center gap-1 rounded-full bg-secondary py-1 pr-1 pl-3 text-sm"
                                            >
                                                {members.find(
                                                    (m) => m.id === id,
                                                )?.name ?? '—'}
                                                <button
                                                    type="button"
                                                    aria-label={`${
                                                        members.find(
                                                            (m) => m.id === id,
                                                        )?.name ?? '担当者'
                                                    }を外す`}
                                                    className="rounded-full p-1 hover:bg-black/10"
                                                    onClick={() =>
                                                        set(
                                                            'assignee_ids',
                                                            form.assignee_ids.filter(
                                                                (a) => a !== id,
                                                            ),
                                                        )
                                                    }
                                                >
                                                    <X className="size-3" />
                                                </button>
                                            </li>
                                        ))}
                                    </ul>
                                )}
                                <NativeSelect
                                    id="task-assignee"
                                    aria-label="担当者を選ぶ"
                                    value=""
                                    onChange={(e) => {
                                        const id = Number(e.target.value);
                                        if (id) {
                                            set(
                                                'assignee_ids',
                                                [...form.assignee_ids, id].sort(
                                                    (a, b) => a - b,
                                                ),
                                            );
                                        }
                                    }}
                                >
                                    <option value="">
                                        {form.assignee_ids.length === 0
                                            ? '担当者を選ぶ（未選択なら未割当）'
                                            : '担当者を追加する'}
                                    </option>
                                    {assignable
                                        .filter(
                                            (m) =>
                                                !form.assignee_ids.includes(
                                                    m.id,
                                                ),
                                        )
                                        .map((m) => (
                                            <option key={m.id} value={m.id}>
                                                {m.name}
                                            </option>
                                        ))}
                                </NativeSelect>
                            </div>
                        ) : (
                            <p className="text-sm text-muted-foreground">
                                {form.assignee_ids.length === 0
                                    ? '未割当'
                                    : form.assignee_ids
                                          .map(
                                              (id) =>
                                                  members.find(
                                                      (m) => m.id === id,
                                                  )?.name ?? '—',
                                          )
                                          .join('、')}
                                （外注は担当者を変更できません）
                            </p>
                        )}
                        {canChangeAssignees && isAdmin && (
                            <a
                                href="/admin/access"
                                target="_blank"
                                rel="noreferrer"
                                className="w-fit text-xs text-blue-700 underline"
                            >
                                一覧にいない人を登録する
                            </a>
                        )}
                        {fieldError('assignee_ids')}
                    </div>

                    <div className="grid items-start gap-4 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="task-due">
                                期限
                                <Optional />
                            </Label>
                            <Input
                                id="task-due"
                                type="date"
                                value={form.due_date}
                                min={form.start_date || undefined}
                                onChange={(e) =>
                                    set('due_date', e.target.value)
                                }
                            />
                            {fieldError('due_date')}
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="task-project">
                                案件
                                {internal && <Optional />}
                            </Label>
                            <NativeSelect
                                id="task-project"
                                value={form.project_id}
                                disabled={!canChangeProject}
                                onChange={(e) =>
                                    set('project_id', e.target.value)
                                }
                            >
                                {internal && (
                                    <option value="">
                                        会社共通（案件なし）
                                    </option>
                                )}
                                {!internal && form.project_id === '' && (
                                    <option value="">
                                        案件を選択してください
                                    </option>
                                )}
                                {projectOptions.map((p) => (
                                    <option key={p.id} value={p.id}>
                                        {p.name}
                                    </option>
                                ))}
                            </NativeSelect>
                            {internal && canChangeProject && (
                                <div className="grid gap-1">
                                    {addingProject ? (
                                        <div className="grid gap-1 rounded-md border p-2">
                                            <Label htmlFor="new-project-name">
                                                新しい案件名
                                            </Label>
                                            <div className="flex gap-2">
                                                <Input
                                                    id="new-project-name"
                                                    value={newProjectName}
                                                    placeholder="例：社内システム更改"
                                                    onChange={(e) =>
                                                        setNewProjectName(
                                                            e.target.value,
                                                        )
                                                    }
                                                    onKeyDown={(e) => {
                                                        if (e.key === 'Enter') {
                                                            e.preventDefault();
                                                            void createProject();
                                                        }
                                                    }}
                                                />
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    onClick={() =>
                                                        void createProject()
                                                    }
                                                >
                                                    登録
                                                </Button>
                                                <Button
                                                    type="button"
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() => {
                                                        setAddingProject(false);
                                                        setProjectError(null);
                                                    }}
                                                >
                                                    やめる
                                                </Button>
                                            </div>
                                            {projectError && (
                                                <p className="text-xs text-red-600">
                                                    {projectError}
                                                </p>
                                            )}
                                        </div>
                                    ) : (
                                        <button
                                            type="button"
                                            className="w-fit text-xs text-blue-700 underline"
                                            onClick={() =>
                                                setAddingProject(true)
                                            }
                                        >
                                            ＋ 一覧にない案件をここで登録する
                                        </button>
                                    )}
                                </div>
                            )}
                            {fieldError('project_id')}
                        </div>
                    </div>

                    <Collapsible
                        open={
                            detailOpen ||
                            DETAIL_KEYS.some((k) => errors[k]?.[0])
                        }
                        onOpenChange={setDetailOpen}
                    >
                        <CollapsibleTrigger
                            type="button"
                            className="flex w-full items-center gap-1 rounded-md border px-3 py-2 text-sm font-medium hover:bg-accent"
                        >
                            <ChevronDown
                                className={cn(
                                    'size-4 transition-transform',
                                    !(
                                        detailOpen ||
                                        DETAIL_KEYS.some((k) => errors[k]?.[0])
                                    ) && '-rotate-90',
                                )}
                            />
                            詳細設定
                            <span className="text-xs font-normal text-muted-foreground">
                                （説明・状態・開始日
                                {internal ? '・公開区分・情報源' : ''}）
                            </span>
                        </CollapsibleTrigger>
                        <CollapsibleContent className="mt-3 grid gap-4">
                            <div className="grid gap-1">
                                <Label htmlFor="task-description">
                                    説明
                                    <Optional />
                                </Label>
                                <Textarea
                                    id="task-description"
                                    rows={4}
                                    value={form.description}
                                    maxLength={10000}
                                    placeholder="作業内容や手順、注意点などを書きます"
                                    onChange={(e) =>
                                        set('description', e.target.value)
                                    }
                                />
                                {fieldError('description')}
                            </div>
                            <div className="grid items-start gap-4 sm:grid-cols-2">
                                <div className="grid gap-1">
                                    <Label htmlFor="task-status">状態</Label>
                                    <NativeSelect
                                        id="task-status"
                                        value={form.status}
                                        onChange={(e) =>
                                            set(
                                                'status',
                                                e.target.value as TaskStatus,
                                            )
                                        }
                                    >
                                        {(
                                            ['todo', 'doing', 'done'] as const
                                        ).map((s) => (
                                            <option key={s} value={s}>
                                                {STATUS_LABELS[s]}
                                            </option>
                                        ))}
                                    </NativeSelect>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="task-start">
                                        開始日
                                        <Optional />
                                    </Label>
                                    <Input
                                        id="task-start"
                                        type="date"
                                        value={form.start_date}
                                        onChange={(e) =>
                                            set('start_date', e.target.value)
                                        }
                                    />
                                    {fieldError('start_date')}
                                </div>
                            </div>
                            {internal && (
                                <div className="grid gap-1">
                                    <Label htmlFor="task-visibility">
                                        公開区分
                                    </Label>
                                    <NativeSelect
                                        id="task-visibility"
                                        value={form.visibility}
                                        disabled={!canChangeVisibility}
                                        onChange={(e) => {
                                            const v = e.target
                                                .value as Visibility;
                                            setForm((f) => ({
                                                ...f,
                                                // 非公開の担当者は作成者本人のみ
                                                visibility: v,
                                                assignee_ids:
                                                    v === 'private'
                                                        ? f.assignee_ids.filter(
                                                              (id) =>
                                                                  id ===
                                                                  (base?.created_by ??
                                                                      me.id),
                                                          )
                                                        : f.assignee_ids,
                                            }));
                                        }}
                                    >
                                        <option value="shared">共有</option>
                                        <option value="private">
                                            非公開（自分だけ）
                                        </option>
                                    </NativeSelect>
                                    {!canChangeVisibility && !isNew && (
                                        <Hint>
                                            公開区分は作成者だけが変更できます。
                                        </Hint>
                                    )}
                                </div>
                            )}
                            {internal && (
                                <div className="grid items-start gap-4 sm:grid-cols-2">
                                    <div className="grid gap-1">
                                        <Label htmlFor="task-source-type">
                                            情報源の種類
                                            <Optional />
                                        </Label>
                                        <NativeSelect
                                            id="task-source-type"
                                            value={form.source_type}
                                            onChange={(e) =>
                                                set(
                                                    'source_type',
                                                    e.target.value,
                                                )
                                            }
                                        >
                                            <option value="">なし</option>
                                            <option value="email">
                                                メール
                                            </option>
                                            <option value="minutes">
                                                議事録
                                            </option>
                                            <option value="voice">
                                                音声メモ
                                            </option>
                                            <option value="manual">
                                                手入力
                                            </option>
                                            <option value="other">
                                                その他
                                            </option>
                                        </NativeSelect>
                                    </div>
                                    <div className="grid gap-1">
                                        <Label htmlFor="task-source-url">
                                            情報源URL
                                            <Optional />
                                        </Label>
                                        <Input
                                            id="task-source-url"
                                            type="url"
                                            value={form.source_url}
                                            placeholder="https://…"
                                            onChange={(e) =>
                                                set(
                                                    'source_url',
                                                    e.target.value,
                                                )
                                            }
                                        />
                                        {fieldError('source_url')}
                                    </div>
                                </div>
                            )}
                        </CollapsibleContent>
                    </Collapsible>
                </fieldset>

                <DialogFooter className="flex-wrap gap-2 sm:justify-between">
                    <div className="flex gap-2">
                        {!isNew && base && onOpenHistory && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                onClick={() => onOpenHistory(base)}
                            >
                                <History className="mr-1 size-4" />
                                変更履歴
                            </Button>
                        )}
                        {!isNew && base?.can.delete && (
                            <Button
                                type="button"
                                variant="outline"
                                size="sm"
                                className="text-red-600"
                                onClick={() => setConfirmDelete(true)}
                                disabled={saving}
                            >
                                <Trash2 className="mr-1 size-4" />
                                削除
                            </Button>
                        )}
                    </div>
                    <div className="flex gap-2">
                        <Button
                            type="button"
                            variant="outline"
                            onClick={close}
                            disabled={saving}
                        >
                            {canEdit ? 'キャンセル' : '閉じる'}
                        </Button>
                        {canEdit && saveHint && (
                            <span
                                className="self-center text-xs text-muted-foreground"
                                data-testid="task-save-hint"
                            >
                                {saveHint}
                            </span>
                        )}
                        {canEdit && (
                            <Button
                                type="button"
                                onClick={() => void save()}
                                disabled={
                                    saving ||
                                    form.title.trim() === '' ||
                                    (!isNew && !dirty)
                                }
                            >
                                {saving ? '保存中…' : '保存'}
                            </Button>
                        )}
                    </div>
                </DialogFooter>

                <ConfirmDialog
                    open={confirmDelete}
                    title="タスクを削除しますか？"
                    destructive
                    confirmLabel="削除する"
                    busy={saving}
                    onConfirm={() => void remove()}
                    onCancel={() => setConfirmDelete(false)}
                >
                    「{base?.title}
                    」を削除します。削除後も変更履歴から復元できます。
                </ConfirmDialog>
            </DialogContent>
        </Dialog>
    );
}
