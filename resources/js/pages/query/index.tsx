import { Head } from '@inertiajs/react';
import {
    ArrowRight,
    BarChart3,
    ChevronDown,
    Copy,
    Download,
    ExternalLink,
    LineChart,
    Plus,
    Share2,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
    Wrench,
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
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTranslation } from '@/hooks/use-translation';
import { downloadRows } from '@/lib/export-rows';
import { cn } from '@/lib/utils';

import { parseJson, postJson } from './api';
import { localeTag } from './format';
import { detectPlate, extractPlateFromText, formatPlate } from './plate';
import {
    clearRecentQueries,
    getRecentQueriesServerSnapshot,
    pushRecentQuery,
    readRecentQueries,
    subscribeToRecentQueries,
} from './recent-queries';
import { buildShareUrl, resetShareUrl, updateShareUrl } from './share-url';
import { formatResponseBody, SoQLHighlight } from './soql-highlight';
import type {
    ErrorResponse,
    QueryError,
    QueryResult,
    Rating,
    RunResponse,
    SharedRun,
} from './types';
import { useTypewriterPlaceholder } from './use-typewriter-placeholder';
import { ResultBody } from './views/result-body';

type PageProps = { sharedRun: SharedRun | null };

const MIN_PROMPT_LENGTH = 3;

// Curated against the planner's capabilities (single RegisteredVehicles
// dataset, no location/fuel fields). Each item maps to a clean display hint:
// count, bars, timeseries, table, record, stats, or histogram.
const SUGGESTIONS_NL: readonly string[] = [
    'Hoeveel Tesla Model 3 zijn er in Nederland?',
    'Hoeveel Ferrari’s zijn er geregistreerd?',
    'Hoeveel BMW M3 staan er op kenteken?',
    'Hoeveel Land Rover Defender zijn er in Nederland?',
    'Hoeveel Porsche 911 zijn er geregistreerd?',
    'Hoeveel campers staan er op kenteken?',
    'Hoeveel motorfietsen zwaarder dan 1000 cc?',
    'Hoeveel oranje voertuigen zijn er?',
    'Hoeveel Fiat 500’s uit 2015 zijn er?',
    'Hoeveel voertuigen ouder dan 30 jaar zijn er?',
    'Welke kleuren Volkswagen Up! uit 2017 zijn er?',
    'Kleurverdeling Audi A4 uit bouwjaar 2020',
    'Welke autokleur is het zeldzaamst?',
    'Welk merk heeft de meeste roze auto’s?',
    'Meest geregistreerde Tesla-modellen',
    'Top 10 populairste automerken',
    'Meest voorkomende kleuren in het hele register',
    'Volkswagen Golf-tenaamstellingen per maand in 2024',
    'Aantal Tesla’s per jaar sinds 2015',
    'Nieuwe Porsche-registraties per maand in 2024',
    'Aantal motorfietsen per bouwjaar sinds 2000',
    'Recente overschrijvingen Suzuki GSX-R 1100 uit 1991',
    'Top 5 zwaarste voertuigen op kenteken',
    'Top 10 snelste motorfietsen op kenteken',
    'Top 10 duurste Ferrari’s op catalogusprijs',
    'Recente Tesla-overschrijvingen',
    '10 oudste actieve motorfietsen',
    '10 nieuwste Bugatti’s op kenteken',
    'Toon alles over kenteken 7-RKL-23',
    'Toon alles over kenteken 1-ZTZ-08',
    'Toyota in cijfers: aantal, gemiddelde massa en gemiddelde catalogusprijs',
    'Statistieken Volkswagen Golf: aantal en gemiddelde massa',
    'BMW stats: aantal, gemiddelde topsnelheid en gemiddelde catalogusprijs',
    'Verdeling van leeg gewicht van Volkswagen Up!',
    'Hoe is de cilinderinhoud van motorfietsen verdeeld?',
    'Verdeling aantal zitplaatsen bij personenauto’s',
    'Hoeveel APK-keuringen verlopen deze maand?',
    'Hoeveel verzekerde Toyota’s zijn er?',
    'Hoeveel taxi’s staan er op kenteken?',
    'Hoeveel voertuigen wachten op keuring?',
];

const SUGGESTIONS_EN: readonly string[] = [
    'How many Tesla Model 3 are registered in the Netherlands?',
    'How many Ferraris are registered?',
    'How many BMW M3 in the Dutch register?',
    'How many Land Rover Defenders in the Netherlands?',
    'How many Porsche 911 are registered?',
    'How many campers are registered?',
    'How many motorcycles over 1000 cc?',
    'How many orange vehicles are there?',
    'How many 2015 Fiat 500s are there?',
    'How many vehicles over 30 years old?',
    'What colors of Volkswagen Up! from 2017 are out there?',
    'Color breakdown of Audi A4 from model year 2020',
    'What is the rarest car color?',
    'Which brand has the most pink cars?',
    'Most-registered Tesla models',
    'Top 10 most popular car brands',
    'Most common colors across the entire register',
    'Volkswagen Golf transfers per month in 2024',
    'Tesla registrations per year since 2015',
    'New Porsche registrations per month in 2024',
    'Motorcycles per model year since 2000',
    'Recent transfers for Suzuki GSX-R 1100 (1991)',
    'Top 5 heaviest registered vehicles',
    'Top 10 fastest registered motorcycles',
    'Top 10 most expensive Ferraris by catalog price',
    'Recent Tesla transfers',
    '10 oldest active motorcycles',
    '10 newest Bugattis in the register',
    'Show everything about plate 7-RKL-23',
    'Show everything about plate 1-ZTZ-08',
    'Toyota in numbers: count, average mass and average catalog price',
    'Stats on Volkswagen Golf: count and average mass',
    'BMW stats: count, average top speed and average catalog price',
    'Distribution of curb weight of Volkswagen Up!',
    'How is motorcycle engine displacement distributed?',
    'Distribution of seat counts across passenger cars',
    'How many MOT inspections expire this month?',
    'How many insured Toyotas are there?',
    'How many taxis are registered?',
    'How many vehicles are awaiting inspection?',
];

type DiscoverViz = 'kpi' | 'bars' | 'spark' | 'plate';

type DiscoverItem = { question: string; viz: DiscoverViz };

type DiscoverEntry = { nl: string; en: string };

// Curated example pools, grouped by the viz they'll render. We pick one
// random entry per viz so the four cards always span all four visual styles.
const DISCOVER_POOL: Readonly<Record<DiscoverViz, readonly DiscoverEntry[]>> = {
    kpi: [
        {
            nl: 'Hoeveel Tesla Model 3 zijn er in Nederland?',
            en: 'How many Tesla Model 3 are registered in the Netherlands?',
        },
        {
            nl: 'Hoeveel Ferrari’s zijn er geregistreerd?',
            en: 'How many Ferraris are registered?',
        },
        {
            nl: 'Hoeveel BMW M3 staan er op kenteken?',
            en: 'How many BMW M3 in the Dutch register?',
        },
        {
            nl: 'Hoeveel Land Rover Defender zijn er in Nederland?',
            en: 'How many Land Rover Defenders in the Netherlands?',
        },
        {
            nl: 'Hoeveel Porsche 911 zijn er geregistreerd?',
            en: 'How many Porsche 911 are registered?',
        },
        {
            nl: 'Hoeveel campers staan er op kenteken?',
            en: 'How many campers are registered?',
        },
        {
            nl: 'Hoeveel motorfietsen zwaarder dan 1000 cc?',
            en: 'How many motorcycles over 1000 cc?',
        },
        {
            nl: 'Hoeveel oranje voertuigen zijn er?',
            en: 'How many orange vehicles are there?',
        },
        {
            nl: 'Hoeveel Fiat 500’s uit 2015 zijn er?',
            en: 'How many 2015 Fiat 500s are there?',
        },
        {
            nl: 'Hoeveel voertuigen ouder dan 30 jaar zijn er?',
            en: 'How many vehicles over 30 years old?',
        },
        {
            nl: 'Hoeveel APK-keuringen verlopen deze maand?',
            en: 'How many MOT inspections expire this month?',
        },
        {
            nl: 'Hoeveel verzekerde Toyota’s zijn er?',
            en: 'How many insured Toyotas are there?',
        },
        {
            nl: 'Hoeveel taxi’s staan er op kenteken?',
            en: 'How many taxis are registered?',
        },
        {
            nl: 'Hoeveel voertuigen wachten op keuring?',
            en: 'How many vehicles are awaiting inspection?',
        },
    ],
    bars: [
        {
            nl: 'Top 10 populairste automerken',
            en: 'Top 10 most popular car brands',
        },
        {
            nl: 'Meest voorkomende kleuren in het hele register',
            en: 'Most common colors across the entire register',
        },
        {
            nl: 'Kleurverdeling Audi A4 uit bouwjaar 2020',
            en: 'Color breakdown of Audi A4 from model year 2020',
        },
        {
            nl: 'Welke kleuren Volkswagen Up! uit 2017 zijn er?',
            en: 'What colors of Volkswagen Up! from 2017 are out there?',
        },
        {
            nl: 'Welke autokleur is het zeldzaamst?',
            en: 'What is the rarest car color?',
        },
        {
            nl: 'Welk merk heeft de meeste roze auto’s?',
            en: 'Which brand has the most pink cars?',
        },
        {
            nl: 'Meest geregistreerde Tesla-modellen',
            en: 'Most-registered Tesla models',
        },
        {
            nl: 'Verdeling aantal zitplaatsen bij personenauto’s',
            en: 'Distribution of seat counts across passenger cars',
        },
        {
            nl: 'Verdeling van leeg gewicht van Volkswagen Up!',
            en: 'Distribution of curb weight of Volkswagen Up!',
        },
        {
            nl: 'Hoe is de cilinderinhoud van motorfietsen verdeeld?',
            en: 'How is motorcycle engine displacement distributed?',
        },
    ],
    spark: [
        {
            nl: 'Aantal Tesla’s per jaar sinds 2015',
            en: 'Tesla registrations per year since 2015',
        },
        {
            nl: 'Volkswagen Golf-tenaamstellingen per maand in 2024',
            en: 'Volkswagen Golf transfers per month in 2024',
        },
        {
            nl: 'Nieuwe Porsche-registraties per maand in 2024',
            en: 'New Porsche registrations per month in 2024',
        },
        {
            nl: 'Aantal motorfietsen per bouwjaar sinds 2000',
            en: 'Motorcycles per model year since 2000',
        },
    ],
    plate: [
        {
            nl: 'Toon alles over kenteken 7-RKL-23',
            en: 'Show everything about plate 7-RKL-23',
        },
        {
            nl: 'Toon alles over kenteken 1-ZTZ-08',
            en: 'Show everything about plate 1-ZTZ-08',
        },
    ],
};

const DISCOVER_VIZ_ORDER: readonly DiscoverViz[] = [
    'kpi',
    'bars',
    'spark',
    'plate',
];

function pickDiscoverItems(locale: string): DiscoverItem[] {
    return DISCOVER_VIZ_ORDER.map((viz) => {
        const pool = DISCOVER_POOL[viz];
        const entry = pool[Math.floor(Math.random() * pool.length)];

        return {
            viz,
            question: locale === 'nl' ? entry.nl : entry.en,
        };
    });
}

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

    return (
        <>
            <Head title={t('pages.query.title')} />
            <div className="rdw-app relative isolate flex min-h-screen flex-col overflow-x-hidden bg-background text-foreground">
                <div className="rdw-bg" aria-hidden="true" />
                <div className="rdw-grid" aria-hidden="true" />

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
    const animate = !compact && !focused && value === '';
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

    const plate = detectPlate(value);

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
                                <span
                                    className="rdw-plate rdw-fade-in"
                                    aria-hidden="true"
                                >
                                    <span className="rdw-plate-flag">NL</span>
                                    <span className="rdw-plate-text">
                                        {formatPlate(plate)}
                                    </span>
                                </span>
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
                    <span className="rdw-plate">
                        <span className="rdw-plate-flag">NL</span>
                        <span className="rdw-plate-text">
                            {extractPlateFromText(question) ?? '7-RKL-23'}
                        </span>
                    </span>
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
                            title={t('pages.query.editPrompt')}
                            className="flex-1 cursor-text rounded-md text-left text-foreground italic transition hover:bg-[var(--rdw-orange-faint)] hover:text-[var(--rdw-orange)] focus-visible:ring-2 focus-visible:ring-[var(--rdw-orange)]/40 focus-visible:outline-none"
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

// ─── Result view ──────────────────────────────────────────────
function ResultView({
    result,
    locale,
    onRatingChange,
}: {
    result: QueryResult;
    locale: string;
    onRatingChange: (next: {
        rating: Rating | null;
        comment: string | null;
    }) => void;
}) {
    const isUnsupported = result.displayHint === 'unsupported';

    return (
        <div className="flex flex-col gap-4">
            <div>
                <p className="max-w-[640px] text-sm leading-relaxed text-muted-foreground">
                    <strong className="font-semibold text-foreground">
                        {result.plan.explanation}
                    </strong>
                </p>
                <UsageLine result={result} locale={locale} />
            </div>

            <ResultBody result={result} locale={locale} />

            <ResultToolbar result={result} locale={locale} />

            {result.slug !== undefined && (
                <FeedbackPanel
                    key={result.slug}
                    slug={result.slug}
                    rating={result.rating}
                    comment={result.comment}
                    onChange={onRatingChange}
                />
            )}

            {!isUnsupported && (
                <QueryDebugPanel
                    soql={result.soql}
                    url={result.url}
                    model={result.model}
                />
            )}
        </div>
    );
}

function UsageLine({
    result,
    locale,
}: {
    result: QueryResult;
    locale: string;
}) {
    const { t } = useTranslation();
    const total =
        result.tokens.prompt +
        result.tokens.completion +
        result.tokens.cacheRead +
        result.tokens.thought;

    if (total === 0 && result.estimatedCost === null) {
        return null;
    }

    const tokensLabel = t('pages.query.tokensCount', {
        count: new Intl.NumberFormat(locale).format(total),
    });
    const costLabel =
        result.estimatedCost === null
            ? null
            : t('pages.query.estimatedCost', {
                  amount: new Intl.NumberFormat(locale, {
                      style: 'currency',
                      currency: 'USD',
                      minimumFractionDigits: 4,
                      maximumFractionDigits: 6,
                  }).format(result.estimatedCost),
              });

    const segments = [tokensLabel, costLabel].filter((s): s is string =>
        Boolean(s),
    );

    return (
        <p className="mt-1 text-[11.5px] tracking-wide text-muted-foreground/80">
            {segments.join(' · ')}
        </p>
    );
}

// ─── Toolbar (share, csv, json) ───────────────────────────────
function ResultToolbar({
    result,
    locale,
}: {
    result: QueryResult;
    locale: string;
}) {
    const { t } = useTranslation();
    const [, copy] = useClipboard();
    const hasRows = result.rows.length > 0;
    const shareUrl =
        result.slug !== undefined ? buildShareUrl(locale, result.slug) : null;

    return (
        <div className="flex flex-wrap items-center justify-end gap-2 border-t pt-3">
            {shareUrl && (
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    onClick={async () => {
                        const ok = await copy(shareUrl);

                        if (ok) {
                            toast.success(t('pages.query.shareCopied'));
                        } else {
                            toast.error(t('pages.query.shareFailed'));
                        }
                    }}
                >
                    <Share2 className="h-3 w-3" />
                    {t('pages.query.share')}
                </Button>
            )}

            {hasRows && (
                <>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            downloadRows(result.rows, 'csv', result.prompt)
                        }
                    >
                        <Download className="h-3 w-3" />
                        {t('pages.query.exportCsv')}
                    </Button>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        onClick={() =>
                            downloadRows(result.rows, 'json', result.prompt)
                        }
                    >
                        <Download className="h-3 w-3" />
                        {t('pages.query.exportJson')}
                    </Button>
                </>
            )}
        </div>
    );
}

// ─── Error view ──────────────────────────────────────────────
function ErrorView({ error }: { error: QueryError }) {
    return (
        <div className="flex flex-col gap-4">
            <div className="flex items-start gap-3">
                <span className="grid h-10 w-10 flex-shrink-0 place-items-center rounded-full bg-[var(--rdw-orange-faint)] text-[var(--rdw-orange)]">
                    <Wrench className="h-4 w-4" />
                </span>
                <p className="text-sm leading-relaxed text-[var(--rdw-orange)]">
                    {error.message}
                </p>
            </div>
            <QueryDebugPanel
                soql={error.soql}
                url={error.url}
                responseBody={error.responseBody}
                defaultOpen
            />
        </div>
    );
}

// ─── SoQL / URL debug panel ──────────────────────────────────
function QueryDebugPanel({
    soql,
    url,
    model,
    responseBody,
    defaultOpen = false,
}: {
    soql?: Record<string, string>;
    url?: string;
    model?: string;
    responseBody?: string | null;
    defaultOpen?: boolean;
}) {
    const { t } = useTranslation();
    const [, copy] = useClipboard();
    const hasResponseBody = responseBody !== undefined && responseBody !== null;
    const hasModel = model !== undefined && model !== '';

    if (
        soql === undefined &&
        url === undefined &&
        !hasResponseBody &&
        !hasModel
    ) {
        return null;
    }

    const soqlString =
        soql === undefined
            ? ''
            : Object.entries(soql)
                  .map(([k, v]) => `$${k}: ${v}`)
                  .join('\n');

    return (
        <Collapsible defaultOpen={defaultOpen}>
            <CollapsibleTrigger className="group inline-flex items-center gap-1.5 rounded-md px-2 py-1.5 text-[12.5px] text-muted-foreground transition hover:text-foreground">
                <span>{t('pages.query.showQuery')}</span>
                <ChevronDown className="h-3 w-3 transition-transform group-data-[state=open]:rotate-180" />
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-3 space-y-3 rounded-[12px] border bg-[color:color-mix(in_oklab,var(--background)_60%,transparent)] p-3.5 text-xs">
                {hasModel && (
                    <DebugSection label={t('pages.query.model')}>
                        <code className="block rounded bg-background/80 p-2 font-mono text-[11px]">
                            {model}
                        </code>
                    </DebugSection>
                )}
                {soql && (
                    <DebugSection
                        label={t('pages.query.soql')}
                        actions={
                            <CopyChip
                                onCopy={() => copy(soqlString)}
                                label="Copy"
                            />
                        }
                    >
                        <pre className="overflow-x-auto rounded bg-background/80 p-2.5 font-mono text-[11.5px] leading-relaxed whitespace-pre-wrap">
                            <SoQLHighlight value={soqlString} />
                        </pre>
                    </DebugSection>
                )}
                {url !== undefined && (
                    <DebugSection
                        label={t('pages.query.url')}
                        meta={
                            <span className="rounded bg-emerald-500/15 px-1.5 py-0.5 font-mono text-[10px] font-semibold tracking-wide text-emerald-500">
                                GET
                            </span>
                        }
                        actions={
                            <>
                                <CopyChip
                                    onCopy={() => copy(url)}
                                    label="Copy"
                                />
                                <a
                                    href={url}
                                    target="_blank"
                                    rel="noreferrer"
                                    className="inline-flex items-center gap-1 rounded border bg-card px-2 py-1 text-[11px] text-muted-foreground transition hover:border-[var(--rdw-orange)] hover:text-foreground"
                                >
                                    <ExternalLink className="h-3 w-3" />
                                    Open
                                </a>
                            </>
                        }
                    >
                        <div className="block overflow-x-auto rounded bg-background/80 p-2.5 font-mono text-[11px] leading-relaxed break-all whitespace-pre-wrap text-muted-foreground">
                            <span className="mr-1 text-muted-foreground/60">
                                ↳
                            </span>
                            {url}
                        </div>
                    </DebugSection>
                )}
                {hasResponseBody && (
                    <DebugSection label={t('pages.query.rdwResponse')}>
                        <pre className="overflow-x-auto rounded bg-background/80 p-2 font-mono text-[11px] leading-relaxed whitespace-pre-wrap">
                            {formatResponseBody(responseBody)}
                        </pre>
                    </DebugSection>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function DebugSection({
    label,
    meta,
    actions,
    children,
}: {
    label: string;
    meta?: React.ReactNode;
    actions?: React.ReactNode;
    children: React.ReactNode;
}) {
    return (
        <div className="flex flex-col gap-2">
            <div className="flex flex-wrap items-center gap-3">
                <span className="text-[10.5px] font-semibold tracking-[0.12em] whitespace-nowrap text-[var(--rdw-orange)] uppercase">
                    {label}
                </span>
                {meta}
                {actions !== undefined && (
                    <span className="ml-auto inline-flex items-center gap-1.5">
                        {actions}
                    </span>
                )}
            </div>
            {children}
        </div>
    );
}

function CopyChip({
    onCopy,
    label,
}: {
    onCopy: () => Promise<boolean> | boolean;
    label: string;
}) {
    const [copied, setCopied] = useState(false);

    return (
        <button
            type="button"
            onClick={async () => {
                const ok = await onCopy();

                if (ok) {
                    setCopied(true);
                    setTimeout(() => setCopied(false), 1500);
                }
            }}
            className="inline-flex items-center gap-1 rounded border bg-card px-2 py-1 text-[11px] text-muted-foreground transition hover:border-[var(--rdw-orange)] hover:text-foreground"
        >
            <Copy className="h-3 w-3" />
            {copied ? '✓' : label}
        </button>
    );
}

// ─── Feedback panel ──────────────────────────────────────────
function FeedbackPanel({
    slug,
    rating,
    comment,
    onChange,
}: {
    slug: string;
    rating: Rating | null;
    comment: string | null;
    onChange: (next: { rating: Rating | null; comment: string | null }) => void;
}) {
    const { t } = useTranslation();
    const [commentDraft, setCommentDraft] = useState(comment ?? '');
    const [submitting, setSubmitting] = useState(false);

    const submitFeedback = async (
        nextRating: Rating,
        nextComment: string | null,
    ): Promise<void> => {
        setSubmitting(true);

        try {
            const response = await postJson(`/api/query/${slug}/feedback`, {
                rating: nextRating,
                comment: nextComment,
            });

            if (!response.ok) {
                throw new Error('feedback failed');
            }

            onChange({ rating: nextRating, comment: nextComment });
            toast.success(t('pages.query.feedbackThanks'));
        } catch {
            toast.error(t('pages.query.feedbackFailed'));
        } finally {
            setSubmitting(false);
        }
    };

    return (
        <div className="flex flex-col gap-2 rounded-[12px] border bg-card/40 p-3 text-xs">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-muted-foreground">
                    {t('pages.query.feedbackPrompt')}
                </span>
                <div className="flex items-center gap-1.5">
                    <Button
                        type="button"
                        variant={rating === 'up' ? 'default' : 'outline'}
                        size="icon"
                        className="h-8 w-8"
                        disabled={submitting}
                        onClick={() =>
                            void submitFeedback('up', commentDraft || null)
                        }
                        aria-label={t('pages.query.feedbackHelpful')}
                    >
                        <ThumbsUp className="h-3.5 w-3.5" />
                    </Button>
                    <Button
                        type="button"
                        variant={rating === 'down' ? 'default' : 'outline'}
                        size="icon"
                        className="h-8 w-8"
                        disabled={submitting}
                        onClick={() =>
                            void submitFeedback('down', commentDraft || null)
                        }
                        aria-label={t('pages.query.feedbackNotHelpful')}
                    >
                        <ThumbsDown className="h-3.5 w-3.5" />
                    </Button>
                </div>
            </div>

            {rating !== null && (
                <div className="flex flex-col gap-2">
                    <Textarea
                        value={commentDraft}
                        onChange={(e) => setCommentDraft(e.target.value)}
                        placeholder={t(
                            rating === 'up'
                                ? 'pages.query.feedbackCommentPlaceholderPositive'
                                : 'pages.query.feedbackCommentPlaceholderNegative',
                        )}
                        rows={2}
                        className="resize-none text-xs"
                    />
                    <div className="flex justify-end">
                        <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            disabled={
                                submitting || commentDraft === (comment ?? '')
                            }
                            onClick={() =>
                                void submitFeedback(
                                    rating,
                                    commentDraft || null,
                                )
                            }
                        >
                            {t('pages.query.feedbackSubmit')}
                        </Button>
                    </div>
                </div>
            )}
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
    };
}
