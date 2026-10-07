import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            appInfo: {
                demo: boolean;
                googleConfigured: boolean;
                environment: string;
            };
            flash: { error?: string | null };
            sidebarOpen: boolean;
            [key: string]: unknown;
        };
    }
}
