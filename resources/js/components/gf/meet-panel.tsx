import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/textarea';
import { MAIL_SYNCED_EVENT } from '@/hooks/use-auto-gmail-sync';
import { api, ApiError } from '@/lib/api';

type MeetData = {
    oauth_ready: boolean;
    connected: boolean;
    google_email: string | null;
    title_keywords: string;
    initial_days: number;
    initial_days_options: number[];
    auto_sync: boolean;
    sync_interval_minutes: number;
    sync_interval_options: number[];
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
    connected: { ok: true, text: 'Meetのメモを連携しました' },
    denied: {
        ok: false,
        text: 'カレンダーとドキュメントの読み取りが許可されませんでした。もう一度連携してください。',
    },
    error: { ok: false, text: 'Meetのメモの連携に失敗しました。' },
    unconfigured: {
        ok: false,
        text: 'Googleとの連携設定が未完了です（管理者の設定が必要です）。',
    },
};

/** 自分専用の Meet のメモの連携。読み取り専用で、読んだメモは本人だけの候補になる。 */
export function MeetPanel({
    aiReady,
    onSynced,
}: {
    aiReady: boolean;
    onSynced: () => void | Promise<void>;
}) {
    const [data, setData] = useState<MeetData | null>(null);
    const [keywords, setKeywords] = useState('');
    const [days, setDays] = useState(3);
    const [autoSync, setAutoSync] = useState(true);
    const [interval, setIntervalMinutes] = useState(10);
    const [busy, setBusy] = useState(false);

    const apply = useCallback((next: MeetData) => {
        setData(next);
        setKeywords(next.title_keywords);
        setDays(next.initial_days);
        setAutoSync(next.auto_sync);
        setIntervalMinutes(next.sync_interval_minutes);
    }, []);

    useEffect(() => {
        api<{ data: MeetData }>('GET', '/api/meet')
            .then((res) => apply(res.data))
            .catch(() =>
                toast.error('Meetのメモの設定を読み込めませんでした。'),
            );

        // Google から戻ってきたときの結果を表示する
        const url = new URL(window.location.href);
        const result = url.searchParams.get('meet');
        if (result && RETURN_MESSAGES[result]) {
            const m = RETURN_MESSAGES[result];
            (m.ok ? toast.success : toast.error)(m.text);
            url.searchParams.delete('meet');
            window.history.replaceState(null, '', url.pathname + url.search);
        }
    }, [apply]);

    // アプリを開いている間の自動取得が終わったら、最終取得日時と候補を更新する
    useEffect(() => {
        const refresh = () => {
            api<{ data: MeetData }>('GET', '/api/meet')
                .then((res) => apply(res.data))
                .catch(() => undefined);
            void onSynced();
        };
        window.addEventListener(MAIL_SYNCED_EVENT, refresh);

        return () => window.removeEventListener(MAIL_SYNCED_EVENT, refresh);
    }, [apply, onSynced]);

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
                    '/api/meet/sync',
                );
                processed += res.data.processed;
                skipped += res.data.skipped;
                candidates += res.data.candidates;
                await onSynced();
                if (!res.data.has_more) {
                    break;
                }
            }
            const refreshed = await api<{ data: MeetData }>('GET', '/api/meet');
            apply(refreshed.data);
            toast.success(
                processed === 0 && skipped === 0
                    ? '新しいメモはありません'
                    : `${processed}件のメモを確認し、${candidates}件の候補を作成しました（まだタスクにはなっていません）`,
            );
        });

    return (
        <section className="space-y-2 rounded-md border p-3">
            {!data.connected ? (
                <div className="space-y-2">
                    <h2 className="font-semibold">Meetのメモ</h2>
                    <p className="text-xs text-muted-foreground">
                        終わった会議のメモ（Googleドキュメント）から、決まったことと担当者を読み取って候補を作ります（読み取りだけで、変更はしません）。
                    </p>
                    {data.oauth_ready ? (
                        <Button size="sm" asChild>
                            <a href="/auth/meet/redirect">
                                Meetのメモを連携する
                            </a>
                        </Button>
                    ) : (
                        <p className="text-xs text-amber-700 dark:text-amber-300">
                            Googleとの連携設定が未完了です。管理者がGoogle
                            Cloudの設定を行うと使えます。
                        </p>
                    )}
                </div>
            ) : (
                <div className="space-y-2">
                    <div className="flex flex-wrap items-center gap-x-3 gap-y-2">
                        <p className="text-sm">
                            Meetのメモ 連携中：
                            <strong>{data.google_email}</strong>
                            <span className="ml-2 text-xs text-muted-foreground">
                                {data.last_synced_at
                                    ? `最終取得 ${new Date(data.last_synced_at).toLocaleString('ja-JP')}`
                                    : 'まだ取得していません'}
                            </span>
                        </p>
                        <Button
                            size="sm"
                            disabled={busy || !aiReady}
                            onClick={() => void sync()}
                        >
                            {busy ? '取得中…' : 'いま取得'}
                        </Button>
                        {!aiReady && (
                            <span className="text-xs text-muted-foreground">
                                先に「AIの設定」でAPIキーを登録してください
                            </span>
                        )}
                    </div>
                    {data.last_error && (
                        <p className="text-xs text-red-600" role="alert">
                            {data.last_error}
                        </p>
                    )}
                    <details>
                        <summary className="cursor-pointer text-xs text-muted-foreground">
                            メモの取り込みの設定
                        </summary>
                        <div className="mt-3 space-y-3">
                            <div className="grid max-w-xl gap-3 sm:grid-cols-2">
                                <div className="grid gap-1 sm:col-span-2">
                                    <Label htmlFor="meet-keywords">
                                        メモのタイトルに含む語
                                    </Label>
                                    <Input
                                        id="meet-keywords"
                                        value={keywords}
                                        maxLength={200}
                                        placeholder="例：Gemini,メモ,議事録"
                                        onChange={(e) =>
                                            setKeywords(e.target.value)
                                        }
                                    />
                                    <p className="text-xs text-muted-foreground">
                                        予定に付いたドキュメントのうち、タイトルにこの語（カンマ区切り）のどれかを含むものを、メモとして読みます。空にすると、付いているドキュメントをすべて読みます。
                                    </p>
                                </div>
                                <div className="grid gap-1">
                                    <Label htmlFor="meet-days">
                                        初回の取得期間
                                    </Label>
                                    <NativeSelect
                                        id="meet-days"
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
                                        checked={autoSync}
                                        onChange={(e) =>
                                            setAutoSync(e.target.checked)
                                        }
                                    />
                                    アプリを開いている間、自動で取得する
                                </label>
                                {autoSync && (
                                    <div className="grid gap-1">
                                        <Label htmlFor="meet-interval">
                                            取得の間隔
                                        </Label>
                                        <NativeSelect
                                            id="meet-interval"
                                            value={interval}
                                            onChange={(e) =>
                                                setIntervalMinutes(
                                                    Number(e.target.value),
                                                )
                                            }
                                        >
                                            {data.sync_interval_options.map(
                                                (m) => (
                                                    <option key={m} value={m}>
                                                        {m}分ごと
                                                    </option>
                                                ),
                                            )}
                                        </NativeSelect>
                                    </div>
                                )}
                            </div>
                            <div className="flex flex-wrap items-center gap-2">
                                <Button
                                    size="sm"
                                    variant="outline"
                                    disabled={busy}
                                    onClick={() =>
                                        run(async () => {
                                            const res = await api<{
                                                data: MeetData;
                                            }>('PUT', '/api/meet', {
                                                title_keywords: keywords,
                                                initial_days: days,
                                                auto_sync: autoSync,
                                                sync_interval_minutes: interval,
                                            });
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
                                    variant="outline"
                                    className="text-red-600"
                                    disabled={busy}
                                    onClick={() =>
                                        run(async () => {
                                            if (
                                                !window.confirm(
                                                    'Meetのメモの連携を解除しますか？（取り込み済みの候補は残ります）',
                                                )
                                            ) {
                                                return;
                                            }
                                            const res = await api<{
                                                data: MeetData;
                                            }>('DELETE', '/api/meet');
                                            apply(res.data);
                                            toast.success(
                                                'Meetのメモの連携を解除しました',
                                            );
                                        })
                                    }
                                >
                                    連携を解除
                                </Button>
                            </div>
                        </div>
                    </details>
                </div>
            )}
        </section>
    );
}
