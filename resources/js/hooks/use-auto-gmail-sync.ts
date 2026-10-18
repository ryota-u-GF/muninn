import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '@/lib/api';

/** 取得が終わったことを、メール画面などに知らせる（候補や最終取得日時を更新するため） */
export const MAIL_SYNCED_EVENT = 'gf:mail-synced';

type SyncResponse = {
    data: {
        status: 'done' | 'busy' | 'recent' | 'disabled';
        candidates: number;
        has_more: boolean;
    };
};

// このブラウザの画面を開いている間は、1 回だけ確認する（再読み込みしたときは、もう一度確認する）
let started = false;

async function run() {
    try {
        const [gmail, ai] = await Promise.all([
            api<{
                data: {
                    connected: boolean;
                    oauth_ready: boolean;
                    auto_sync: boolean;
                };
            }>('GET', '/api/gmail'),
            api<{ data: { configured: boolean } }>('GET', '/api/ai-settings'),
        ]);
        // 連携していない・自動取得がオフ・AI のキーが未設定のときは、何もしない（案内も出さない）
        if (
            !gmail.data.connected ||
            !gmail.data.oauth_ready ||
            !gmail.data.auto_sync ||
            !ai.data.configured
        ) {
            return;
        }

        let created = 0;
        let ran = false;
        // 1 回に少数ずつ処理するので、残りがなくなるまで続けて呼ぶ
        for (let i = 0; i < 20; i++) {
            const res = await api<SyncResponse>('POST', '/api/gmail/sync', {
                auto: true,
            });
            if (res.data.status !== 'done') {
                break;
            }
            ran = true;
            created += res.data.candidates;
            if (!res.data.has_more) {
                break;
            }
        }

        if (ran) {
            window.dispatchEvent(new Event(MAIL_SYNCED_EVENT));
        }
        if (created > 0) {
            toast.success(`メールから${created}件のタスク候補を作成しました`, {
                action: {
                    label: '確認する',
                    onClick: () => router.visit('/imports'),
                },
            });
        }
    } catch (e) {
        // 自動取得の失敗で作業を止めない。原因は「メール」画面に表示される。
        if (e instanceof ApiError) {
            toast.error(`メールの自動取得に失敗しました：${e.firstError()}`);
        }
    }
}

/**
 * アプリを開いたときに、社内メンバーの Gmail から新着メールを取り込み、候補にする。
 * 連携・AI のキー・自動取得の設定が揃っているときだけ動く。承認するまでタスクにはならない。
 */
export function useAutoGmailSync() {
    const role = usePage().props.auth.user?.role;

    useEffect(() => {
        if (started || (role !== 'admin' && role !== 'employee')) {
            return;
        }
        started = true;
        void run();
    }, [role]);
}
