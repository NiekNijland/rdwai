import { router, usePage } from '@inertiajs/react';
import { Globe } from 'lucide-react';
import { useEffect, useRef, useState } from 'react';

import UpdateLocaleController from '@/actions/App/Http/Controllers/Auth/UpdateLocaleController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
    Tooltip,
    TooltipContent,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { useTranslation } from '@/hooks/use-translation';

export function LanguageSwitcher() {
    const { locales } = usePage().props;
    const { currentLocale, setLocale, t } = useTranslation();
    const locale = currentLocale();
    const [dropdownOpen, setDropdownOpen] = useState(false);
    const [tooltipSuppressed, setTooltipSuppressed] = useState(false);
    const [tooltipOpen, setTooltipOpen] = useState(false);
    const suppressTimer = useRef<ReturnType<typeof setTimeout>>(null);
    const shouldSuppressTooltip = dropdownOpen || tooltipSuppressed;

    useEffect(() => {
        return () => {
            clearTimeout(suppressTimer.current ?? undefined);
        };
    }, []);

    function switchLocale(newLocale: string) {
        if (newLocale === locale) {
            return;
        }

        setLocale(newLocale);

        const supportedLocales = Object.keys(locales);
        const pathSegments = window.location.pathname
            .split('/')
            .filter(Boolean);
        const hasLocalePrefix =
            pathSegments.length > 0 &&
            supportedLocales.includes(pathSegments[0]);

        if (!hasLocalePrefix) {
            // No locale in the URL: do a full Inertia POST so the backend can
            // update the session, the stored user locale, and redirect back
            // through SetLocale.
            router.post(
                UpdateLocaleController.url(),
                {
                    locale: newLocale,
                },
                {
                    preserveState: true,
                    preserveScroll: true,
                    onError: () => setLocale(locale),
                },
            );

            return;
        }

        // Locale is in the URL: fire-and-forget the backend update so the
        // authenticated user's stored preference (and the session/cookie)
        // stay in sync, then navigate to the new prefix.
        const token = csrfToken();
        void fetch(UpdateLocaleController.url(), {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(token ? { 'X-CSRF-TOKEN': token } : {}),
            },
            body: JSON.stringify({ locale: newLocale }),
        });

        pathSegments[0] = newLocale;
        const localizedUrl = `/${pathSegments.join('/')}${window.location.search}`;

        router.visit(localizedUrl, {
            preserveState: true,
            preserveScroll: true,
            onError: () => setLocale(locale),
        });
    }

    return (
        <DropdownMenu
            open={dropdownOpen}
            onOpenChange={(open) => {
                setDropdownOpen(open);
                setTooltipOpen(false);

                if (!open) {
                    setTooltipSuppressed(true);
                    clearTimeout(suppressTimer.current ?? undefined);
                    suppressTimer.current = setTimeout(() => {
                        if (document.activeElement instanceof HTMLElement) {
                            document.activeElement.blur();
                        }

                        setTooltipSuppressed(false);
                    }, 200);
                }
            }}
        >
            <Tooltip
                open={shouldSuppressTooltip ? false : tooltipOpen}
                onOpenChange={(open) => {
                    if (shouldSuppressTooltip) {
                        setTooltipOpen(false);

                        return;
                    }

                    setTooltipOpen(open);
                }}
            >
                <TooltipTrigger asChild>
                    <DropdownMenuTrigger asChild>
                        <Button
                            variant="ghost"
                            size="icon"
                            className="h-9 w-9 cursor-pointer"
                        >
                            <Globe className="size-5 opacity-80" />
                            <span className="sr-only">
                                {t('components.languageSwitcher.label')}
                            </span>
                        </Button>
                    </DropdownMenuTrigger>
                </TooltipTrigger>
                <TooltipContent>
                    <p>{t('components.languageSwitcher.label')}</p>
                </TooltipContent>
            </Tooltip>
            <DropdownMenuContent align="end">
                <DropdownMenuRadioGroup
                    value={locale}
                    onValueChange={switchLocale}
                >
                    {Object.entries(locales).map(([code, label]) => (
                        <DropdownMenuRadioItem key={code} value={code}>
                            {label}
                        </DropdownMenuRadioItem>
                    ))}
                </DropdownMenuRadioGroup>
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function csrfToken(): string | null {
    return (
        document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
            ?.content ?? null
    );
}
