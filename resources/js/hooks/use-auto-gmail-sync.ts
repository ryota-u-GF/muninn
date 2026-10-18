import { router, usePage } from '@inertiajs/react';
import { useEffect } from 'react';
import { toast } from 'sonner';
import { api, ApiError } from '@/lib/api';

/** 取得が終わったことを、メール画面・サイドバーに知らせる（候補・件数・最終取得日時を更新するため） */
export const MAIL_SYNCED_EVENT = 'gf:mail-synced';
/** 未確認の候補の件数が変わったことを知らせる（detail: 件数） */
export const PENDING_COUNT_EVENT = 'gf:pending-count';

type SyncResponse = {
    data: {
        status: 'done' | 'busy' | 'recent' | 'disabled';
        candidates: number;
        has_more: boolean;
    };
};

/** 画面から、サーバーへ「取得してよいか」を確認する間隔。実際に取得する間隔は、設定（5・10・30分）に従う。 */
const HEARTBEAT_MS = 60_000;

let lastRun = 0;
let running = false;
let lastToast = '';

/** 1 つの取り込み元（Gmail／Meet のメモ）を、残りがなくなるまで続けて呼ぶ。 */
async function syncSource(
    url: string,
): Promise<{ ran: boolean; created: number }> {
    let created = 0;
    let ran = false;
    for (let i = 0; i < 20; i++) {
        const res = await api<SyncResponse>('POST', url, { auto: true });
        if (res.data.status !== 'done') {
            break;
        }
        ran = true;
        created += res.data.candidates;
        if (!res.data.has_more) {
            break;
        }
    }

    return { ran, created };
}

async function tick(force = false) {
    if (document.visibilityState !== 'visible' || running) {
        return;
    }
    // 画面の切り替えで何度も呼ばれても、確認は 1 分に 1 回までにする
    if (!force && Date.now() - lastRun < HEARTBEAT_MS - 5_000) {
        return;
    }
    running = true;
    lastRun = Date.now();

    let created = 0;
    let ran = false;
    let failed = false;
    // 片方が失敗しても、もう片方は取り込む
    for (const [url, name] of [
        ['/api/gmail/sync', 'メール'],
        ['/api/meet/sync', 'Meetのメモ'],
    ]) {
        try {
            const result = await syncSource(url);
            ran ||= result.ran;
            created += result.created;
        } catch (e) {
            // 自動取得の失敗で作業を止めない。同じ内容の通知は繰り返さない。原因は「メール」画面にも表示される。
            failed = true;
            const ignored = [401, 403, 419];
            if (e instanceof ApiError && !ignored.includes(e.status)) {
                const message = `${name}の自動取得に失敗しました：${e.firstError()}`;
                if (message !== lastToast) {
                    lastToast = message;
                    toast.error(message);
                }
            }
        }
    }
    if (!failed) {
        lastToast = '';
    }

    try {
        if (ran) {
            window.dispatchEvent(new Event(MAIL_SYNCED_EVENT));
        }
        if (created > 0) {
            toast.success(
                `メール・議事録から${created}件のタスク候補を作成しました`,
                {
                    action: {
                        label: '確認する',
                        onClick: () => router.visit('/imports'),
                    },
                },
            );
        }
    } finally {
        running = false;
    }
}

/**
 * アプリを開いている間、社内メンバーの Gmail の新着メールと Meet のメモを定期的に取り込み、候補にする。
 * 開いたとき・タブに戻ったとき・1 分ごとに確認し、設定した間隔（既定 10 分）が過ぎていれば取得する。
 * 連携・AI のキー・自動取得の設定が揃っているときだけ動く。承認するまでタスクにはならない。
 */
export function useAutoGmailSync() {
    const role = usePage().props.auth.user?.role;

    useEffect(() => {
        if (role !== 'admin' && role !== 'employee') {
            return;
        }

        void tick();
        const timer = window.setInterval(() => void tick(true), HEARTBEAT_MS);
        const onVisible = () => void tick();
        document.addEventListener('visibilitychange', onVisible);

        return () => {
            window.clearInterval(timer);
            document.removeEventListener('visibilitychange', onVisible);
        };
    }, [role]);
}
