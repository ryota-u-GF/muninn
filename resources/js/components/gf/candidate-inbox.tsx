import { usePage } from '@inertiajs/react';
import { CheckSquare, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { PENDING_COUNT_EVENT } from '@/hooks/use-pending-candidate-counts';
import { Empty, ErrorBanner, Loading } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, Textarea } from '@/components/ui/textarea';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useUnsavedGuard } from '@/hooks/use-unsaved-guard';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { formatJa } from '@/lib/dates';
import type {
    Candidate,
    CandidateKind,
    CandidatePayload,
    Visibility,
} from '@/lib/types';

const exampleJson = (kind: CandidateKind) => `[
  {
    "title": "見積書を送付する",
    "description": "${kind === 'minutes' ? '会議で決まったこと' : 'メールの依頼より'}",
    "project_id": null,
    "assignee_ids": [],
    "visibility": "shared",
    "start_date": null,
    "due_date": "2026-10-20",
    "source_type": "${kind === 'minutes' ? 'minutes' : 'email'}"
  }
]`;

const SOURCE_LABELS: Record<string, string> = {
    manual_json: '手動追加',
    ai_text: 'メールの文章から作成',
    ai_minutes: '議事録から作成',
    gmail: 'Gmail',
    meet: 'Meetのメモ',
    inbound: '外部から受信',
};

const VISIBILITY_LABELS: Record<Visibility, string> = {
    shared: '共有（他のメンバーにも見える）',
    private: '自分のみ',
};

/** メール・議事録から見つけたタスクの候補。kind のものだけを出し、確認して正規のタスクにする。 */
export function CandidateInbox({ kind }: { kind: CandidateKind }) {
    const exampleText = exampleJson(kind);
    const ref = useReferenceData();
    const me = usePage().props.auth.user!;
    const [candidates, setCandidates] = useState<Candidate[]>([]);
    const [loading, setLoading] = useState(true);
    const [error, setError] = useState<string | null>(null);
    const [json, setJson] = useState('');
    const [busy, setBusy] = useState(false);
    const [selected, setSelected] = useState<Set<number>>(new Set());
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmKey, setConfirmKey] = useState<string | null>(null);
    const [editing, setEditing] = useState<{
        id: number;
        payload: CandidatePayload;
    } | null>(null);

    useUnsavedGuard(json.trim() !== '' || editing !== null);

    const load = useCallback(async () => {
        try {
            const res = await api<{ data: Candidate[] }>('GET', '/api/imports');
            setCandidates(res.data);
            // サイドバーの件数を、いまの表示に合わせる
            const pendingAll = res.data.filter((c) => c.status === 'pending');
            window.dispatchEvent(
                new CustomEvent(PENDING_COUNT_EVENT, {
                    detail: {
                        mail: pendingAll.filter((c) => c.kind === 'mail')
                            .length,
                        minutes: pendingAll.filter((c) => c.kind === 'minutes')
                            .length,
                    },
                }),
            );
            setError(null);
            setSelected(
                (s) =>
                    new Set(
                        [...s].filter((id) =>
                            res.data.some(
                                (c) => c.id === id && c.status === 'pending',
                            ),
                        ),
                    ),
            );
        } catch (e) {
            setError(
                e instanceof ApiError ? e.message : '読み込みに失敗しました。',
            );
        } finally {
            setLoading(false);
        }
    }, []);

    useEffect(() => {
        void load();

        // 候補は外部システムから届くため、タブに戻ったときに読み直す
        const onVisible = () => {
            if (document.visibilityState === 'visible') {
                void load();
            }
        };
        document.addEventListener('visibilitychange', onVisible);

        return () =>
            document.removeEventListener('visibilitychange', onVisible);
    }, [load]);

    const run = async (fn: () => Promise<void>) => {
        setBusy(true);
        try {
            await fn();
        } catch (e) {
            if (e instanceof ApiError && e.isConflict) {
                toast.error(e.message);
                await load();
            } else {
                toast.error(
                    e instanceof ApiError
                        ? e.firstError()
                        : '処理に失敗しました。',
                );
            }
        } finally {
            setBusy(false);
        }
    };

    const mine = candidates.filter((c) => c.kind === kind);
    const pending = mine.filter((c) => c.status === 'pending');
    const processed = mine.filter((c) => c.status !== 'pending');
    const chosen = pending.filter((c) => selected.has(c.id));

    const toggle = (id: number) =>
        setSelected((s) => {
            const next = new Set(s);
            if (next.has(id)) next.delete(id);
            else next.add(id);

            return next;
        });

    const confirm = () =>
        run(async () => {
            const key = confirmKey ?? newIdempotencyKey();
            setConfirmKey(key);
            // 通信失敗時は同じキーで再送できる（サーバー側で二重作成しない）
            const res = await api<{ replayed: boolean; task_ids: number[] }>(
                'POST',
                '/api/imports/confirm',
                {
                    idempotency_key: key,
                    items: chosen.map((c) => ({
                        id: c.id,
                        expected_revision: c.revision,
                    })),
                },
            );
            toast.success(
                `${res.task_ids.length}件のタスクを作成しました${res.replayed ? '（再送：作成済みの結果）' : ''}`,
            );
            setConfirmOpen(false);
            setConfirmKey(null);
            setSelected(new Set());
            await load();
        });

    const assignable = ref.members.filter(
        (m) => m.is_active && m.role !== 'tv',
    );

    return (
        <div className="flex flex-col gap-4">
            <p className="text-sm text-muted-foreground">
                {kind === 'minutes' ? '議事録' : 'メール'}
                から見つけたタスクの候補を、確認して登録します。候補は自分だけが見られ、承認したものだけがタスクになります。
            </p>

            <ErrorBanner
                message={error ?? ref.error}
                onRetry={() => void load()}
            />

            <section className="space-y-2">
                <div className="flex flex-wrap items-center justify-between gap-2">
                    <h2 className="font-semibold">
                        未確認の候補（{pending.length}）
                    </h2>
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() =>
                                setSelected(new Set(pending.map((c) => c.id)))
                            }
                            disabled={pending.length === 0}
                        >
                            すべて選択
                        </Button>
                        <Button
                            size="sm"
                            disabled={
                                chosen.length === 0 || busy || editing !== null
                            }
                            onClick={() => {
                                setConfirmKey(null);
                                setConfirmOpen(true);
                            }}
                        >
                            <CheckSquare className="mr-1 size-4" />
                            選択した {chosen.length} 件を確認…
                        </Button>
                    </div>
                </div>
                {loading ? (
                    <Loading />
                ) : pending.length === 0 ? (
                    <Empty>未確認の候補はありません。</Empty>
                ) : (
                    <ul className="divide-y rounded-md border">
                        {pending.map((c) => {
                            const p = c.payload!;
                            const isEditing = editing?.id === c.id;

                            return (
                                <li
                                    key={c.id}
                                    className="space-y-2 p-3"
                                    data-testid={`candidate-${c.id}`}
                                >
                                    <div className="flex items-start gap-2">
                                        <input
                                            type="checkbox"
                                            aria-label={`候補「${p.title}」を選択`}
                                            className="mt-1"
                                            checked={selected.has(c.id)}
                                            onChange={() => toggle(c.id)}
                                        />
                                        <div className="min-w-0 flex-1">
                                            {isEditing ? (
                                                <div className="grid gap-2 sm:grid-cols-2">
                                                    <Input
                                                        aria-label="タイトル"
                                                        value={
                                                            editing.payload
                                                                .title
                                                        }
                                                        maxLength={300}
                                                        onChange={(e) =>
                                                            setEditing({
                                                                id: c.id,
                                                                payload: {
                                                                    ...editing.payload,
                                                                    title: e
                                                                        .target
                                                                        .value,
                                                                },
                                                            })
                                                        }
                                                    />
                                                    <NativeSelect
                                                        aria-label="案件"
                                                        value={
                                                            editing.payload
                                                                .project_id ??
                                                            ''
                                                        }
                                                        onChange={(e) =>
                                                            setEditing({
                                                                id: c.id,
                                                                payload: {
                                                                    ...editing.payload,
                                                                    project_id:
                                                                        e.target
                                                                            .value
                                                                            ? Number(
                                                                                  e
                                                                                      .target
                                                                                      .value,
                                                                              )
                                                                            : null,
                                                                },
                                                            })
                                                        }
                                                    >
                                                        <option value="">
                                                            会社共通（案件なし）
                                                        </option>
                                                        {ref.projects.map(
                                                            (pr) => (
                                                                <option
                                                                    key={pr.id}
                                                                    value={
                                                                        pr.id
                                                                    }
                                                                >
                                                                    {pr.name}
                                                                </option>
                                                            ),
                                                        )}
                                                    </NativeSelect>
                                                    <NativeSelect
                                                        aria-label="公開区分"
                                                        value={
                                                            editing.payload
                                                                .visibility
                                                        }
                                                        onChange={(e) => {
                                                            const v = e.target
                                                                .value as Visibility;
                                                            setEditing({
                                                                id: c.id,
                                                                payload: {
                                                                    ...editing.payload,
                                                                    visibility:
                                                                        v,
                                                                    // 非公開の担当者は本人のみ
                                                                    assignee_ids:
                                                                        v ===
                                                                        'private'
                                                                            ? editing.payload.assignee_ids.filter(
                                                                                  (
                                                                                      x,
                                                                                  ) =>
                                                                                      x ===
                                                                                      me.id,
                                                                              )
                                                                            : editing
                                                                                  .payload
                                                                                  .assignee_ids,
                                                                },
                                                            });
                                                        }}
                                                    >
                                                        {(
                                                            [
                                                                'shared',
                                                                'private',
                                                            ] as const
                                                        ).map((v) => (
                                                            <option
                                                                key={v}
                                                                value={v}
                                                            >
                                                                {
                                                                    VISIBILITY_LABELS[
                                                                        v
                                                                    ]
                                                                }
                                                            </option>
                                                        ))}
                                                    </NativeSelect>
                                                    <Input
                                                        aria-label="開始日"
                                                        type="date"
                                                        value={
                                                            editing.payload
                                                                .start_date ??
                                                            ''
                                                        }
                                                        onChange={(e) =>
                                                            setEditing({
                                                                id: c.id,
                                                                payload: {
                                                                    ...editing.payload,
                                                                    start_date:
                                                                        e.target
                                                                            .value ||
                                                                        null,
                                                                },
                                                            })
                                                        }
                                                    />
                                                    <Input
                                                        aria-label="期限"
                                                        type="date"
                                                        value={
                                                            editing.payload
                                                                .due_date ?? ''
                                                        }
                                                        onChange={(e) =>
                                                            setEditing({
                                                                id: c.id,
                                                                payload: {
                                                                    ...editing.payload,
                                                                    due_date:
                                                                        e.target
                                                                            .value ||
                                                                        null,
                                                                },
                                                            })
                                                        }
                                                    />
                                                    <div className="flex flex-wrap gap-x-3 gap-y-1 sm:col-span-2">
                                                        {assignable
                                                            .filter(
                                                                (m) =>
                                                                    editing
                                                                        .payload
                                                                        .visibility !==
                                                                        'private' ||
                                                                    m.id ===
                                                                        me.id,
                                                            )
                                                            .map((m) => (
                                                                <label
                                                                    key={m.id}
                                                                    className="flex items-center gap-1 text-xs"
                                                                >
                                                                    <input
                                                                        type="checkbox"
                                                                        checked={editing.payload.assignee_ids.includes(
                                                                            m.id,
                                                                        )}
                                                                        onChange={(
                                                                            e,
                                                                        ) =>
                                                                            setEditing(
                                                                                {
                                                                                    id: c.id,
                                                                                    payload:
                                                                                        {
                                                                                            ...editing.payload,
                                                                                            assignee_ids:
                                                                                                e
                                                                                                    .target
                                                                                                    .checked
                                                                                                    ? [
                                                                                                          ...editing
                                                                                                              .payload
                                                                                                              .assignee_ids,
                                                                                                          m.id,
                                                                                                      ]
                                                                                                    : editing.payload.assignee_ids.filter(
                                                                                                          (
                                                                                                              x,
                                                                                                          ) =>
                                                                                                              x !==
                                                                                                              m.id,
                                                                                                      ),
                                                                                        },
                                                                                },
                                                                            )
                                                                        }
                                                                    />
                                                                    {m.name}
                                                                </label>
                                                            ))}
                                                    </div>
                                                    <Textarea
                                                        aria-label="説明"
                                                        rows={2}
                                                        className="sm:col-span-2"
                                                        value={
                                                            editing.payload
                                                                .description ??
                                                            ''
                                                        }
                                                        onChange={(e) =>
                                                            setEditing({
                                                                id: c.id,
                                                                payload: {
                                                                    ...editing.payload,
                                                                    description:
                                                                        e.target
                                                                            .value ||
                                                                        null,
                                                                },
                                                            })
                                                        }
                                                    />
                                                    <div className="flex gap-2 sm:col-span-2">
                                                        <Button
                                                            size="sm"
                                                            disabled={busy}
                                                            onClick={() =>
                                                                run(
                                                                    async () => {
                                                                        const {
                                                                            source_type:
                                                                                _s,
                                                                            ...changes
                                                                        } =
                                                                            editing.payload;
                                                                        void _s;
                                                                        await api(
                                                                            'PATCH',
                                                                            `/api/imports/${c.id}`,
                                                                            {
                                                                                ...changes,
                                                                                expected_revision:
                                                                                    c.revision,
                                                                            },
                                                                        );
                                                                        setEditing(
                                                                            null,
                                                                        );
                                                                        await load();
                                                                    },
                                                                )
                                                            }
                                                        >
                                                            候補を保存
                                                        </Button>
                                                        <Button
                                                            size="sm"
                                                            variant="outline"
                                                            onClick={() =>
                                                                setEditing(null)
                                                            }
                                                        >
                                                            キャンセル
                                                        </Button>
                                                    </div>
                                                </div>
                                            ) : (
                                                <>
                                                    <div className="font-medium">
                                                        {p.title || (
                                                            <span className="text-red-600">
                                                                （タイトル未入力）
                                                            </span>
                                                        )}
                                                    </div>
                                                    <div className="text-xs text-muted-foreground">
                                                        {SOURCE_LABELS[
                                                            c.source
                                                        ] ?? c.source}
                                                        ・公開：
                                                        {p.visibility ===
                                                        'private'
                                                            ? '自分のみ'
                                                            : '共有'}
                                                        ・案件：
                                                        {p.project_id === null
                                                            ? '未設定'
                                                            : ref.projectName(
                                                                  p.project_id,
                                                              )}
                                                        ・担当：
                                                        {p.assignee_ids.length
                                                            ? p.assignee_ids
                                                                  .map(
                                                                      ref.memberName,
                                                                  )
                                                                  .join('、')
                                                            : '未設定'}
                                                        ・期限：
                                                        {p.due_date
                                                            ? formatJa(
                                                                  p.due_date,
                                                              )
                                                            : '未設定'}
                                                    </div>
                                                    {c.mail && (
                                                        <div className="text-xs text-muted-foreground">
                                                            {c.mail.kind ===
                                                            'minutes'
                                                                ? '議事録：'
                                                                : 'メール：'}
                                                            {c.mail.subject ??
                                                                '（件名なし）'}
                                                            {c.mail.sender &&
                                                                `（${c.mail.sender}）`}{' '}
                                                            <a
                                                                href={
                                                                    c.mail.url
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-blue-700 underline"
                                                            >
                                                                {c.mail.kind ===
                                                                'minutes'
                                                                    ? 'メモを開く'
                                                                    : 'Gmailで開く'}
                                                            </a>
                                                        </div>
                                                    )}
                                                    {c.source_url && (
                                                        <div className="text-xs">
                                                            <a
                                                                href={
                                                                    c.source_url
                                                                }
                                                                target="_blank"
                                                                rel="noreferrer"
                                                                className="text-blue-700 underline"
                                                            >
                                                                元を開く
                                                            </a>
                                                        </div>
                                                    )}
                                                    {p.description && (
                                                        <div className="text-xs">
                                                            {p.description}
                                                        </div>
                                                    )}
                                                </>
                                            )}
                                            {c.warnings.length > 0 && (
                                                <ul className="mt-1 list-disc pl-5 text-xs text-amber-700 dark:text-amber-300">
                                                    {c.warnings.map((w) => (
                                                        <li key={w}>{w}</li>
                                                    ))}
                                                </ul>
                                            )}
                                        </div>
                                        {!isEditing && (
                                            <div className="flex shrink-0 gap-1">
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    onClick={() =>
                                                        setEditing({
                                                            id: c.id,
                                                            payload: { ...p },
                                                        })
                                                    }
                                                >
                                                    編集
                                                </Button>
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="text-red-600"
                                                    disabled={busy}
                                                    onClick={() =>
                                                        run(async () => {
                                                            if (
                                                                !window.confirm(
                                                                    'この候補を却下しますか？（本文は削除されます）',
                                                                )
                                                            )
                                                                return;
                                                            await api(
                                                                'POST',
                                                                `/api/imports/${c.id}/reject`,
                                                                {
                                                                    expected_revision:
                                                                        c.revision,
                                                                },
                                                            );
                                                            toast.success(
                                                                '却下しました',
                                                            );
                                                            await load();
                                                        })
                                                    }
                                                >
                                                    <X className="mr-1 size-4" />
                                                    却下
                                                </Button>
                                            </div>
                                        )}
                                    </div>
                                </li>
                            );
                        })}
                    </ul>
                )}
            </section>

            {processed.length > 0 && (
                <section className="space-y-1">
                    <h2 className="text-sm font-semibold text-muted-foreground">
                        最近処理した候補（14日間）
                    </h2>
                    <ul className="text-xs text-muted-foreground">
                        {processed.map((c) => (
                            <li key={c.id}>
                                #{c.id}・
                                {c.status === 'confirmed'
                                    ? `確定（タスク #${c.task_id}）：${c.payload?.title ?? ''}`
                                    : '却下（本文は削除済み）'}
                            </li>
                        ))}
                    </ul>
                </section>
            )}

            <details className="rounded-md border p-3">
                <summary className="cursor-pointer text-sm font-semibold">
                    候補を手で追加する（JSON）
                </summary>
                <div className="mt-2 space-y-2">
                    <p className="text-xs text-muted-foreground">
                        候補の配列（title, description, project_id,
                        assignee_ids, visibility, start_date, due_date,
                        source_type）を貼り付けます。日付は YYYY-MM-DD。
                    </p>
                    <Textarea
                        aria-label="候補JSON"
                        rows={8}
                        className="font-mono text-xs"
                        value={json}
                        onChange={(e) => setJson(e.target.value)}
                        placeholder={exampleText}
                    />
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setJson(exampleText)}
                        >
                            例を入れる
                        </Button>
                        <Button
                            size="sm"
                            disabled={busy || json.trim() === ''}
                            onClick={() =>
                                run(async () => {
                                    await api('POST', '/api/imports', { json });
                                    setJson('');
                                    toast.success(
                                        '候補を作成しました（まだタスクにはなっていません）',
                                    );
                                    await load();
                                })
                            }
                        >
                            候補を作成
                        </Button>
                    </div>
                </div>
            </details>

            <ConfirmDialog
                open={confirmOpen}
                title={`${chosen.length} 件をタスクとして作成しますか？`}
                confirmLabel="タスクを作成する"
                busy={busy}
                onConfirm={() => void confirm()}
                onCancel={() => setConfirmOpen(false)}
            >
                <p className="mb-2">
                    「共有」のタスクは、他のメンバーにも表示されます。「自分のみ」のタスクは、あなただけに表示されます。1
                    件でも他の画面で変更されていた場合は、全体を作成しません。
                </p>
                <ul className="max-h-60 list-disc overflow-auto pl-5">
                    {chosen.map((c) => (
                        <li key={c.id}>
                            {c.payload?.title}（
                            {c.payload?.visibility === 'private'
                                ? '自分のみ'
                                : '共有'}
                            ・
                            {c.payload?.project_id
                                ? ref.projectName(c.payload.project_id)
                                : '会社共通'}
                            ・期限{' '}
                            {c.payload?.due_date
                                ? formatJa(c.payload.due_date)
                                : '未設定'}
                            ）
                        </li>
                    ))}
                </ul>
            </ConfirmDialog>
        </div>
    );
}
