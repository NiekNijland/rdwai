import { Head } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    Github,
    LineChart,
    Plus,
    Sparkles,
    X,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useMemo,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import { toast } from 'sonner';

import { LanguageSwitcher } from '@/components/language-switcher';
import { ThemeToggle } from '@/components/theme-toggle';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Textarea } from '@/components/ui/textarea';
import { usePrefersReducedMotion } from '@/hooks/use-reduced-motion';
import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

import { parseJson, postJson } from './api';
import { ErrorView, ResultView } from './components/result-view';
import { pickDiscoverItems, SUGGESTIONS_EN, SUGGESTIONS_NL } from './examples';
import type { DiscoverItem, DiscoverViz } from './examples';
import { localeTag } from './format';
import {
    extractPlateFromText,
    isMotorcyclePlate,
    splitPlateLines,
} from './plate';
import {
    clearRecentQueries,
    getRecentQueriesServerSnapshot,
    pushRecentQuery,
    readRecentQueries,
    subscribeToRecentQueries,
} from './recent-queries';
import { resetShareUrl, updateShareUrl } from './share-url';
import type {
    ErrorResponse,
    QueryError,
    QueryResult,
    Rating,
    RunResponse,
    SharedRun,
} from './types';
import { useTypewriterPlaceholder } from './use-typewriter-placeholder';

type PageProps = { sharedRun: SharedRun | null };

const MIN_PROMPT_LENGTH = 3;

type SessionStats = {
    runs: number;
    lastLatencyMs: number | null;
    lastTokens: number | null;
};

const INITIAL_SESSION_STATS: SessionStats = {
    runs: 0,
    lastLatencyMs: null,
    lastTokens: null,
};

function fallbackErrorForStatus(
    status: number,
    t: (key: string) => string,
): string {
    if (status === 429) {
        return t('pages.query.errors.rateLimited');
    }

    if (status === 422) {
        return t('pages.query.errors.rejected');
    }

    if (status === 419) {
        return t('pages.query.errors.sessionExpired');
    }

    if (status === 504) {
        return t('pages.query.errors.timeout');
    }

    if (status >= 500) {
        return t('pages.query.errors.server');
    }

    return t('pages.query.errors.failed');
}

export default function QueryPage({ sharedRun }: PageProps) {
    return (
        <QueryPageInner
            key={sharedRun?.slug ?? 'fresh'}
            sharedRun={sharedRun}
        />
    );
}

function QueryPageInner({ sharedRun }: PageProps) {
    const { t, currentLocale } = useTranslation();
    const locale = currentLocale();

    const [prompt, setPrompt] = useState(sharedRun?.prompt ?? '');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<QueryResult | null>(
        sharedRun ? sharedRunToResult(sharedRun) : null,
    );
    const [error, setError] = useState<QueryError | null>(null);
    const [sessionStats, setSessionStats] = useState<SessionStats>(
        INITIAL_SESSION_STATS,
    );
    const recent = useSyncExternalStore(
        subscribeToRecentQueries,
        readRecentQueries,
        getRecentQueriesServerSnapshot,
    );
    const abortRef = useRef<AbortController | null>(null);
    const taRef = useRef<HTMLTextAreaElement>(null);

    useEffect(
        () => () => {
            abortRef.current?.abort();
        },
        [],
    );

    // Auto-resize textarea
    useEffect(() => {
        const el = taRef.current;

        if (!el) {
            return;
        }

        el.style.height = 'auto';
        el.style.height = Math.min(220, el.scrollHeight) + 'px';
    }, [prompt]);

    const submit = async (overridePrompt?: string) => {
        const value = (overridePrompt ?? prompt).trim();

        if (value.length < MIN_PROMPT_LENGTH) {
            return;
        }

        abortRef.current?.abort();
        const controller = new AbortController();
        abortRef.current = controller;

        setLoading(true);
        setResult(null);
        setError(null);

        const startedAt =
            typeof performance !== 'undefined' ? performance.now() : Date.now();

        try {
            const response = await postJson(
                '/api/query',
                { prompt: value },
                controller.signal,
            );

            const data = await parseJson(response);

            if (!response.ok) {
                const errorData =
                    data && typeof data === 'object'
                        ? (data as ErrorResponse)
                        : {};
                const errorMessage =
                    errorData.error ??
                    fallbackErrorForStatus(response.status, t);
                toast.error(errorMessage);
                setError({
                    message: errorMessage,
                    soql: errorData.soql,
                    url: errorData.url,
                    responseBody: errorData.responseBody,
                });

                return;
            }

            const runData = data as RunResponse;
            const finishedAt =
                typeof performance !== 'undefined'
                    ? performance.now()
                    : Date.now();
            const latencyMs = Math.max(0, Math.round(finishedAt - startedAt));
            const totalTokens =
                runData.tokens.prompt +
                runData.tokens.completion +
                runData.tokens.cacheRead +
                runData.tokens.thought;

            setResult({
                ...runData,
                prompt: value,
                rating: null,
                comment: null,
            });
            setSessionStats((prev) => ({
                runs: prev.runs + 1,
                lastLatencyMs: latencyMs,
                lastTokens: totalTokens > 0 ? totalTokens : prev.lastTokens,
            }));
            pushRecentQuery(value);
            updateShareUrl(locale, runData.slug);
        } catch (e) {
            if (e instanceof DOMException && e.name === 'AbortError') {
                return;
            }

            const message =
                e instanceof Error
                    ? e.message
                    : t('pages.query.errors.network');
            toast.error(message);
            setError({ message });
        } finally {
            if (abortRef.current === controller) {
                abortRef.current = null;
                setLoading(false);
            }
        }
    };

    const clear = (): void => {
        abortRef.current?.abort();
        abortRef.current = null;
        setPrompt('');
        setResult(null);
        setError(null);
        setLoading(false);
        resetShareUrl(locale);
    };

    const canClear = prompt.length > 0 || result !== null || error !== null;
    const hasResult = result !== null || error !== null || loading;

    const updateRating = useCallback(
        (next: { rating: Rating | null; comment: string | null }): void => {
            setResult((current) =>
                current === null
                    ? current
                    : {
                          ...current,
                          rating: next.rating,
                          comment: next.comment,
                      },
            );
        },
        [],
    );

    const suggestions = locale === 'nl' ? SUGGESTIONS_NL : SUGGESTIONS_EN;
    const discoverItems = useMemo(() => pickDiscoverItems(locale), [locale]);

    // Screen readers can't see the result swap in, so announce the high-level
    // status politely. Errors already surface through the toast's own live
    // region, so they're intentionally left out here.
    const liveMessage = loading
        ? t('pages.query.thinking')
        : result !== null
          ? result.plan.explanation
          : '';

    return (
        <>
            <Head title={t('pages.query.title')} />
            <div className="rdw-app relative isolate flex min-h-screen flex-col overflow-x-hidden bg-background text-foreground">
                <div className="rdw-bg" aria-hidden="true" />
                <div className="rdw-grid" aria-hidden="true" />

                <p className="sr-only" role="status" aria-live="polite">
                    {liveMessage}
                </p>

                <TopBar />

                <main
                    className={cn(
                        'relative z-10 mx-auto flex w-full max-w-5xl flex-1 flex-col items-center px-6 pb-32',
                        hasResult ? 'pt-2' : 'pt-3',
                    )}
                >
                    <EyebrowChip compact={hasResult} />

                    <div className="flex w-full max-w-[880px] flex-col items-center text-center">
                        <Hero compact={hasResult} />

                        <ComposerCard
                            taRef={taRef}
                            value={prompt}
                            setValue={setPrompt}
                            onSubmit={() => void submit()}
                            onClear={canClear ? clear : undefined}
                            busy={loading}
                            compact={hasResult}
                            placeholderSuggestions={suggestions}
                        />

                        {!hasResult && (
                            <DiscoverCards
                                items={discoverItems}
                                onPick={(q) => {
                                    setPrompt(q);
                                    void submit(q);
                                }}
                            />
                        )}

                        {!hasResult && recent.length > 0 && (
                            <RecentChips
                                items={recent}
                                onPick={(q) => {
                                    setPrompt(q);
                                    void submit(q);
                                }}
                                onClearAll={() => clearRecentQueries()}
                            />
                        )}

                        {loading && (
                            <ResultPanel query={prompt}>
                                <LoadingSkeleton />
                            </ResultPanel>
                        )}

                        {!loading && result && (
                            <ResultPanel
                                query={result.prompt}
                                live
                                onEditPrompt={() => {
                                    setPrompt(result.prompt);
                                    taRef.current?.focus();
                                    taRef.current?.setSelectionRange(
                                        result.prompt.length,
                                        result.prompt.length,
                                    );
                                }}
                            >
                                <ResultView
                                    result={result}
                                    locale={locale}
                                    onRatingChange={updateRating}
                                />
                            </ResultPanel>
                        )}

                        {!loading && error && (
                            <ResultPanel query={prompt}>
                                <ErrorView error={error} />
                            </ResultPanel>
                        )}
                    </div>
                </main>

                <StatStrip stats={sessionStats} locale={locale} />
            </div>
        </>
    );
}

// ─── Top bar ───────────────────────────────────────────────────
function TopBar() {
    const { t } = useTranslation();

    return (
        <header className="relative z-20 flex items-center justify-between px-6 py-5 md:px-8">
            <a
                href="/"
                aria-label={t('pages.query.homeAriaLabel')}
                className="inline-flex items-center gap-2.5 text-foreground no-underline"
            >
                <span
                    className={cn(
                        'relative grid h-9 w-9 place-items-center overflow-hidden rounded-[11px] text-base font-bold tracking-tight',
                        'bg-[var(--rdw-orange)] text-white',
                        'shadow-[0_10px_32px_-10px_var(--rdw-orange-glow),inset_0_0_0_1px_rgba(255,255,255,0.22),inset_0_-10px_18px_-8px_rgba(0,0,0,0.18)]',
                    )}
                    aria-hidden="true"
                >
                    R
                    <span className="pointer-events-none absolute inset-x-[-10%] top-[38%] h-px bg-gradient-to-r from-transparent via-white/45 to-transparent" />
                    <span className="pointer-events-none absolute inset-x-[-10%] top-[62%] h-0.5 bg-gradient-to-r from-transparent via-white/85 to-transparent" />
                </span>
                <span className="leading-[1.05]">
                    <span className="block text-[17px] font-bold tracking-tight">
                        RDW
                        <span className="text-[var(--rdw-orange)]">.AI</span>
                    </span>
                    <span className="block text-xs text-muted-foreground tabular-nums">
                        rdw.nijland.cc
                    </span>
                </span>
            </a>
            <div className="inline-flex items-center gap-2">
                <ThemeToggle />
                <LanguageSwitcher />
            </div>
        </header>
    );
}

// ─── Eyebrow chip ──────────────────────────────────────────────
function EyebrowChip({ compact }: { compact: boolean }) {
    const { t } = useTranslation();

    return (
        <div
            className={cn(
                'inline-flex items-center gap-2 rounded-full border bg-card/60 px-3.5 py-1.5 text-[12.5px] font-medium text-muted-foreground backdrop-blur',
                compact ? 'mb-2.5' : 'mb-5',
            )}
        >
            <span className="rdw-pulse" aria-hidden="true" />
            <Sparkles
                className="h-3 w-3 text-[var(--rdw-orange)]"
                aria-hidden="true"
            />
            <span>{t('pages.query.poweredBy')}</span>
        </div>
    );
}

// ─── Hero ──────────────────────────────────────────────────────
const HERO_ACCENT_PLACEHOLDER = '{accent}';

function Hero({ compact }: { compact: boolean }) {
    const { t } = useTranslation();
    const template = t('pages.query.hero');
    const accent = t('pages.query.heroAccent');
    const placeholderIndex = template.indexOf(HERO_ACCENT_PLACEHOLDER);
    const before =
        placeholderIndex === -1
            ? template
            : template.slice(0, placeholderIndex);
    const after =
        placeholderIndex === -1
            ? ''
            : template.slice(placeholderIndex + HERO_ACCENT_PLACEHOLDER.length);

    return (
        <header className="flex flex-col items-center">
            <h1
                className={cn(
                    'm-0 font-bold tracking-[-0.04em] text-balance transition-[font-size,line-height] duration-300 ease-out',
                    compact
                        ? 'text-[clamp(1.4rem,2.6vw,1.85rem)] leading-[1.05]'
                        : 'text-[clamp(2.4rem,6.2vw,4.8rem)] leading-[0.96]',
                )}
            >
                {before}
                <span className="pr-[0.15em] font-bold text-[var(--rdw-orange)] italic">
                    {accent}
                </span>
                {after}
            </h1>
            {!compact && (
                <p className="mt-4 max-w-[540px] text-base leading-[1.5] text-balance text-muted-foreground sm:text-[1.05rem]">
                    {t('pages.query.description')}
                </p>
            )}
        </header>
    );
}

// ─── Composer ──────────────────────────────────────────────────
function ComposerCard({
    taRef,
    value,
    setValue,
    onSubmit,
    onClear,
    busy,
    compact,
    placeholderSuggestions,
}: {
    taRef: React.RefObject<HTMLTextAreaElement | null>;
    value: string;
    setValue: (v: string) => void;
    onSubmit: () => void;
    onClear?: () => void;
    busy: boolean;
    compact: boolean;
    placeholderSuggestions: readonly string[];
}) {
    const { t } = useTranslation();
    const staticPlaceholder = t('pages.query.placeholder');
    const [focused, setFocused] = useState(false);
    const reducedMotion = usePrefersReducedMotion();
    // Hold the static placeholder still for users who opt out of motion.
    const animate = !compact && !focused && value === '' && !reducedMotion;
    const typed = useTypewriterPlaceholder(
        placeholderSuggestions,
        animate,
        staticPlaceholder,
    );

    const handleKey = (e: React.KeyboardEvent<HTMLTextAreaElement>) => {
        if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
            e.preventDefault();
            onSubmit();
        }
    };

    // Scan the whole question for a plate rather than requiring the field to be
    // a bare plate — users type it inside a sentence ("… op de weg? 1-ZTZ-08?").
    const plate = extractPlateFromText(value);

    return (
        <div
            className={cn(
                'rdw-composer-wrap relative w-full max-w-[780px]',
                compact ? 'mt-3' : 'mt-7',
            )}
        >
            <div className="rdw-composer-glow" aria-hidden="true" />
            <div className="rdw-composer-shell">
                <div className="rdw-composer-inner relative flex flex-col px-5 pt-4 pb-3">
                    <Textarea
                        ref={taRef}
                        value={value}
                        onChange={(e) => setValue(e.target.value)}
                        onFocus={() => setFocused(true)}
                        onBlur={() => setFocused(false)}
                        onKeyDown={handleKey}
                        placeholder={animate ? typed : staticPlaceholder}
                        rows={1}
                        aria-label={staticPlaceholder}
                        className={cn(
                            'min-h-0 resize-none border-0 bg-transparent p-0 leading-relaxed shadow-none focus-visible:ring-0 focus-visible:ring-offset-0 dark:bg-transparent',
                            compact
                                ? 'text-[15.5px]'
                                : 'text-[17px] md:text-[18px]',
                        )}
                    />
                    <div className="mt-3 flex items-center justify-between gap-3 border-t pt-3">
                        <div className="inline-flex items-center gap-3 text-xs whitespace-nowrap text-muted-foreground">
                            {plate !== null ? (
                                <PlateChip
                                    plate={plate}
                                    className="rdw-fade-in"
                                />
                            ) : (
                                <>
                                    <span className="inline-flex items-center gap-1">
                                        <kbd className="rdw-kbd">⌘</kbd>
                                        <kbd className="rdw-kbd">↵</kbd>
                                    </span>
                                    <span className="hidden sm:inline">
                                        {t('pages.query.submitHint')}
                                    </span>
                                </>
                            )}
                        </div>
                        <div className="flex items-center gap-2">
                            {onClear && (
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    onClick={onClear}
                                    disabled={busy}
                                    className="text-muted-foreground"
                                >
                                    <X className="h-3.5 w-3.5" />
                                    <span className="hidden sm:inline">
                                        {t('pages.query.clearAll')}
                                    </span>
                                </Button>
                            )}
                            <Button
                                type="button"
                                onClick={onSubmit}
                                disabled={
                                    busy ||
                                    value.trim().length < MIN_PROMPT_LENGTH
                                }
                                className={cn(
                                    'gap-2 rounded-[12px] bg-[var(--rdw-orange)] text-white hover:bg-[#ff6c37]',
                                    'shadow-[0_1px_0_rgba(255,255,255,0.18)_inset,0_-1px_0_rgba(0,0,0,0.12)_inset,0_8px_20px_-6px_var(--rdw-orange-glow)]',
                                    'focus-visible:ring-[var(--rdw-orange)]/40',
                                )}
                            >
                                {busy ? (
                                    <>
                                        <span
                                            className="rdw-spinner"
                                            style={{
                                                width: 14,
                                                height: 14,
                                                borderWidth: 2,
                                            }}
                                            aria-hidden="true"
                                        />
                                        {t('pages.query.thinking')}
                                    </>
                                ) : (
                                    <>
                                        {t('pages.query.ask')}
                                        <ArrowRight className="h-3.5 w-3.5" />
                                    </>
                                )}
                            </Button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    );
}

// ─── Discover cards (idle) ────────────────────────────────────
function DiscoverCards({
    items,
    onPick,
}: {
    items: DiscoverItem[];
    onPick: (q: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="mt-6 flex w-full max-w-[880px] flex-col gap-2.5">
            <div className="flex items-baseline justify-between gap-3 px-0.5">
                <span className="text-[11px] font-semibold tracking-[0.18em] text-[var(--rdw-orange)] uppercase">
                    {t('pages.query.popular')}
                </span>
                <span className="text-[11.5px] whitespace-nowrap text-muted-foreground/70">
                    {t('pages.query.submitHint')}
                </span>
            </div>
            <div className="grid grid-cols-1 gap-2.5 sm:grid-cols-2 lg:grid-cols-4">
                {items.map((item) => (
                    <DiscoverCard
                        key={item.question}
                        question={item.question}
                        viz={item.viz}
                        onPick={onPick}
                    />
                ))}
            </div>
        </div>
    );
}

const VIZ_LABEL_KEYS: Record<DiscoverViz, string> = {
    kpi: 'pages.query.viz.kpi',
    bars: 'pages.query.viz.bars',
    spark: 'pages.query.viz.spark',
    plate: 'pages.query.viz.plate',
};

function DiscoverCard({
    question,
    viz,
    onPick,
}: {
    question: string;
    viz: DiscoverViz;
    onPick: (q: string) => void;
}) {
    const { t } = useTranslation();

    return (
        <button
            type="button"
            onClick={() => onPick(question)}
            className={cn(
                'group text-left',
                'flex min-h-[132px] flex-col gap-2.5 rounded-[18px] border bg-card px-3.5 pt-3.5 pb-3 text-card-foreground',
                'transition-all duration-200',
                'hover:-translate-y-0.5 hover:border-[var(--rdw-orange)] hover:bg-card/80 hover:shadow-[0_12px_24px_-10px_rgba(0,0,0,0.45)]',
            )}
        >
            <div className="flex h-12 items-center">
                {viz === 'kpi' && (
                    <div className="flex items-baseline gap-1">
                        <span className="text-[26px] font-bold tracking-tight text-[var(--rdw-orange)] tabular-nums">
                            72.184
                        </span>
                    </div>
                )}
                {viz === 'bars' && (
                    <div className="flex w-full flex-col gap-1">
                        <span className="h-1.5 [width:92%] rounded-sm bg-[var(--rdw-orange)]" />
                        <span className="h-1.5 [width:64%] rounded-sm bg-[var(--rdw-orange)] opacity-[0.78]" />
                        <span className="h-1.5 [width:44%] rounded-sm bg-[var(--rdw-orange)] opacity-[0.56]" />
                        <span className="h-1.5 [width:28%] rounded-sm bg-[var(--rdw-orange)] opacity-[0.35]" />
                    </div>
                )}
                {viz === 'spark' && (
                    <svg
                        viewBox="0 0 100 40"
                        width="100%"
                        height="40"
                        preserveAspectRatio="none"
                        aria-hidden="true"
                    >
                        <path
                            d="M 0 32 L 14 28 L 28 22 L 42 24 L 56 16 L 70 12 L 84 6 L 100 4"
                            fill="none"
                            stroke="var(--rdw-orange)"
                            strokeWidth="2"
                            strokeLinejoin="round"
                            strokeLinecap="round"
                        />
                    </svg>
                )}
                {viz === 'plate' && (
                    <PlateChip
                        plate={extractPlateFromText(question) ?? 'GT-486-N'}
                    />
                )}
            </div>
            <span className="line-clamp-2 text-[13px] leading-snug font-medium text-foreground">
                {question}
            </span>
            <span className="mt-auto text-[10.5px] tracking-[0.06em] text-muted-foreground/80 uppercase">
                {t(VIZ_LABEL_KEYS[viz])}
            </span>
        </button>
    );
}

// ─── Plate chip ───────────────────────────────────────────────
// Renders the yellow NL plate badge. M-series plates are motorcycles, which
// carry a square two-line plate, so the value stacks instead of running wide.
function PlateChip({
    plate,
    className,
}: {
    plate: string;
    className?: string;
}) {
    if (isMotorcyclePlate(plate)) {
        const [top, bottom] = splitPlateLines(plate);

        return (
            <span
                className={cn('rdw-plate rdw-plate--moto', className)}
                aria-hidden="true"
            >
                <span className="rdw-plate-flag">NL</span>
                <span className="rdw-plate-text">
                    <span>{top}</span>
                    {bottom !== '' && <span>{bottom}</span>}
                </span>
            </span>
        );
    }

    return (
        <span className={cn('rdw-plate', className)} aria-hidden="true">
            <span className="rdw-plate-flag">NL</span>
            <span className="rdw-plate-text">{plate}</span>
        </span>
    );
}

// ─── Recent chips ─────────────────────────────────────────────
function RecentChips({
    items,
    onPick,
    onClearAll,
}: {
    items: string[];
    onPick: (q: string) => void;
    onClearAll: () => void;
}) {
    const { t } = useTranslation();

    return (
        <div className="mt-5 flex w-full max-w-[880px] flex-col items-center gap-2">
            <div className="flex items-center gap-2">
                <span className="text-xs text-muted-foreground">
                    {t('pages.query.recent')}
                </span>
                <button
                    type="button"
                    onClick={onClearAll}
                    className="text-xs text-muted-foreground underline-offset-2 hover:text-foreground hover:underline"
                >
                    {t('pages.query.clearRecent')}
                </button>
            </div>
            <div className="flex flex-wrap justify-center gap-1.5">
                {items.map((q) => (
                    <button
                        key={q}
                        type="button"
                        onClick={() => onPick(q)}
                        className="group inline-flex items-center gap-1.5 rounded-full border bg-card/60 px-3 py-1.5 text-[12.5px] text-muted-foreground transition hover:border-[var(--rdw-orange)] hover:bg-[var(--rdw-orange-faint)] hover:text-foreground"
                    >
                        <Plus className="h-3 w-3 text-[var(--rdw-orange)]" />
                        {q}
                    </button>
                ))}
            </div>
        </div>
    );
}

// ─── Result panel wrapper ─────────────────────────────────────
function ResultPanel({
    query,
    live,
    onEditPrompt,
    children,
}: {
    query: string;
    live?: boolean;
    onEditPrompt?: () => void;
    children: React.ReactNode;
}) {
    const { t } = useTranslation();
    const trimmed = query.trim();

    return (
        <div className="rdw-result-mount mt-7 flex w-full max-w-[880px] flex-col text-left">
            {trimmed !== '' && (
                <div className="flex items-baseline gap-2.5 px-1.5 pb-3 text-sm text-muted-foreground">
                    <span
                        aria-hidden="true"
                        className="translate-y-0.5 text-lg leading-none font-bold text-[var(--rdw-orange)]"
                    >
                        ↳
                    </span>
                    <span className="text-[10.5px] font-semibold tracking-[0.14em] whitespace-nowrap text-muted-foreground/70 uppercase">
                        {t('pages.query.youAsked')}
                    </span>
                    {onEditPrompt !== undefined ? (
                        <button
                            type="button"
                            onClick={onEditPrompt}
                            className="flex-1 cursor-text rounded-md text-left text-foreground italic decoration-[var(--rdw-orange)]/50 decoration-dotted underline-offset-[3px] transition-colors hover:text-[var(--rdw-orange)] hover:underline focus-visible:ring-2 focus-visible:ring-[var(--rdw-orange)]/40 focus-visible:outline-none"
                        >
                            "{trimmed}"
                        </button>
                    ) : (
                        <span className="flex-1 text-foreground italic">
                            "{trimmed}"
                        </span>
                    )}
                    {live === true && (
                        <Badge className="rounded-full border-emerald-500/30 bg-emerald-500/15 px-2.5 py-1 text-[11px] font-semibold text-emerald-500 hover:bg-emerald-500/15">
                            <span className="mr-1 inline-block h-1.5 w-1.5 rounded-full bg-emerald-500" />
                            {t('pages.query.liveData')}
                        </Badge>
                    )}
                </div>
            )}
            <div className="relative overflow-hidden rounded-[22px] border bg-card px-6 py-5 text-left text-card-foreground shadow-[0_20px_50px_-20px_rgba(0,0,0,0.4)]">
                <span className="rdw-accent-line" aria-hidden="true" />
                {children}
            </div>
        </div>
    );
}

// ─── Loading skeleton ─────────────────────────────────────────
function LoadingSkeleton() {
    const { t } = useTranslation();
    const stages = useMemo(
        () => [
            t('pages.query.loadingPlanning'),
            t('pages.query.loadingQuerying'),
            t('pages.query.loadingRendering'),
        ],
        [t],
    );
    const [stageIndex, setStageIndex] = useState(0);

    useEffect(() => {
        if (stageIndex >= stages.length - 1) {
            return;
        }

        const delay = stageIndex === 0 ? 700 : 1100;
        const timer = window.setTimeout(() => {
            setStageIndex((i) => Math.min(stages.length - 1, i + 1));
        }, delay);

        return () => window.clearTimeout(timer);
    }, [stageIndex, stages.length]);

    return (
        <div className="space-y-3">
            <div className="flex items-center gap-2.5 text-sm text-muted-foreground">
                <span className="rdw-spinner" aria-hidden="true" />
                <span key={stageIndex} className="rdw-fade-in">
                    {stages[stageIndex]}
                </span>
            </div>
            <div className="flex items-center gap-1.5 pt-0.5">
                {stages.map((_, i) => (
                    <span
                        key={i}
                        className={cn(
                            'h-1 flex-1 rounded-full transition-colors duration-300',
                            i <= stageIndex
                                ? 'bg-[var(--rdw-orange)]'
                                : 'bg-border',
                        )}
                    />
                ))}
            </div>
            <div className="space-y-2.5 pt-2">
                <div
                    className="rdw-skel h-3.5 rounded-sm"
                    style={{ width: '92%' }}
                />
                <div
                    className="rdw-skel h-3.5 rounded-sm"
                    style={{ width: '76%' }}
                />
                <div
                    className="rdw-skel h-3.5 rounded-sm"
                    style={{ width: '88%' }}
                />
                <div
                    className="rdw-skel h-3.5 rounded-sm"
                    style={{ width: '64%' }}
                />
            </div>
        </div>
    );
}

// ─── Stat strip (bottom) ─────────────────────────────────────
function StatStrip({ stats, locale }: { stats: SessionStats; locale: string }) {
    const { t } = useTranslation();
    const nf = new Intl.NumberFormat(localeTag(locale));
    const hasRuns = stats.runs > 0;

    return (
        <footer
            className={cn(
                'absolute right-0 bottom-0 left-0 z-10',
                'flex flex-wrap items-center justify-between gap-x-6 gap-y-1.5 border-t px-6 py-3 text-xs text-muted-foreground md:px-8',
                'bg-gradient-to-t from-background via-background/95 to-transparent',
            )}
        >
            <div className="inline-flex flex-wrap items-center gap-5">
                {hasRuns ? (
                    <>
                        <StatItem
                            value={nf.format(stats.runs)}
                            label={t('pages.query.sessionQueries')}
                        />
                        {stats.lastLatencyMs !== null && (
                            <StatItem
                                value={`${nf.format(stats.lastLatencyMs)} ms`}
                                label={t('pages.query.sessionLatency')}
                            />
                        )}
                        {stats.lastTokens !== null && stats.lastTokens > 0 && (
                            <StatItem
                                value={nf.format(stats.lastTokens)}
                                label={t('pages.query.sessionTokens')}
                            />
                        )}
                    </>
                ) : (
                    <span className="text-[11.5px] text-muted-foreground/60">
                        {t('pages.query.sessionEmpty')}
                    </span>
                )}
            </div>
            <div className="inline-flex items-center gap-2 font-mono text-[11.5px] tracking-wide">
                <span className="text-muted-foreground/60">
                    {t('pages.query.sourceLabel')}
                </span>
                <a
                    href="https://opendata.rdw.nl"
                    target="_blank"
                    rel="noopener noreferrer"
                    className="text-muted-foreground underline-offset-2 transition-colors hover:text-foreground hover:underline"
                >
                    opendata.rdw.nl
                </a>
                <span className="text-muted-foreground/40">·</span>
                <BarChart3 className="h-3 w-3 text-[var(--rdw-orange)]" />
                <span className="text-muted-foreground">SoQL</span>
                <span className="text-muted-foreground/40">·</span>
                <LineChart className="h-3 w-3 text-[var(--rdw-orange)]" />
                <span className="text-muted-foreground">AI</span>
                <span className="text-muted-foreground/40">·</span>
                <a
                    href="https://github.com/NiekNijland/rdwai"
                    target="_blank"
                    rel="noopener noreferrer"
                    aria-label="GitHub"
                    className="inline-flex items-center gap-1 text-muted-foreground underline-offset-2 transition-colors hover:text-foreground hover:underline"
                >
                    <Github className="h-3 w-3" />
                    <span>GitHub</span>
                </a>
            </div>
        </footer>
    );
}

function StatItem({ value, label }: { value: string; label: string }) {
    return (
        <span className="inline-flex items-baseline gap-1.5 whitespace-nowrap">
            <span className="text-[13px] font-semibold text-foreground tabular-nums">
                {value}
            </span>
            <span className="text-[11.5px] text-muted-foreground/70">
                {label}
            </span>
        </span>
    );
}

// ─── Helpers ─────────────────────────────────────────────────
function sharedRunToResult(run: SharedRun): QueryResult {
    return {
        slug: run.slug,
        prompt: run.prompt,
        plan: run.plan,
        soql: run.soql,
        url: run.url,
        rows: run.rows,
        displayHint: run.displayHint,
        rating: run.rating,
        comment: run.comment,
        model: run.model,
        tokens: run.tokens,
        estimatedCost: run.estimatedCost,
        steps: run.steps,
        presentation: run.presentation,
    };
}
