import { usePage } from '@inertiajs/react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { HistoryDialog, SnapshotView } from '@/components/gf/history-dialog';
import { Empty, ErrorBanner, Loading, Page } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import { NativeSelect } from '@/components/ui/textarea';
import { useReferenceData } from '@/hooks/use-reference-data';
import { api, ApiError } from '@/lib/api';
import { formatDateTime } from '@/lib/dates';
import type { DocumentItem, Paginated, RevisionItem, Task } from '@/lib/types';
import { ACTION_LABELS, FIELD_LABELS } from '@/lib/types';

const TYPE_LABELS: Record<string, string> = {
    task: 'タスク',
    document: '文書・議事録',
    project: '案件',
    project_access: '外注権限',
    user: '利用者',
};

type Target = {
    entity: 'tasks' | 'documents';
    id: number;
    title: string;
    revision: number;
    canRestore: boolean;
};

export default function HistoryPage() {
    const { auth } = usePage().props;
    const role = auth.user?.role;
    const ref = useReferenceData({
        includeArchived: role === 'admin' || role === 'employee',
    });
    const [type, setType] = useState('');
    const [page, setPage] = useState(1);
    const [list, setList] = useState<Paginated<RevisionItem> | null>(null);
    const [error, setError] = useState<string | null>(null);
    const [target, setTarget] = useState<Target | null>(null);
    const [audit, setAudit] = useState<RevisionItem | null>(null);

    const load = useCallback(async () => {
        setError(null);
        try {
            const p = new URLSearchParams({ page: String(page) });
            if (type) p.set('type', type);
            setList(
                await api<Paginated<RevisionItem>>('GET', `/api/history?${p}`),
            );
        } catch (e) {
            setError(
                e instanceof ApiError ? e.message : '読み込みに失敗しました。',
            );
        }
    }, [type, page]);

    useEffect(() => {
        void load();
    }, [load]);

    const openItem = async (r: RevisionItem) => {
        if (r.entity_type === 'task' || r.entity_type === 'document') {
            const entity = r.entity_type === 'task' ? 'tasks' : 'documents';
            try {
                const res = await api<{ data: Task | DocumentItem }>(
                    'GET',
                    `/api/${entity}/${r.entity_id}`,
                );
                setTarget({
                    entity,
                    id: r.entity_id,
                    title: res.data.title,
                    revision: res.data.revision,
                    canRestore: res.data.can.restore,
                });
            } catch (e) {
                toast.error(
                    e instanceof ApiError ? e.message : '開けませんでした。',
                );
            }
        } else {
            setAudit(r);
        }
    };

    const types =
        role === 'admin'
            ? ['task', 'document', 'project', 'project_access', 'user']
            : role === 'employee'
              ? ['task', 'document', 'project']
              : ['task', 'document'];

    return (
        <Page
            title="変更履歴"
            actions={
                <NativeSelect
                    aria-label="種類で絞り込み"
                    className="w-44"
                    value={type}
                    onChange={(e) => {
                        setType(e.target.value);
                        setPage(1);
                    }}
                >
                    <option value="">すべて</option>
                    {types.map((t) => (
                        <option key={t} value={t}>
                            {TYPE_LABELS[t]}
                        </option>
                    ))}
                </NativeSelect>
            }
        >
            <p className="text-sm text-muted-foreground">
                現在の権限で閲覧できる変更だけを表示します（最大 500
                件まで表示。履歴そのものは削除されません）。削除したタスク・文書も、ここから開いて復元できます。
            </p>
            <ErrorBanner message={error} onRetry={() => void load()} />
            {!list ? (
                <Loading />
            ) : list.data.length === 0 ? (
                <Empty>表示できる履歴はありません。</Empty>
            ) : (
                <div className="overflow-x-auto rounded-md border">
                    <table className="w-full text-sm">
                        <thead className="bg-muted text-left text-xs">
                            <tr>
                                <th className="px-3 py-2">日時</th>
                                <th className="px-3 py-2">利用者</th>
                                <th className="px-3 py-2">対象</th>
                                <th className="px-3 py-2">操作</th>
                                <th className="px-3 py-2">版</th>
                                <th className="px-3 py-2">変更項目</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y">
                            {list.data.map((r) => (
                                <tr
                                    key={r.id}
                                    className="cursor-pointer hover:bg-muted/50"
                                    onClick={() => void openItem(r)}
                                >
                                    <td className="px-3 py-2 whitespace-nowrap">
                                        {formatDateTime(r.created_at)}
                                    </td>
                                    <td className="px-3 py-2">
                                        {r.actor.name ?? 'システム'}
                                    </td>
                                    <td className="px-3 py-2">
                                        <span className="text-xs text-muted-foreground">
                                            {TYPE_LABELS[r.entity_type]}
                                        </span>{' '}
                                        {r.title ?? `#${r.entity_id}`}
                                    </td>
                                    <td className="px-3 py-2">
                                        {ACTION_LABELS[r.action] ?? r.action}
                                    </td>
                                    <td className="px-3 py-2">{r.version}</td>
                                    <td className="px-3 py-2 text-xs text-muted-foreground">
                                        {r.changed
                                            .map((c) => FIELD_LABELS[c] ?? c)
                                            .join('、')}
                                    </td>
                                </tr>
                            ))}
                        </tbody>
                    </table>
                </div>
            )}
            {list && list.meta.last_page > 1 && (
                <div className="flex items-center gap-3 text-sm">
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={page <= 1}
                        onClick={() => setPage((p) => p - 1)}
                    >
                        新しい
                    </Button>
                    <span>
                        {page} / {list.meta.last_page}（{list.meta.total}件）
                    </span>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={page >= list.meta.last_page}
                        onClick={() => setPage((p) => p + 1)}
                    >
                        古い
                    </Button>
                </div>
            )}
            {target && (
                <HistoryDialog
                    open
                    entity={target.entity}
                    id={target.id}
                    title={target.title}
                    currentRevision={target.revision}
                    canRestore={target.canRestore}
                    projectName={ref.projectName}
                    memberName={ref.memberName}
                    onClose={() => setTarget(null)}
                    onRestored={() => void load()}
                />
            )}
            {audit && (
                <div
                    className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
                    onClick={() => setAudit(null)}
                >
                    <div
                        role="dialog"
                        aria-label="監査記録"
                        className="w-full max-w-lg rounded-md bg-background p-4"
                        onClick={(e) => e.stopPropagation()}
                    >
                        <h2 className="mb-2 font-semibold">
                            {TYPE_LABELS[audit.entity_type]}：
                            {ACTION_LABELS[audit.action] ?? audit.action}
                        </h2>
                        <p className="mb-3 text-xs text-muted-foreground">
                            {formatDateTime(audit.created_at)}・
                            {audit.actor.name ?? 'システム（コマンド）'}
                        </p>
                        <AuditDetail
                            revision={audit}
                            projectName={ref.projectName}
                            memberName={ref.memberName}
                        />
                        <div className="mt-3 text-right">
                            <Button size="sm" onClick={() => setAudit(null)}>
                                閉じる
                            </Button>
                        </div>
                    </div>
                </div>
            )}
        </Page>
    );
}

function AuditDetail({
    revision,
    projectName,
    memberName,
}: {
    revision: RevisionItem;
    projectName: (id: number | null) => string;
    memberName: (id: number) => string;
}) {
    return (
        <div className="grid gap-3 text-sm sm:grid-cols-2">
            <div>
                <h3 className="mb-1 text-xs font-semibold text-muted-foreground">
                    変更前
                </h3>
                {revision.before ? (
                    <SnapshotView
                        snapshot={revision.before}
                        projectName={projectName}
                        memberName={memberName}
                    />
                ) : (
                    <p className="text-muted-foreground">—（新規）</p>
                )}
            </div>
            <div>
                <h3 className="mb-1 text-xs font-semibold text-muted-foreground">
                    変更後
                </h3>
                {revision.after ? (
                    <SnapshotView
                        snapshot={revision.after}
                        projectName={projectName}
                        memberName={memberName}
                    />
                ) : (
                    <p className="text-muted-foreground">—</p>
                )}
            </div>
        </div>
    );
}
