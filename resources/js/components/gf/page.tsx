import { Head } from '@inertiajs/react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { cn } from '@/lib/utils';

export function Page({
    title,
    actions,
    children,
    className,
}: {
    title: string;
    actions?: ReactNode;
    children: ReactNode;
    className?: string;
}) {
    return (
        <div
            className={cn(
                'gf-page-enter flex min-w-0 flex-1 flex-col gap-4 p-4',
                className,
            )}
        >
            <Head title={title} />
            <div className="flex flex-wrap items-center justify-between gap-3">
                <h1 className="text-xl font-semibold">{title}</h1>
                {actions && (
                    <div className="flex flex-wrap items-center gap-2">
                        {actions}
                    </div>
                )}
            </div>
            {children}
        </div>
    );
}

export function ErrorBanner({
    message,
    onRetry,
}: {
    message: string | null;
    onRetry?: () => void;
}) {
    if (!message) {
        return null;
    }

    return (
        <div
            role="alert"
            className="flex items-center gap-2 rounded-md border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800 dark:border-red-800 dark:bg-red-950 dark:text-red-200"
        >
            <AlertTriangle className="size-4 shrink-0" />
            <span className="flex-1">{message}</span>
            {onRetry && (
                <button type="button" className="underline" onClick={onRetry}>
                    再読み込み
                </button>
            )}
        </div>
    );
}

export function Loading({ label = '読み込み中…' }: { label?: string }) {
    return (
        <div
            className="flex items-center gap-2 py-6 text-sm text-muted-foreground"
            role="status"
        >
            <Loader2 className="size-4 animate-spin" />
            {label}
        </div>
    );
}

export function Empty({ children }: { children: ReactNode }) {
    return (
        <div className="rounded-md border border-dashed px-4 py-8 text-center text-sm text-muted-foreground">
            {children}
        </div>
    );
}
