import type { Auth } from '@/types/auth';

declare module '@inertiajs/core' {
    export interface InertiaConfig {
        sharedPageProps: {
            name: string;
            auth: Auth;
            sidebarOpen: boolean;
            locale: string;
            locales: Record<string, string>;
            fallbackLocale: string;
            [key: string]: unknown;
        };
    }
}
