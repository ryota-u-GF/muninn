import { useCallback, useEffect, useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { NativeSelect } from '@/components/ui/textarea';
import { api, ApiError } from '@/lib/api';

type AiSettingsData = {
    configured: boolean;
    key_hint: string | null;
    model: string;
    models: { id: string; label: string }[];
};

/** 自分専用の AI（Claude）設定。API キーは保存のみで、画面には末尾4文字しか出さない。 */
export function AiSettings({
    onConfiguredChange,
}: {
    onConfiguredChange?: (configured: boolean) => void;
}) {
    const [data, setData] = useState<AiSettingsData | null>(null);
    const [apiKey, setApiKey] = useState('');
    const [model, setModel] = useState('');
    const [busy, setBusy] = useState(false);

    const apply = useCallback(
        (next: AiSettingsData) => {
            setData(next);
            setModel(next.model);
            onConfiguredChange?.(next.configured);
        },
        [onConfiguredChange],
    );

    useEffect(() => {
        api<{ data: AiSettingsData }>('GET', '/api/ai-settings')
            .then((res) => apply(res.data))
            .catch(() => toast.error('AIの設定を読み込めませんでした。'));
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

    return (
        <details className="rounded-md border p-3" open={!data.configured}>
            <summary className="cursor-pointer text-sm font-semibold">
                AIの設定（自分のAPIキー）
                <span className="ml-2 text-xs font-normal text-muted-foreground">
                    {data.configured
                        ? `設定済み（${data.key_hint}）`
                        : '未設定'}
                </span>
            </summary>
            <div className="mt-3 grid max-w-xl gap-3">
                <div className="grid gap-1">
                    <Label htmlFor="ai-api-key">
                        APIキー
                        {data.configured && (
                            <span className="ml-1 text-xs font-normal text-muted-foreground">
                                変更するときだけ入力
                            </span>
                        )}
                    </Label>
                    <Input
                        id="ai-api-key"
                        type="password"
                        autoComplete="off"
                        placeholder="sk-ant-…"
                        value={apiKey}
                        onChange={(e) => setApiKey(e.target.value)}
                    />
                </div>
                <div className="grid gap-1">
                    <Label htmlFor="ai-model">モデル</Label>
                    <NativeSelect
                        id="ai-model"
                        value={model}
                        onChange={(e) => setModel(e.target.value)}
                    >
                        {data.models.map((m) => (
                            <option key={m.id} value={m.id}>
                                {m.label}
                            </option>
                        ))}
                    </NativeSelect>
                </div>
                <div className="flex flex-wrap gap-2">
                    <Button
                        size="sm"
                        disabled={
                            busy || (!data.configured && apiKey.trim() === '')
                        }
                        onClick={() =>
                            run(async () => {
                                const res = await api<{ data: AiSettingsData }>(
                                    'PUT',
                                    '/api/ai-settings',
                                    {
                                        model,
                                        ...(apiKey.trim() === ''
                                            ? {}
                                            : { api_key: apiKey.trim() }),
                                    },
                                );
                                apply(res.data);
                                setApiKey('');
                                toast.success('AIの設定を保存しました');
                            })
                        }
                    >
                        保存
                    </Button>
                    <Button
                        size="sm"
                        variant="outline"
                        disabled={busy || !data.configured}
                        onClick={() =>
                            run(async () => {
                                await api('POST', '/api/ai-settings/test');
                                toast.success('AIに接続できました');
                            })
                        }
                    >
                        接続テスト
                    </Button>
                    {data.configured && (
                        <Button
                            size="sm"
                            variant="outline"
                            className="text-red-600"
                            disabled={busy}
                            onClick={() =>
                                run(async () => {
                                    if (
                                        !window.confirm(
                                            '保存したAPIキーを削除しますか？',
                                        )
                                    ) {
                                        return;
                                    }
                                    const res = await api<{
                                        data: AiSettingsData;
                                    }>('DELETE', '/api/ai-settings');
                                    apply(res.data);
                                    toast.success('APIキーを削除しました');
                                })
                            }
                        >
                            キーを削除
                        </Button>
                    )}
                </div>
                <p className="text-xs text-muted-foreground">
                    キーは暗号化して保存し、画面には表示しません。AIには、貼り付けた文章がAnthropicに送信されます。キーは、Claude
                    Proなどとは別に、Anthropic Consoleで取得します。
                </p>
            </div>
        </details>
    );
}
