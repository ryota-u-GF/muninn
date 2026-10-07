import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { ErrorBanner, Loading } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { api, ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/dates';
import type { Paginated, RevisionItem } from '@/lib/types';
import { ACTION_LABELS, FIELD_LABELS, STATUS_LABELS } from '@/lib/types';
import { cn } from '@/lib/utils';

type Entity = 'tasks' | 'documents';

export function SnapshotView({
    snapshot,
    projectName,
    memberName,
}: {
    snapshot: Record<string, unknown>;
    projectName: (id: number | null) => string;
    memberName: (id: number) => string;
}) {
    const rows: [string, string][] = [];
    const order = [
        'title',
        'kind',
        'status',
        'visibility',
        'project_id',
        'meeting_date',
        'participants',
        'start_date',
        'due_date',
        'assignee_ids',
        'source_type',
        'source_url',
        'deleted',
        'name',
        'archived',
        'project_name',
        'permission',
        'role',
        'is_active',
    ];
    for (const key of order) {
        if (!(key in snapshot)) {
            continue;
        }
        const v = snapshot[key];
        let text: string;
        if (key === 'status') {
            text = STATUS_LABELS[v as 'todo'] ?? String(v);
        } else if (key === 'visibility') {
            text = v === 'private' ? '非公開' : '共有';
        } else if (key === 'kind') {
            text = v === 'minutes' ? '議事録' : '文書';
        } else if (key === 'project_id') {
            text = projectName((v as number | null) ?? null);
        } else if (key === 'assignee_ids') {
            const ids = (v as number[]) ?? [];
            text = ids.length ? ids.map(memberName).join('、') : '未割当';
        } else if (typeof v === 'boolean') {
            text = v ? 'はい' : 'いいえ';
        } else {
            text =
                v === null || v === undefined || v === ''
                    ? '—'
                    : typeof v === 'object'
                      ? JSON.stringify(v)
                      : String(v as string | number);
        }
        rows.push([FIELD_LABELS[key] ?? key, text]);
    }
    const body = (snapshot.body ?? snapshot.description) as
        | string
        | null
        | undefined;

    return (
        <div className="space-y-2 text-sm">
            <dl className="grid grid-cols-[7rem_1fr] gap-x-3 gap-y-1">
                {rows.map(([k, v]) => (
                    <div key={k} className="contents">
                        <dt className="text-muted-foreground">{k}</dt>
                        <dd className="break-all">{v}</dd>
                    </div>
                ))}
            </dl>
            {body !== undefined && (
                <div>
                    <div className="text-muted-foreground">
                        {'body' in snapshot ? '本文' : '説明'}
                    </div>
                    <pre className="mt-1 max-h-60 overflow-auto rounded bg-muted p-2 text-xs whitespace-pre-wrap">
                        {body || '（空）'}
                    </pre>
                </div>
            )}
        </div>
    );
}

export function HistoryDialog({
    open,
    entity,
    id,
    title,
    currentRevision,
    canRestore,
    projectName,
    memberName,
    onClose,
    onRestored,
}: {
    open: boolean;
    entity: Entity;
    id: number;
    title: string;
    currentRevision: number;
    canRestore: boolean;
    projectName: (id: number | null) => string;
    memberName: (id: number) => string;
    onClose: () => void;
    onRestored: (data: unknown) => void;
}) {
    const [page, setPage] = useState(1);
    const [list, setList] = useState<Paginated<RevisionItem> | null>(null);
    const [selected, setSelected] = useState<RevisionItem | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [confirming, setConfirming] = useState(false);
    const [busy, setBusy] = useState(false);
    const [revision, setRevision] = useState(currentRevision);

    useEffect(() => setRevision(currentRevision), [currentRevision]);

    const load = useCallback(async () => {
        setError(null);
        try {
            setList(
                await api<Paginated<RevisionItem>>(
                    'GET',
                    `/api/${entity}/${id}/revisions?page=${page}`,
                ),
            );
        } catch (e) {
            setError(
                e instanceof ApiError
                    ? e.message
                    : '履歴を読み込めませんでした。',
            );
        }
    }, [entity, id, page]);

    useEffect(() => {
        if (open) {
            void load();
        } else {
            setSelected(null);
            setPage(1);
        }
    }, [open, load]);

    const preview = async (version: number) => {
        try {
            const res = await api<{ data: RevisionItem }>(
                'GET',
                `/api/${entity}/${id}/revisions/${version}`,
            );
            setSelected(res.data);
        } catch (e) {
            toast.error(
                e instanceof ApiError
                    ? e.message
                    : 'プレビューできませんでした。',
            );
        }
    };

    const restore = async () => {
        if (!selected) {
            return;
        }
        setBusy(true);
        try {
            const res = await api<{ data: { revision: number } }>(
                'POST',
                `/api/${entity}/${id}/restore`,
                {
                    version: selected.version,
                    expected_revision: revision,
                },
            );
            toast.success(
                `版 ${selected.version} の内容で新しい版を作成しました`,
            );
            setRevision(res.data.revision);
            setConfirming(false);
            setSelected(null);
            onRestored(res.data);
            await load();
        } catch (e) {
            setConfirming(false);
            toast.error(
                e instanceof ApiError && e.isConflict
                    ? '他の人が先に更新しました。最新の状態を読み込み直してから復元してください。'
                    : e instanceof ApiError
                      ? e.firstError()
                      : '復元に失敗しました。',
            );
        } finally {
            setBusy(false);
        }
    };

    return (
        <Dialog open={open} onOpenChange={(o) => !o && onClose()}>
            <DialogContent className="max-h-[92vh] overflow-y-auto sm:max-w-4xl">
                <DialogHeader>
                    <DialogTitle>変更履歴：{title}</DialogTitle>
                    <DialogDescription>
                        現在の権限で閲覧できる版だけを表示します。復元は新しい版として記録され、古い履歴は上書きされません。
                    </DialogDescription>
                </DialogHeader>
                <ErrorBanner message={error} onRetry={() => void load()} />
                {!list && !error && <Loading />}
                {list && (
                    <div className="grid gap-4 md:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
                        <div>
                            <ul className="divide-y rounded-md border">
                                {list.data.map((r) => (
                                    <li key={r.id}>
                                        <button
                                            type="button"
                                            onClick={() =>
                                                void preview(r.version)
                                            }
                                            className={cn(
                                                'w-full px-3 py-2 text-left text-sm hover:bg-muted',
                                                selected?.version ===
                                                    r.version && 'bg-muted',
                                            )}
                                        >
                                            <div className="flex justify-between gap-2">
                                                <span className="font-medium">
                                                    版 {r.version}・
                                                    {ACTION_LABELS[r.action] ??
                                                        r.action}
                                                </span>
                                                {r.version === revision && (
                                                    <span className="text-xs text-emerald-600">
                                                        現在
                                                    </span>
                                                )}
                                            </div>
                                            <div className="text-xs text-muted-foreground">
                                                {formatDateTime(r.created_at)}・
                                                {r.actor.name ?? '—'}
                                            </div>
                                            {r.changed.length > 0 && (
                                                <div className="text-xs text-muted-foreground">
                                                    変更：
                                                    {r.changed
                                                        .map(
                                                            (c) =>
                                                                FIELD_LABELS[
                                                                    c
                                                                ] ?? c,
                                                        )
                                                        .join('、')}
                                                </div>
                                            )}
                                            {r.before_hidden && (
                                                <div className="text-xs text-amber-600">
                                                    変更前は閲覧権限がないため非表示
                                                </div>
                                            )}
                                        </button>
                                    </li>
                                ))}
                            </ul>
                            {list.meta.last_page > 1 && (
                                <div className="mt-2 flex items-center justify-between text-xs">
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={page <= 1}
                                        onClick={() => setPage((p) => p - 1)}
                                    >
                                        新しい版
                                    </Button>
                                    <span>
                                        {page} / {list.meta.last_page}（
                                        {list.meta.total}件）
                                    </span>
                                    <Button
                                        size="sm"
                                        variant="outline"
                                        disabled={page >= list.meta.last_page}
                                        onClick={() => setPage((p) => p + 1)}
                                    >
                                        古い版
                                    </Button>
                                </div>
                            )}
                        </div>
                        <div className="rounded-md border p-3">
                            {!selected ? (
                                <p className="text-sm text-muted-foreground">
                                    左の一覧から版を選ぶと内容をプレビューできます。
                                </p>
                            ) : (
                                <div className="space-y-3">
                                    <div className="flex items-center justify-between gap-2">
                                        <h3 className="font-semibold">
                                            版 {selected.version} の内容
                                        </h3>
                                        {canRestore &&
                                            selected.version !== revision &&
                                            selected.action !== 'delete' && (
                                                <Button
                                                    size="sm"
                                                    onClick={() =>
                                                        setConfirming(true)
                                                    }
                                                >
                                                    この版に復元…
                                                </Button>
                                            )}
                                    </div>
                                    {selected.after && (
                                        <SnapshotView
                                            snapshot={selected.after}
                                            projectName={projectName}
                                            memberName={memberName}
                                        />
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                )}
                <ConfirmDialog
                    open={confirming}
                    title={`版 ${selected?.version} の内容に復元しますか？`}
                    confirmLabel="復元する（新しい版を作成）"
                    busy={busy}
                    onConfirm={() => void restore()}
                    onCancel={() => setConfirming(false)}
                >
                    現在の内容（版 {revision}）は履歴に残ります。復元すると版{' '}
                    {revision + 1} が作成されます。
                </ConfirmDialog>
            </DialogContent>
        </Dialog>
    );
}
