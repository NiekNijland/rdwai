const RECENT_QUERIES_KEY = 'rdwai:recent-queries';
const RECENT_QUERIES_MAX = 6;

// Module-level cache so useSyncExternalStore sees a stable reference when
// nothing has changed (React bails out of re-renders by identity).
const EMPTY_RECENT: string[] = [];
let cachedRecent: string[] | null = null;
const recentListeners = new Set<() => void>();

export function readRecentQueries(): string[] {
    if (typeof window === 'undefined') {
        return EMPTY_RECENT;
    }

    if (cachedRecent !== null) {
        return cachedRecent;
    }

    try {
        const raw = window.localStorage.getItem(RECENT_QUERIES_KEY);

        if (raw === null) {
            cachedRecent = EMPTY_RECENT;

            return cachedRecent;
        }

        const parsed: unknown = JSON.parse(raw);

        if (!Array.isArray(parsed)) {
            cachedRecent = EMPTY_RECENT;

            return cachedRecent;
        }

        cachedRecent = parsed
            .filter((v): v is string => typeof v === 'string')
            .slice(0, RECENT_QUERIES_MAX);

        return cachedRecent;
    } catch {
        cachedRecent = EMPTY_RECENT;

        return cachedRecent;
    }
}

export function getRecentQueriesServerSnapshot(): string[] {
    return EMPTY_RECENT;
}

export function subscribeToRecentQueries(callback: () => void): () => void {
    recentListeners.add(callback);

    const onStorage = (event: StorageEvent) => {
        if (event.key === RECENT_QUERIES_KEY) {
            cachedRecent = null;
            callback();
        }
    };

    if (typeof window !== 'undefined') {
        window.addEventListener('storage', onStorage);
    }

    return () => {
        recentListeners.delete(callback);

        if (typeof window !== 'undefined') {
            window.removeEventListener('storage', onStorage);
        }
    };
}

function notifyRecentChanged(next: string[]): void {
    cachedRecent = next;
    recentListeners.forEach((cb) => cb());
}

export function clearRecentQueries(): void {
    if (typeof window !== 'undefined') {
        try {
            window.localStorage.removeItem(RECENT_QUERIES_KEY);
        } catch {
            // localStorage unavailable; in-memory clear still works.
        }
    }

    notifyRecentChanged(EMPTY_RECENT);
}

export function pushRecentQuery(query: string): void {
    const trimmed = query.trim();
    const existing = readRecentQueries().filter((q) => q !== trimmed);
    const next = [trimmed, ...existing].slice(0, RECENT_QUERIES_MAX);

    if (typeof window !== 'undefined') {
        try {
            window.localStorage.setItem(
                RECENT_QUERIES_KEY,
                JSON.stringify(next),
            );
        } catch {
            // localStorage unavailable (private mode, quota); show recent
            // queries for this session only.
        }
    }

    notifyRecentChanged(next);
}
