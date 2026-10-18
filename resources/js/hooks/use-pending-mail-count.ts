import { useEffect, useState } from 'react';
import {
    MAIL_SYNCED_EVENT,
    PENDING_COUNT_EVENT,
} from '@/hooks/use-auto-gmail-sync';
import { api } from '@/lib/api';

/** サイドバーの「メール」に出す、自分の未確認の候補の件数 */
export function usePendingMailCount(enabled: boolean): number {
    const [count, setCount] = useState(0);

    useEffect(() => {
        if (!enabled) {
            return;
        }

        const refresh = () => {
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
        window.addEventListener(MAIL_SYNCED_EVENT, refresh);
        window.addEventListener(PENDING_COUNT_EVENT, onCount);

        return () => {
            window.removeEventListener(MAIL_SYNCED_EVENT, refresh);
            window.removeEventListener(PENDING_COUNT_EVENT, onCount);
        };
    }, [enabled]);

    return enabled ? count : 0;
}
