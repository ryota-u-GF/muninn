import { useEffect, useState } from 'react';
import { api } from '@/lib/api';

/** 未確認の候補の件数が変わったことを知らせる（detail: { mail, minutes }） */
export const PENDING_COUNT_EVENT = 'gf:pending-count';

export type PendingCounts = { mail: number; minutes: number };

const NONE: PendingCounts = { mail: 0, minutes: 0 };

/** サイドバーの「メール」「議事録」に出す、自分の未確認の候補の件数 */
export function usePendingCandidateCounts(enabled: boolean): PendingCounts {
    const [counts, setCounts] = useState<PendingCounts>(NONE);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        // 候補は外部システムから届くため、タブに戻ったときにも数え直す
        const refresh = () => {
            if (document.visibilityState !== 'visible') {
                return;
            }
            api<{ data: PendingCounts }>('GET', '/api/imports/pending-count')
                .then((res) =>
                    setCounts({
                        mail: res.data.mail,
                        minutes: res.data.minutes,
                    }),
                )
                .catch(() => undefined);
        };
        const onCount = (e: Event) =>
            setCounts((e as CustomEvent<PendingCounts>).detail);

        refresh();
        document.addEventListener('visibilitychange', refresh);
        window.addEventListener(PENDING_COUNT_EVENT, onCount);

        return () => {
            document.removeEventListener('visibilitychange', refresh);
            window.removeEventListener(PENDING_COUNT_EVENT, onCount);
        };
    }, [enabled]);

    return enabled ? counts : NONE;
}
