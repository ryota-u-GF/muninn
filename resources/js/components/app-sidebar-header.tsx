import { usePage } from '@inertiajs/react';
import { SidebarTrigger } from '@/components/ui/sidebar';

export function AppSidebarHeader() {
    const { appInfo } = usePage().props;

    return (
        <header className="flex h-12 shrink-0 items-center gap-2 border-b border-sidebar-border/50 px-4">
            <SidebarTrigger className="-ml-1" />
            {appInfo.demo && (
                <span className="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-900 dark:bg-amber-900 dark:text-amber-100">
                    開発用デモ環境（デモデータ・実運用ではありません）
                </span>
            )}
        </header>
    );
}
