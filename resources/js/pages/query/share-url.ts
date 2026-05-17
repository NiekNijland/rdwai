export function buildShareUrl(locale: string, slug: string): string {
    if (typeof window === 'undefined') {
        return `/${locale}?q=${encodeURIComponent(slug)}`;
    }

    const url = new URL(window.location.href);
    url.pathname = `/${locale}`;
    url.search = '';
    url.searchParams.set('q', slug);

    return url.toString();
}

export function updateShareUrl(locale: string, slug: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.history.replaceState({}, '', buildShareUrl(locale, slug));
}

export function resetShareUrl(locale: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.history.replaceState({}, '', `/${locale}`);
}
