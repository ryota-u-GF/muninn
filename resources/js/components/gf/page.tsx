import { Head } from '@inertiajs/react';
import { AlertTriangle, Loader2 } from 'lucide-react';
import type { ReactNode } from 'react';
import { Skeleton } from '@/components/ui/skeleton';
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
        <div className="space-y-4 py-4" role="status">
            <div className="flex items-center gap-2 text-sm text-muted-foreground">
                <Loader2 className="size-4 animate-spin" />
                {label}
            </div>
            <div
                aria-hidden="true"
                className="grid gap-3 md:grid-cols-3 motion-reduce:[&_*]:animate-none"
            >
                {[0, 1, 2].map((i) => (
                    <div
                        key={i}
                        className="space-y-2 rounded-lg bg-muted/50 p-2"
                    >
                        <Skeleton className="h-4 w-16" />
                        <Skeleton className="h-16 w-full" />
                        <Skeleton className="h-16 w-full" />
                    </div>
                ))}
            </div>
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
