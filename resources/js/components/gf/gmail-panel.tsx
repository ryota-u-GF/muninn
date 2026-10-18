import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';

type GmailData = {
    oauth_ready: boolean;
    connected: boolean;
    google_email: string | null;
    scope_mode: 'inbox' | 'label';
    label_name: string | null;
    exclude_promotions: boolean;
    initial_days: number;
    initial_days_options: number[];
    last_synced_at: string | null;
    last_error: string | null;
};

type SyncResult = {
    processed: number;
    skipped: number;
    candidates: number;
    has_more: boolean;
};

const RETURN_MESSAGES: Record<string, { ok: boolean; text: string }> = {
    connected: { ok: true, text: 'Gmailを連携しました' },
    denied: {
        ok: false,
        text: 'Gmailの読み取りが許可されませんでした。もう一度連携してください。',
    },
    error: { ok: false, text: 'Gmailの連携に失敗しました。' },
    unconfigured: {
        ok: false,
        text: 'Googleとの連携設定が未完了です（管理者の設定が必要です）。',
    },
};

/** 自分専用の Gmail 連携。読み取り専用で、取り込んだメールは本人だけの候補になる。 */
export function GmailPanel({
    aiReady,
    onSynced,
}: {
    aiReady: boolean;
    onSynced: () => void | Promise<void>;
}) {
    const [data, setData] = useState<GmailData | null>(null);
    const [mode, setMode] = useState<'inbox' | 'label'>('inbox');
    const [label, setLabel] = useState('');
    const [exclude, setExclude] = useState(true);
    const [days, setDays] = useState(3);
    const [busy, setBusy] = useState(false);

    const apply = useCallback((next: GmailData) => {
        setData(next);
        setMode(next.scope_mode);
        setLabel(next.label_name ?? '');
        setExclude(next.exclude_promotions);
        setDays(next.initial_days);
    }, []);

    useEffect(() => {
        api<{ data: GmailData }>('GET', '/api/gmail')
            .then((res) => apply(res.data))
            .catch(() => toast.error('Gmailの設定を読み込めませんでした。'));

        // Google から戻ってきたときの結果を表示する
        const url = new URL(window.location.href);
        const result = url.searchParams.get('gmail');
        if (result && RETURN_MESSAGES[result]) {
            const m = RETURN_MESSAGES[result];
            (m.ok ? toast.success : toast.error)(m.text);
            url.searchParams.delete('gmail');
            window.history.replaceState(null, '', url.pathname + url.search);
        }
    }, [apply]);

    const run = async (fn: () => Promise<void>) => {
        setBusy(true);
        try {
            await fn();
        } catch (e) {
            toast.error(
                e instanceof ApiError ? e.firstError() : '処理に失敗しました。',
            );
        } finally {
            setBusy(false);
        }
    };

    if (data === null) {
        return null;
    }

    const sync = () =>
        run(async () => {
            let processed = 0;
            let candidates = 0;
            let skipped = 0;
            // 1 回に少数ずつ処理するので、残りがなくなるまで続けて呼ぶ
            for (let i = 0; i < 50; i++) {
                const res = await api<{ data: SyncResult }>(
                    'POST',
                    '/api/gmail/sync',
                );
                processed += res.data.processed;
                skipped += res.data.skipped;
                candidates += res.data.candidates;
                await onSynced();
                if (!res.data.has_more) {
                    break;
                }
            }
            const refreshed = await api<{ data: GmailData }>(
                'GET',
                '/api/gmail',
            );
            apply(refreshed.data);
            toast.success(
                processed === 0 && skipped === 0
                    ? '新しいメールはありません'
                    : `${processed}通を確認し、${candidates}件の候補を作成しました（まだタスクにはなっていません）`,
            );
        });

    return (
        <section className="space-y-3 rounded-md border p-3">
            <h2 className="font-semibold">Gmail</h2>
            {!data.connected ? (
                <div className="space-y-2">
                    <p className="text-xs text-muted-foreground">
                        自分のGmailを連携すると、新着メールからタスクの候補を作ります（読み取りだけで、送信や削除はしません）。
                    </p>
                    {data.oauth_ready ? (
                        <Button size="sm" asChild>
                            <a href="/auth/gmail/redirect">Gmailを連携する</a>
                        </Button>
                    ) : (
                        <p className="text-xs text-amber-700 dark:text-amber-300">
                            Googleとの連携設定が未完了です。管理者がGoogle
                            Cloudの設定を行うと使えます。
                        </p>
                    )}
                </div>
            ) : (
                <div className="space-y-3">
                    <p className="text-sm">
                        連携中：<strong>{data.google_email}</strong>
                        <span className="ml-2 text-xs text-muted-foreground">
                            {data.last_synced_at
                                ? `最終取得 ${new Date(data.last_synced_at).toLocaleString('ja-JP')}`
                                : 'まだ取得していません'}
                        </span>
                    </p>
                    {data.last_error && (
                        <p className="text-xs text-red-600" role="alert">
                            {data.last_error}
                        </p>
                    )}
                    <div className="grid max-w-xl gap-3 sm:grid-cols-2">
                        <div className="grid gap-1">
                            <Label htmlFor="gmail-scope">取り込み対象</Label>
                            <NativeSelect
                                id="gmail-scope"
                                value={mode}
                                onChange={(e) =>
                                    setMode(e.target.value as 'inbox' | 'label')
                                }
                            >
                                <option value="inbox">受信箱すべて</option>
                                <option value="label">ラベルを指定</option>
                            </NativeSelect>
                        </div>
                        {mode === 'label' && (
                            <div className="grid gap-1">
                                <Label htmlFor="gmail-label">ラベル名</Label>
                                <Input
                                    id="gmail-label"
                                    value={label}
                                    maxLength={100}
                                    placeholder="例：タスク"
                                    onChange={(e) => setLabel(e.target.value)}
                                />
                            </div>
                        )}
                        <div className="grid gap-1">
                            <Label htmlFor="gmail-days">初回の取得期間</Label>
                            <NativeSelect
                                id="gmail-days"
                                value={days}
                                onChange={(e) =>
                                    setDays(Number(e.target.value))
                                }
                            >
                                {data.initial_days_options.map((d) => (
                                    <option key={d} value={d}>
                                        直近{d}日
                                    </option>
                                ))}
                            </NativeSelect>
                        </div>
                        <label className="flex items-center gap-2 text-sm sm:col-span-2">
                            <input
                                type="checkbox"
                                checked={exclude}
                                onChange={(e) => setExclude(e.target.checked)}
                            />
                            プロモーション・ソーシャル・フォーラムのメールを除く
                        </label>
                    </div>
                    <div className="flex flex-wrap items-center gap-2">
                        <Button
                            size="sm"
                            variant="outline"
                            disabled={
                                busy ||
                                (mode === 'label' && label.trim() === '')
                            }
                            onClick={() =>
                                run(async () => {
                                    const res = await api<{ data: GmailData }>(
                                        'PUT',
                                        '/api/gmail',
                                        {
                                            scope_mode: mode,
                                            label_name:
                                                mode === 'label' ? label : null,
                                            exclude_promotions: exclude,
                                            initial_days: days,
                                        },
                                    );
                                    apply(res.data);
                                    toast.success(
                                        '取り込みの設定を保存しました',
                                    );
                                })
                            }
                        >
                            設定を保存
                        </Button>
                        <Button
                            size="sm"
                            disabled={busy || !aiReady}
                            onClick={() => void sync()}
                        >
                            {busy ? '取得中…' : 'いま取得'}
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
                                            'Gmailの連携を解除しますか？（取り込み済みの候補は残ります）',
                                        )
                                    ) {
                                        return;
                                    }
                                    const res = await api<{ data: GmailData }>(
                                        'DELETE',
                                        '/api/gmail',
                                    );
                                    apply(res.data);
                                    toast.success('Gmailの連携を解除しました');
                                })
                            }
                        >
                            連携を解除
                        </Button>
                        {!aiReady && (
                            <span className="text-xs text-muted-foreground">
                                先に「AIの設定」でAPIキーを登録してください
                            </span>
                        )}
                    </div>
                </div>
            )}
        </section>
    );
}
