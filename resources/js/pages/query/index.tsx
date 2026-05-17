import { Head } from '@inertiajs/react';
import { ChevronDown, Sparkles } from 'lucide-react';
import { useEffect, useRef, useState, useSyncExternalStore } from 'react';
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
import { useTranslation } from '@/hooks/use-translation';

type WhereClause = { field: string; op: string; value: string };
type AggregateClause = { fn: string; field: string | null; alias: string };
type OrderClause = { expr: string; direction: 'asc' | 'desc' };
type DisplayHint = 'count' | 'bars' | 'table' | 'record';

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

const MIN_PROMPT_LENGTH = 3;
const RECENT_QUERIES_KEY = 'rdwai:recent-queries';
const RECENT_QUERIES_MAX = 6;

export default function QueryPage() {
    const { t, currentLocale } = useTranslation();
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<QueryResult | null>(null);
    const [error, setError] = useState<QueryError | null>(null);
    // Recent queries live in localStorage. useSyncExternalStore returns the
    // empty list as the SSR snapshot, then swaps to the localStorage value
    // after hydration — so we never produce mismatched markup.
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

            setResult(data as QueryResult);
            pushRecentQuery(value);
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

                    {!result && !error && !loading && recent.length > 0 && (
                        <div className="flex flex-col items-center gap-2">
                            <div className="flex items-center gap-2">
                                <span className="text-xs text-neutral-500">
                                    {t('pages.query.recent')}
                                </span>
                                <button
                                    type="button"
                                    onClick={() => clearRecentQueries()}
                                    className="text-xs text-neutral-500 underline-offset-2 hover:text-neutral-700 hover:underline dark:hover:text-neutral-300"
                                >
                                    {t('pages.query.clearRecent')}
                                </button>
                            </div>
                            <div className="flex flex-wrap justify-center gap-2">
                                {recent.map((q) => (
                                    <button
                                        key={q}
                                        type="button"
                                        onClick={() => {
                                            setPrompt(q);
                                            void submit(q);
                                        }}
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
                    )}

                    {loading && <LoadingSkeleton />}

                    {result && (
                        <ResultView result={result} locale={currentLocale()} />
                    )}

                    {error && !loading && <ErrorView error={error} />}
                </div>
            </div>
        </>
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
}: {
    result: QueryResult;
    locale: string;
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

            <QueryDebugPanel soql={result.soql} url={result.url} />
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
