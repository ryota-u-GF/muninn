import { usePage } from '@inertiajs/react';
import { History, Trash2 } from 'lucide-react';
import { useEffect, useMemo, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { Button } from '@/components/ui/button';
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

export function TaskDialog({
    open,
    task,
    defaults,
    projects,
    members,
    onClose,
    onSaved,
    onOpenHistory,
}: {
    open: boolean;
    task: Task | null;
    defaults?: Partial<Task>;
    projects: Project[];
    members: Member[];
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

    useEffect(() => {
        if (open) {
            const f = toForm(task ?? defaults);
            setBase(task);
            setInitial(f);
            setForm(f);
            setErrors({});
            setMessage(null);
            setConflict(null);
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, task?.id]);

    const dirty = JSON.stringify(form) !== JSON.stringify(initial);
    const canEdit = isNew || (base?.can.update ?? false);
    const canChangeProject = isNew ? true : (base?.can.change_project ?? false);
    const canChangeAssignees = internal;
    const canChangeVisibility = isNew
        ? internal
        : (base?.can.change_visibility ?? false);

    const projectOptions = useMemo(
        () =>
            projects.filter(
                (p) =>
                    !p.archived &&
                    (internal ||
                        p.can_edit_content ||
                        String(p.id) === form.project_id),
            ),
        [projects, internal, form.project_id],
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
                    <DialogDescription>
                        {isNew
                            ? '必要な項目を入力して保存してください。'
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

                <fieldset disabled={!canEdit || saving} className="grid gap-3">
                    <div className="grid gap-1">
                        <Label htmlFor="task-title">タイトル（必須）</Label>
                        <Input
                            id="task-title"
                            value={form.title}
                            maxLength={300}
                            onChange={(e) => set('title', e.target.value)}
                            autoFocus={isNew}
                        />
                        {fieldError('title')}
                    </div>
                    <div className="grid gap-1">
                        <Label htmlFor="task-description">説明</Label>
                        <Textarea
                            id="task-description"
                            rows={4}
                            value={form.description}
                            maxLength={10000}
                            onChange={(e) => set('description', e.target.value)}
                        />
                        {fieldError('description')}
                    </div>
                    <div className="grid gap-3 sm:grid-cols-3">
                        <div className="grid gap-1">
                            <Label htmlFor="task-status">状態</Label>
                            <NativeSelect
                                id="task-status"
                                value={form.status}
                                onChange={(e) =>
                                    set('status', e.target.value as TaskStatus)
                                }
                            >
                                {(['todo', 'doing', 'done'] as const).map(
                                    (s) => (
                                        <option key={s} value={s}>
                                            {STATUS_LABELS[s]}
                                        </option>
                                    ),
                                )}
                            </NativeSelect>
                        </div>
                        <div className="grid gap-1">
                            <Label htmlFor="task-start">開始日</Label>
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
                        <div className="grid gap-1">
                            <Label htmlFor="task-due">期限</Label>
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
                    </div>
                    <div className="grid gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="task-project">案件</Label>
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
                            {fieldError('project_id')}
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
                                        const v = e.target.value as Visibility;
                                        setForm((f) => ({
                                            ...f,
                                            visibility: v,
                                            // 非公開の担当者は作成者本人のみ
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
                                    <p className="text-xs text-muted-foreground">
                                        公開区分は作成者だけが変更できます。
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                    <div className="grid gap-1">
                        <span className="text-sm font-medium">
                            担当者（複数可）
                        </span>
                        {canChangeAssignees ? (
                            <div className="flex flex-wrap gap-x-4 gap-y-1 rounded-md border p-2">
                                {assignable.map((m) => (
                                    <label
                                        key={m.id}
                                        className="flex items-center gap-1 text-sm"
                                    >
                                        <input
                                            type="checkbox"
                                            checked={form.assignee_ids.includes(
                                                m.id,
                                            )}
                                            onChange={(e) =>
                                                set(
                                                    'assignee_ids',
                                                    e.target.checked
                                                        ? [
                                                              ...form.assignee_ids,
                                                              m.id,
                                                          ].sort(
                                                              (a, b) => a - b,
                                                          )
                                                        : form.assignee_ids.filter(
                                                              (id) =>
                                                                  id !== m.id,
                                                          ),
                                                )
                                            }
                                        />
                                        {m.name}
                                    </label>
                                ))}
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
                        {fieldError('assignee_ids')}
                    </div>
                    {internal && (
                        <div className="grid gap-3 sm:grid-cols-2">
                            <div className="grid gap-1">
                                <Label htmlFor="task-source-type">
                                    情報源の種類
                                </Label>
                                <NativeSelect
                                    id="task-source-type"
                                    value={form.source_type}
                                    onChange={(e) =>
                                        set('source_type', e.target.value)
                                    }
                                >
                                    <option value="">なし</option>
                                    <option value="email">メール</option>
                                    <option value="minutes">議事録</option>
                                    <option value="voice">音声メモ</option>
                                    <option value="manual">手入力</option>
                                    <option value="other">その他</option>
                                </NativeSelect>
                            </div>
                            <div className="grid gap-1">
                                <Label htmlFor="task-source-url">
                                    情報源URL
                                </Label>
                                <Input
                                    id="task-source-url"
                                    type="url"
                                    value={form.source_url}
                                    onChange={(e) =>
                                        set('source_url', e.target.value)
                                    }
                                />
                                {fieldError('source_url')}
                            </div>
                        </div>
                    )}
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
