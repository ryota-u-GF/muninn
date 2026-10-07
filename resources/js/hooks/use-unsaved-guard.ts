import { router } from '@inertiajs/react';
import { useEffect } from 'react';

export const UNSAVED_MESSAGE =
    '保存していない変更があります。破棄して移動しますか？';

/** 未保存の編集があるとき、ページ離脱・画面遷移の前に確認する */
export function useUnsavedGuard(dirty: boolean) {
    useEffect(() => {
        if (!dirty) {
            return;
        }
        const onBeforeUnload = (event: BeforeUnloadEvent) => {
            event.preventDefault();
            event.returnValue = '';
        };
        window.addEventListener('beforeunload', onBeforeUnload);
        const removeRouterListener = router.on('before', (event) => {
            const visit = event.detail.visit;
            if (visit.method === 'get' && !window.confirm(UNSAVED_MESSAGE)) {
                event.preventDefault();
            }
        });

        return () => {
            window.removeEventListener('beforeunload', onBeforeUnload);
            removeRouterListener();
        };
    }, [dirty]);
}

export function confirmDiscard(dirty: boolean): boolean {
    return !dirty || window.confirm(UNSAVED_MESSAGE);
}
