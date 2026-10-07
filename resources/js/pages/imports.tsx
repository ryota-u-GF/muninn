import { CheckSquare, Sparkles, X } from 'lucide-react';
import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { ConfirmDialog } from '@/components/gf/confirm-dialog';
import { Empty, ErrorBanner, Loading, Page } from '@/components/gf/page';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { NativeSelect, Textarea } from '@/components/ui/textarea';
import { useReferenceData } from '@/hooks/use-reference-data';
import { useUnsavedGuard } from '@/hooks/use-unsaved-guard';
import { api, ApiError, newIdempotencyKey } from '@/lib/api';
import { formatJa } from '@/lib/dates';
import type { Candidate, CandidatePayload } from '@/lib/types';

type Sample = { key: string; label: string; text: string };

const EXAMPLE_JSON = `[
  {
    "title": "見積書を送付する",
    "description": "メールの依頼より",
    "project_id": null,
    "assignee_ids": [],
    "start_date": null,
    "due_date": "2026-10-20",
    "source_type": "email"
  }
]`;

const SOURCE_LABELS: Record<string, string> = {
    manual_json: '手動JSON',
    demo_email: '合成メール（デモ）',
    demo_minutes: '合成議事録（デモ）',
    demo_voice: '合成音声メモ（デモ）',
};

export default function Imports() {
    const ref = useReferenceData();
    const [candidates, setCandidates] = useState<Candidate[]>([]);
    const [samples, setSamples] = useState<Sample[]>([]);
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
            const res = await api<{ data: Candidate[]; samples: Sample[] }>(
                'GET',
                '/api/imports',
            );
            setCandidates(res.data);
            setSamples(res.samples);
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

    const pending = candidates.filter((c) => c.status === 'pending');
    const processed = candidates.filter((c) => c.status !== 'pending');
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
                `${res.task_ids.length}件の共有タスクを作成しました${res.replayed ? '（再送：作成済みの結果）' : ''}`,
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
        <Page title="AI候補（本人のみ）">
            <div className="rounded-md border border-sky-300 bg-sky-50 p-3 text-sm text-sky-900 dark:border-sky-800 dark:bg-sky-950 dark:text-sky-100">
                <p className="font-semibold">
                    入力 → 候補 → 人が確認 → 共有タスク化
                </p>
                <ul className="mt-1 list-disc pl-5 text-xs">
                    <li>
                        候補はあなただけが閲覧できます（管理者を含む他の人には表示されません）。
                    </li>
                    <li>
                        選択して最終確認したものだけが共有タスクになります。AI
                        から直接共有タスクを作る経路はありません。
                    </li>
                    <li>
                        この画面のデモは<strong>合成データ</strong>です。実際の
                        AI 推論・Gmail
                        取得・録音・自動解析は行っていません（未接続）。
                    </li>
                    <li>
                        不明な案件・担当者・期限は推測せず「未設定」にして警告を表示します。
                    </li>
                </ul>
            </div>

            <div className="grid gap-4 lg:grid-cols-2">
                <section className="space-y-2 rounded-md border p-3">
                    <h2 className="font-semibold">合成デモから候補を作る</h2>
                    {samples.map((s) => (
                        <details key={s.key} className="rounded border p-2">
                            <summary className="cursor-pointer text-sm">
                                {s.label}（合成データ）
                            </summary>
                            <pre className="mt-2 text-xs whitespace-pre-wrap text-muted-foreground">
                                {s.text}
                            </pre>
                            <Button
                                size="sm"
                                className="mt-2"
                                disabled={busy}
                                onClick={() =>
                                    run(async () => {
                                        await api('POST', '/api/imports/demo', {
                                            sample: s.key,
                                        });
                                        toast.success(
                                            '候補を作成しました（まだ共有されていません）',
                                        );
                                        await load();
                                    })
                                }
                            >
                                <Sparkles className="mr-1 size-4" />
                                この合成データから候補を作る
                            </Button>
                        </details>
                    ))}
                </section>
                <section className="space-y-2 rounded-md border p-3">
                    <h2 className="font-semibold">手動 JSON 取り込み</h2>
                    <p className="text-xs text-muted-foreground">
                        候補の配列（title, description, project_id,
                        assignee_ids, start_date, due_date,
                        source_type）を貼り付けます。日付は YYYY-MM-DD。
                    </p>
                    <Textarea
                        aria-label="候補JSON"
                        rows={8}
                        className="font-mono text-xs"
                        value={json}
                        onChange={(e) => setJson(e.target.value)}
                        placeholder={EXAMPLE_JSON}
                    />
                    <div className="flex gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            onClick={() => setJson(EXAMPLE_JSON)}
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
                                        '候補を作成しました（まだ共有されていません）',
                                    );
                                    await load();
                                })
                            }
                        >
                            候補を作成
                        </Button>
                    </div>
                </section>
            </div>

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
                                                        {assignable.map((m) => (
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

            <ConfirmDialog
                open={confirmOpen}
                title={`${chosen.length} 件を共有タスクとして作成しますか？`}
                confirmLabel="共有タスクを作成する"
                busy={busy}
                onConfirm={() => void confirm()}
                onCancel={() => setConfirmOpen(false)}
            >
                <p className="mb-2">
                    作成すると会社の共有タスクとして他のメンバーにも表示されます。1
                    件でも他の画面で変更されていた場合は、全体を作成しません。
                </p>
                <ul className="max-h-60 list-disc overflow-auto pl-5">
                    {chosen.map((c) => (
                        <li key={c.id}>
                            {c.payload?.title}（
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
        </Page>
    );
}
