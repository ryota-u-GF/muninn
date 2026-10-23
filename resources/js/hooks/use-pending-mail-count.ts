import { useEffect, useState } from 'react';
import { api } from '@/lib/api';

/** 未確認の候補の件数が変わったことを知らせる（detail: 件数） */
export const PENDING_COUNT_EVENT = 'gf:pending-count';

/** サイドバーの「候補」に出す、自分の未確認の候補の件数 */
export function usePendingMailCount(enabled: boolean): number {
    const [count, setCount] = useState(0);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        // 候補は外部システムから届くため、タブに戻ったときにも数え直す
        const refresh = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }
            api<{ data: { count: number } }>(
                'GET',
                '/api/imports/pending-count',
            )
                .then((res) => setCount(res.data.count))
                .catch(() => undefined);
        };
        const onCount = (e: Event) =>
            setCount((e as CustomEvent<number>).detail);

        refresh();
        document.addEventListener('visibilitychange', refresh);
        window.addEventListener(PENDING_COUNT_EVENT, onCount);

        return () => {
            document.removeEventListener('visibilitychange', refresh);
            window.removeEventListener(PENDING_COUNT_EVENT, onCount);
        };
    }, [enabled]);

    return enabled ? count : 0;
}
