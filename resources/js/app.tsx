import { createInertiaApp } from '@inertiajs/react';
import { LaravelReactI18nProvider } from 'laravel-react-internationalization';
import { StrictMode } from 'react';
import { createRoot, hydrateRoot } from 'react-dom/client';
import { Toaster } from '@/components/ui/sonner';
import { TooltipProvider } from '@/components/ui/tooltip';
import { initializeTheme } from '@/hooks/use-appearance';
import AppLayout from '@/layouts/app-layout';
import AuthLayout from '@/layouts/auth-layout';
import SettingsLayout from '@/layouts/settings/layout';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name.startsWith('query/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    setup({ el, App, props }) {
        if (!el) {
            return;
        }

        const app = (
            <StrictMode>
                <LaravelReactI18nProvider
                    locale={String(props.initialPage.props.locale || 'nl')}
                    fallbackLocale={String(
                        props.initialPage.props.fallbackLocale || 'nl',
                    )}
                    files={import.meta.glob('/lang/*.json', {
                        eager: true,
                    })}
                >
                    <TooltipProvider delayDuration={0}>
                        <App {...props} />
                        <Toaster />
                    </TooltipProvider>
                </LaravelReactI18nProvider>
            </StrictMode>
        );

        if (el.hasAttribute('data-server-rendered')) {
            hydrateRoot(el, app);
        } else {
            createRoot(el).render(app);
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on load...
initializeTheme();
