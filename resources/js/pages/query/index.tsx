import { Head } from '@inertiajs/react';
import {
    ChevronDown,
    Download,
    Share2,
    Sparkles,
    ThumbsDown,
    ThumbsUp,
} from 'lucide-react';
import {
    useCallback,
    useEffect,
    useRef,
    useState,
    useSyncExternalStore,
} from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    XAxis,
    YAxis,
} from 'recharts';
import { toast } from 'sonner';

import { LanguageSwitcher } from '@/components/language-switcher';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
} from '@/components/ui/card';
import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import {
    Collapsible,
    CollapsibleContent,
    CollapsibleTrigger,
} from '@/components/ui/collapsible';
import { Skeleton } from '@/components/ui/skeleton';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { Textarea } from '@/components/ui/textarea';
import { useClipboard } from '@/hooks/use-clipboard';
import { useTranslation } from '@/hooks/use-translation';
import { downloadRows } from '@/lib/export-rows';

type WhereClause = { field: string; op: string; value: string };
type AggregateClause = { fn: string; field: string | null; alias: string };
type OrderClause = { expr: string; direction: 'asc' | 'desc' };
type DisplayHint = 'count' | 'bars' | 'table' | 'record';
type Rating = 'up' | 'down';

type Plan = {
    where: WhereClause[];
    select: string[];
    groupBy: string[];
    aggregates: AggregateClause[];
    orderBy: OrderClause[];
    limit: number | null;
    display: DisplayHint;
    explanation: string;
};

type QueryRow = Record<string, unknown>;

type QueryResult = {
    slug?: string;
    prompt: string;
    plan: Plan;
    soql: Record<string, string>;
    url: string;
    rows: QueryRow[];
    displayHint: DisplayHint;
    rating: Rating | null;
    comment: string | null;
};

type SharedRun = {
    slug: string;
    prompt: string;
    locale: string;
    plan: Plan;
    soql: Record<string, string>;
    url: string;
    rows: QueryRow[];
    displayHint: DisplayHint;
    rating: Rating | null;
    comment: string | null;
};

type RunResponse = {
    slug: string;
    plan: Plan;
    soql: Record<string, string>;
    url: string;
    rows: QueryRow[];
    displayHint: DisplayHint;
};

type ErrorResponse = {
    error?: string;
    plan?: Plan;
    soql?: Record<string, string>;
    url?: string;
    responseBody?: string | null;
};

type QueryError = {
    message: string;
    soql?: Record<string, string>;
    url?: string;
    responseBody?: string | null;
};

type PageProps = { sharedRun: SharedRun | null };

const MIN_PROMPT_LENGTH = 3;
const RECENT_QUERIES_KEY = 'rdwai:recent-queries';
const RECENT_QUERIES_MAX = 6;

export default function QueryPage({ sharedRun }: PageProps) {
    // Remount on shared-run change so internal state resets cleanly — Inertia
    // keeps the same component instance across navigations otherwise.
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
    const [popular, setPopular] = useState<string[]>([]);
    const recent = useSyncExternalStore(
        subscribeToRecentQueries,
        readRecentQueries,
        getRecentQueriesServerSnapshot,
    );
    const abortRef = useRef<AbortController | null>(null);

    useEffect(
        () => () => {
            abortRef.current?.abort();
        },
        [],
    );

    useEffect(() => {
        const controller = new AbortController();

        fetch(`/api/query/popular?locale=${encodeURIComponent(locale)}`, {
            signal: controller.signal,
            headers: { Accept: 'application/json' },
        })
            .then((response) =>
                response.ok ? response.json() : { prompts: [] },
            )
            .then((data: { prompts?: unknown }) => {
                if (Array.isArray(data.prompts)) {
                    setPopular(
                        data.prompts.filter(
                            (v): v is string => typeof v === 'string',
                        ),
                    );
                }
            })
            .catch(() => {
                // Popular suggestions are non-critical; fail silently.
            });

        return () => controller.abort();
    }, [locale]);

    const fallbackErrorForStatus = (status: number): string => {
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
    };

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

        try {
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch('/api/query', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
                body: JSON.stringify({ prompt: value }),
                signal: controller.signal,
            });

            const data = await parseJson(response);

            if (!response.ok) {
                const errorData =
                    data && typeof data === 'object'
                        ? (data as ErrorResponse)
                        : {};
                const errorMessage =
                    errorData.error ?? fallbackErrorForStatus(response.status);
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
            setResult({
                ...runData,
                prompt: value,
                rating: null,
                comment: null,
            });
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

    return (
        <>
            <Head title={t('pages.query.title')} />
            <div className="min-h-screen bg-gradient-to-b from-neutral-50 to-neutral-100 px-4 py-12 dark:from-neutral-950 dark:to-neutral-900">
                <div className="absolute top-4 right-4">
                    <LanguageSwitcher />
                </div>
                <div className="mx-auto flex max-w-3xl flex-col gap-6">
                    <header className="text-center">
                        <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-neutral-200 bg-white/70 px-3 py-1 text-xs text-neutral-600 backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/70 dark:text-neutral-400">
                            <Sparkles className="h-3 w-3" />
                            {t('pages.query.poweredBy')}
                        </div>
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            {t('pages.query.heading')}
                        </h1>
                        <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                            {t('pages.query.description')}
                        </p>
                    </header>

                    <Card>
                        <CardContent className="space-y-4 pt-6">
                            <Textarea
                                value={prompt}
                                onChange={(e) => setPrompt(e.target.value)}
                                onKeyDown={(e) => {
                                    if (
                                        e.key === 'Enter' &&
                                        (e.metaKey || e.ctrlKey)
                                    ) {
                                        e.preventDefault();
                                        void submit();
                                    }
                                }}
                                placeholder={t('pages.query.placeholder')}
                                rows={3}
                                className="resize-none text-base"
                            />
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs text-neutral-500">
                                    {t('pages.query.submitHint')}
                                </span>
                                <Button
                                    onClick={() => void submit()}
                                    disabled={
                                        loading ||
                                        prompt.trim().length < MIN_PROMPT_LENGTH
                                    }
                                >
                                    {loading
                                        ? t('pages.query.thinking')
                                        : t('pages.query.ask')}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {!result && !error && !loading && (
                        <SuggestionsList
                            popular={popular}
                            recent={recent}
                            onPick={(q) => {
                                setPrompt(q);
                                void submit(q);
                            }}
                        />
                    )}

                    {loading && <LoadingSkeleton />}

                    {result && (
                        <ResultView
                            result={result}
                            locale={locale}
                            onRatingChange={updateRating}
                        />
                    )}

                    {error && !loading && <ErrorView error={error} />}
                </div>
            </div>
        </>
    );
}

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
    };
}

function updateShareUrl(locale: string, slug: string): void {
    if (typeof window === 'undefined') {
        return;
    }

    window.history.replaceState({}, '', buildShareUrl(locale, slug));
}

function SuggestionsList({
    popular,
    recent,
    onPick,
}: {
    popular: string[];
    recent: string[];
    onPick: (q: string) => void;
}) {
    const { t } = useTranslation();

    if (popular.length === 0 && recent.length === 0) {
        return null;
    }

    return (
        <div className="flex flex-col gap-4">
            {popular.length > 0 && (
                <SuggestionRow
                    label={t('pages.query.popular')}
                    items={popular}
                    onPick={onPick}
                />
            )}

            {recent.length > 0 && (
                <SuggestionRow
                    label={t('pages.query.recent')}
                    items={recent}
                    onPick={onPick}
                    trailing={
                        <button
                            type="button"
                            onClick={() => clearRecentQueries()}
                            className="text-xs text-neutral-500 underline-offset-2 hover:text-neutral-700 hover:underline dark:hover:text-neutral-300"
                        >
                            {t('pages.query.clearRecent')}
                        </button>
                    }
                />
            )}
        </div>
    );
}

function SuggestionRow({
    label,
    items,
    onPick,
    trailing,
}: {
    label: string;
    items: string[];
    onPick: (q: string) => void;
    trailing?: React.ReactNode;
}) {
    return (
        <div className="flex flex-col items-center gap-2">
            <div className="flex items-center gap-2">
                <span className="text-xs text-neutral-500">{label}</span>
                {trailing}
            </div>
            <div className="flex flex-wrap justify-center gap-2">
                {items.map((q) => (
                    <button
                        key={q}
                        type="button"
                        onClick={() => onPick(q)}
                        className="group"
                    >
                        <Badge
                            variant="outline"
                            className="cursor-pointer text-xs font-normal transition group-hover:bg-neutral-100 dark:group-hover:bg-neutral-800"
                        >
                            {q}
                        </Badge>
                    </button>
                ))}
            </div>
        </div>
    );
}

function LoadingSkeleton() {
    return (
        <Card>
            <CardContent className="space-y-3 pt-6">
                <Skeleton className="h-4 w-1/2" />
                <Skeleton className="h-32 w-full" />
                <Skeleton className="h-4 w-2/3" />
            </CardContent>
        </Card>
    );
}

function ErrorView({ error }: { error: QueryError }) {
    return (
        <div className="flex flex-col gap-4">
            <Card className="border-red-200 dark:border-red-900/50">
                <CardHeader>
                    <CardDescription className="text-red-700 dark:text-red-400">
                        {error.message}
                    </CardDescription>
                </CardHeader>
            </Card>

            <QueryDebugPanel
                soql={error.soql}
                url={error.url}
                responseBody={error.responseBody}
                defaultOpen
            />
        </div>
    );
}

function QueryDebugPanel({
    soql,
    url,
    responseBody,
    defaultOpen = false,
}: {
    soql?: Record<string, string>;
    url?: string;
    responseBody?: string | null;
    defaultOpen?: boolean;
}) {
    const { t } = useTranslation();
    const hasResponseBody = responseBody !== undefined && responseBody !== null;

    if (soql === undefined && url === undefined && !hasResponseBody) {
        return null;
    }

    return (
        <Collapsible defaultOpen={defaultOpen}>
            <CollapsibleTrigger className="flex w-full items-center justify-between rounded-md border border-neutral-200 bg-white px-3 py-2 text-xs text-neutral-600 hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800">
                <span>{t('pages.query.showQuery')}</span>
                <ChevronDown className="h-3 w-3" />
            </CollapsibleTrigger>
            <CollapsibleContent className="mt-2 space-y-3 rounded-md border border-neutral-200 bg-white p-3 text-xs dark:border-neutral-800 dark:bg-neutral-900">
                {soql && (
                    <div>
                        <div className="mb-2 font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('pages.query.soql')}
                        </div>
                        <pre className="overflow-x-auto rounded bg-neutral-50 p-2 text-[11px] leading-relaxed dark:bg-neutral-950">
                            {JSON.stringify(soql, null, 2)}
                        </pre>
                    </div>
                )}
                {url && <RequestUrl url={url} />}
                {hasResponseBody && (
                    <div>
                        <div className="mb-2 font-semibold text-neutral-700 dark:text-neutral-300">
                            {t('pages.query.rdwResponse')}
                        </div>
                        <pre className="overflow-x-auto rounded bg-neutral-50 p-2 text-[11px] leading-relaxed whitespace-pre-wrap dark:bg-neutral-950">
                            {formatResponseBody(responseBody)}
                        </pre>
                    </div>
                )}
            </CollapsibleContent>
        </Collapsible>
    );
}

function RequestUrl({ url }: { url: string }) {
    const { t } = useTranslation();

    return (
        <div>
            <div className="mb-2 font-semibold text-neutral-700 dark:text-neutral-300">
                {t('pages.query.url')}
            </div>
            <a
                href={url}
                target="_blank"
                rel="noreferrer"
                className="block overflow-x-auto rounded bg-neutral-50 p-2 text-[11px] leading-relaxed break-all text-blue-600 hover:underline dark:bg-neutral-950 dark:text-blue-400"
            >
                {url}
            </a>
        </div>
    );
}

function formatResponseBody(body: string): string {
    try {
        return JSON.stringify(JSON.parse(body), null, 2);
    } catch {
        return body;
    }
}

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
    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardDescription>{result.plan.explanation}</CardDescription>
                </CardHeader>
                <CardContent>
                    <ResultBody result={result} locale={locale} />
                </CardContent>
            </Card>

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

            <QueryDebugPanel soql={result.soql} url={result.url} />
        </div>
    );
}

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
        <div className="flex flex-wrap items-center justify-end gap-2">
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
                    <Share2 className="mr-1 h-3 w-3" />
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
                        <Download className="mr-1 h-3 w-3" />
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
                        <Download className="mr-1 h-3 w-3" />
                        {t('pages.query.exportJson')}
                    </Button>
                </>
            )}
        </div>
    );
}

function buildShareUrl(locale: string, slug: string): string {
    if (typeof window === 'undefined') {
        return `/${locale}?q=${encodeURIComponent(slug)}`;
    }

    const url = new URL(window.location.href);
    url.pathname = `/${locale}`;
    url.search = '';
    url.searchParams.set('q', slug);

    return url.toString();
}

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
            const csrf = document.querySelector<HTMLMetaElement>(
                'meta[name="csrf-token"]',
            )?.content;
            const response = await fetch(`/api/query/${slug}/feedback`, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
                body: JSON.stringify({
                    rating: nextRating,
                    comment: nextComment,
                }),
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
        <div className="flex flex-col gap-2 rounded-md border border-neutral-200 bg-white p-3 text-xs dark:border-neutral-800 dark:bg-neutral-900">
            <div className="flex flex-wrap items-center justify-between gap-2">
                <span className="text-neutral-600 dark:text-neutral-400">
                    {t('pages.query.feedbackPrompt')}
                </span>
                <div className="flex items-center gap-2">
                    <Button
                        type="button"
                        variant={rating === 'up' ? 'default' : 'outline'}
                        size="sm"
                        disabled={submitting}
                        onClick={() =>
                            void submitFeedback('up', commentDraft || null)
                        }
                        aria-label={t('pages.query.feedbackHelpful')}
                    >
                        <ThumbsUp className="h-3 w-3" />
                    </Button>
                    <Button
                        type="button"
                        variant={rating === 'down' ? 'default' : 'outline'}
                        size="sm"
                        disabled={submitting}
                        onClick={() =>
                            void submitFeedback('down', commentDraft || null)
                        }
                        aria-label={t('pages.query.feedbackNotHelpful')}
                    >
                        <ThumbsDown className="h-3 w-3" />
                    </Button>
                </div>
            </div>

            {rating !== null && (
                <div className="flex flex-col gap-2">
                    <Textarea
                        value={commentDraft}
                        onChange={(e) => setCommentDraft(e.target.value)}
                        placeholder={t(
                            'pages.query.feedbackCommentPlaceholder',
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

function ResultBody({
    result,
    locale,
}: {
    result: QueryResult;
    locale: string;
}) {
    const { t } = useTranslation();
    const { rows, displayHint, plan } = result;

    if (rows.length === 0) {
        return (
            <p className="text-sm text-neutral-500">
                {t('pages.query.noRows')}
            </p>
        );
    }

    if (displayHint === 'count') {
        const alias = plan.aggregates[0]?.alias ?? 'count';
        const firstRow = rows[0] ?? {};
        const value = firstRow[alias] ?? Object.values(firstRow)[0];

        return (
            <div className="flex flex-col items-center py-6">
                <div className="text-5xl font-semibold tabular-nums">
                    {formatNumber(value, locale)}
                </div>
                <div className="mt-1 text-sm text-neutral-500">
                    {t('pages.query.matchingVehicles')}
                </div>
            </div>
        );
    }

    if (displayHint === 'bars') {
        return <BarsView rows={rows} plan={plan} locale={locale} />;
    }

    return <TableView rows={rows} locale={locale} />;
}

function BarsView({
    rows,
    plan,
    locale,
}: {
    rows: QueryRow[];
    plan: Plan;
    locale: string;
}) {
    const firstRow = rows[0] ?? {};
    const groupKey =
        plan.groupBy[0] ??
        Object.keys(firstRow).find((k) => typeof firstRow[k] === 'string') ??
        Object.keys(firstRow)[0];
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(firstRow);

    if (groupKey === undefined || valueKey === undefined) {
        return <TableView rows={rows} locale={locale} />;
    }

    const data = rows
        .map((r) => ({
            label: String(r[groupKey] ?? '—'),
            value: Number(r[valueKey] ?? 0),
        }))
        .filter((d) => Number.isFinite(d.value))
        .sort((a, b) => b.value - a.value)
        .slice(0, 25);

    const config = {
        value: {
            label: plan.aggregates[0]?.alias ?? 'count',
            color: 'var(--chart-1)',
        },
    } satisfies ChartConfig;

    return (
        <ChartContainer config={config} className="h-[360px] w-full">
            <BarChart
                data={data}
                layout="vertical"
                margin={{ left: 80, right: 32 }}
            >
                <CartesianGrid horizontal={false} />
                <XAxis type="number" hide />
                <YAxis
                    dataKey="label"
                    type="category"
                    tickLine={false}
                    axisLine={false}
                    width={120}
                    tick={{ fontSize: 12 }}
                />
                <ChartTooltip
                    cursor={false}
                    content={<ChartTooltipContent indicator="line" />}
                />
                <Bar dataKey="value" fill="var(--chart-1)" radius={4}>
                    <LabelList
                        dataKey="value"
                        position="right"
                        className="fill-foreground text-xs"
                        formatter={(v) => formatNumber(v, locale)}
                    />
                </Bar>
            </BarChart>
        </ChartContainer>
    );
}

function TableView({ rows, locale }: { rows: QueryRow[]; locale: string }) {
    const { t } = useTranslation();
    const columns = Object.keys(rows[0] ?? {});

    return (
        <div className="overflow-x-auto">
            <Table>
                <TableHeader>
                    <TableRow>
                        {columns.map((c) => (
                            <TableHead key={c} className="text-xs">
                                {c}
                            </TableHead>
                        ))}
                    </TableRow>
                </TableHeader>
                <TableBody>
                    {rows.map((row, i) => (
                        <TableRow key={i}>
                            {columns.map((c) => (
                                <TableCell key={c} className="text-xs">
                                    {formatCell(row[c], locale, t)}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function findNumericKey(row: QueryRow): string | undefined {
    for (const [k, v] of Object.entries(row)) {
        if (
            typeof v === 'number' ||
            (typeof v === 'string' && v !== '' && !Number.isNaN(Number(v)))
        ) {
            return k;
        }
    }

    return Object.keys(row)[0];
}

async function parseJson(response: Response): Promise<unknown> {
    const contentType = response.headers.get('content-type') ?? '';

    if (!contentType.includes('application/json')) {
        return null;
    }

    try {
        return await response.json();
    } catch {
        return null;
    }
}

function localeTag(locale: string): string {
    return locale === 'nl' ? 'nl-NL' : 'en-US';
}

function formatNumber(v: unknown, locale: string): string {
    const n = typeof v === 'number' ? v : Number(v);

    return Number.isFinite(n)
        ? n.toLocaleString(localeTag(locale))
        : String(v ?? '');
}

// Module-level cache so useSyncExternalStore sees a stable reference when
// nothing has changed (React bails out of re-renders by identity).
const EMPTY_RECENT: string[] = [];
let cachedRecent: string[] | null = null;
const recentListeners = new Set<() => void>();

function readRecentQueries(): string[] {
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

function getRecentQueriesServerSnapshot(): string[] {
    return EMPTY_RECENT;
}

function subscribeToRecentQueries(callback: () => void): () => void {
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

function clearRecentQueries(): void {
    if (typeof window !== 'undefined') {
        try {
            window.localStorage.removeItem(RECENT_QUERIES_KEY);
        } catch {
            // localStorage unavailable; in-memory clear still works.
        }
    }

    notifyRecentChanged(EMPTY_RECENT);
}

function pushRecentQuery(query: string): void {
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

function formatCell(
    v: unknown,
    locale: string,
    t: (key: string) => string,
): string {
    if (v === null || v === undefined) {
        return '—';
    }

    if (typeof v === 'boolean') {
        return v ? t('pages.query.boolean.yes') : t('pages.query.boolean.no');
    }

    if (typeof v === 'number') {
        return formatNumber(v, locale);
    }

    return String(v);
}
