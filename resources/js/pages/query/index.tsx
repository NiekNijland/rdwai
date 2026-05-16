import { Head } from '@inertiajs/react';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    XAxis,
    YAxis,
} from 'recharts';
import { toast } from 'sonner';

import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    type ChartConfig,
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
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
import { ChevronDown, Sparkles } from 'lucide-react';

type WhereClause = { field: string; op: string; value: string };
type AggregateClause = { fn: string; field: string | null; alias: string };
type OrderClause = { expr: string; direction: 'asc' | 'desc' };

type Plan = {
    where: WhereClause[];
    select: string[];
    groupBy: string[];
    aggregates: AggregateClause[];
    orderBy: OrderClause[];
    limit: number | null;
    display: 'count' | 'bars' | 'table' | 'record';
    explanation: string;
};

type QueryResult = {
    plan: Plan;
    soql: Record<string, string>;
    rows: Array<Record<string, unknown>>;
    displayHint: Plan['display'];
};

type Props = {
    examples: string[];
};

export default function QueryPage({ examples }: Props) {
    const [prompt, setPrompt] = useState('');
    const [loading, setLoading] = useState(false);
    const [result, setResult] = useState<QueryResult | null>(null);

    const submit = async (overridePrompt?: string) => {
        const value = (overridePrompt ?? prompt).trim();
        if (value.length < 3) return;

        setLoading(true);
        setResult(null);
        try {
            const csrf = document
                .querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
                ?.content;
            const response = await fetch('/api/query', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
                },
                body: JSON.stringify({ prompt: value }),
            });
            const data = await response.json();
            if (!response.ok) {
                toast.error(data.error ?? 'Query failed');
                return;
            }
            setResult(data as QueryResult);
        } catch (e) {
            toast.error(e instanceof Error ? e.message : 'Network error');
        } finally {
            setLoading(false);
        }
    };

    return (
        <>
            <Head title="RDWAI — Ask the Dutch vehicle registry" />
            <div className="min-h-screen bg-gradient-to-b from-neutral-50 to-neutral-100 px-4 py-12 dark:from-neutral-950 dark:to-neutral-900">
                <div className="mx-auto flex max-w-3xl flex-col gap-6">
                    <header className="text-center">
                        <div className="mb-3 inline-flex items-center gap-2 rounded-full border border-neutral-200 bg-white/70 px-3 py-1 text-xs text-neutral-600 backdrop-blur dark:border-neutral-800 dark:bg-neutral-900/70 dark:text-neutral-400">
                            <Sparkles className="h-3 w-3" />
                            Powered by gpt-4.1-nano + RDW Open Data
                        </div>
                        <h1 className="text-3xl font-semibold tracking-tight sm:text-4xl">
                            Ask the Dutch vehicle registry
                        </h1>
                        <p className="mt-2 text-sm text-neutral-600 dark:text-neutral-400">
                            Describe what you want to know about registered
                            vehicles in the Netherlands.
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
                                placeholder="e.g. How many white Volkswagen Ups from February 2017 are insured?"
                                rows={3}
                                className="resize-none text-base"
                            />
                            <div className="flex flex-wrap items-center justify-between gap-2">
                                <span className="text-xs text-neutral-500">
                                    ⌘ + Enter to submit
                                </span>
                                <Button
                                    onClick={() => void submit()}
                                    disabled={loading || prompt.trim().length < 3}
                                >
                                    {loading ? 'Thinking…' : 'Ask'}
                                </Button>
                            </div>
                        </CardContent>
                    </Card>

                    {!result && !loading && (
                        <div className="flex flex-wrap justify-center gap-2">
                            {examples.map((ex) => (
                                <button
                                    key={ex}
                                    type="button"
                                    onClick={() => {
                                        setPrompt(ex);
                                        void submit(ex);
                                    }}
                                    className="group"
                                >
                                    <Badge
                                        variant="outline"
                                        className="cursor-pointer text-xs font-normal transition group-hover:bg-neutral-100 dark:group-hover:bg-neutral-800"
                                    >
                                        {ex}
                                    </Badge>
                                </button>
                            ))}
                        </div>
                    )}

                    {loading && <LoadingSkeleton />}

                    {result && <ResultView result={result} />}
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

function ResultView({ result }: { result: QueryResult }) {
    return (
        <div className="flex flex-col gap-4">
            <Card>
                <CardHeader>
                    <CardDescription>{result.plan.explanation}</CardDescription>
                </CardHeader>
                <CardContent>
                    <ResultBody result={result} />
                </CardContent>
            </Card>

            <Collapsible>
                <CollapsibleTrigger className="flex w-full items-center justify-between rounded-md border border-neutral-200 bg-white px-3 py-2 text-xs text-neutral-600 hover:bg-neutral-50 dark:border-neutral-800 dark:bg-neutral-900 dark:text-neutral-400 dark:hover:bg-neutral-800">
                    <span>Show generated query</span>
                    <ChevronDown className="h-3 w-3" />
                </CollapsibleTrigger>
                <CollapsibleContent className="mt-2 rounded-md border border-neutral-200 bg-white p-3 text-xs dark:border-neutral-800 dark:bg-neutral-900">
                    <div className="mb-2 font-semibold text-neutral-700 dark:text-neutral-300">
                        Plan
                    </div>
                    <pre className="overflow-x-auto rounded bg-neutral-50 p-2 text-[11px] leading-relaxed dark:bg-neutral-950">
                        {JSON.stringify(result.plan, null, 2)}
                    </pre>
                    <div className="mt-3 mb-2 font-semibold text-neutral-700 dark:text-neutral-300">
                        SoQL
                    </div>
                    <pre className="overflow-x-auto rounded bg-neutral-50 p-2 text-[11px] leading-relaxed dark:bg-neutral-950">
                        {JSON.stringify(result.soql, null, 2)}
                    </pre>
                </CollapsibleContent>
            </Collapsible>
        </div>
    );
}

function ResultBody({ result }: { result: QueryResult }) {
    const { rows, displayHint, plan } = result;

    if (rows.length === 0) {
        return (
            <p className="text-sm text-neutral-500">
                No rows matched this query.
            </p>
        );
    }

    if (displayHint === 'count') {
        const alias = plan.aggregates[0]?.alias ?? 'count';
        const value = rows[0]?.[alias] ?? Object.values(rows[0] ?? {})[0];
        return (
            <div className="flex flex-col items-center py-6">
                <div className="text-5xl font-semibold tabular-nums">
                    {formatNumber(value)}
                </div>
                <div className="mt-1 text-sm text-neutral-500">
                    matching vehicles
                </div>
            </div>
        );
    }

    if (displayHint === 'bars') {
        return <BarsView rows={rows} plan={plan} />;
    }

    return <TableView rows={rows} />;
}

function BarsView({
    rows,
    plan,
}: {
    rows: Array<Record<string, unknown>>;
    plan: Plan;
}) {
    const groupKey =
        plan.groupBy[0] != null
            ? rdwKeyFor(plan.groupBy[0])
            : Object.keys(rows[0]).find((k) => typeof rows[0][k] === 'string') ??
              Object.keys(rows[0])[0];
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(rows[0]);

    const data = rows
        .map((r) => ({
            label: String(r[groupKey] ?? '—'),
            value: Number(r[valueKey] ?? 0),
        }))
        .filter((d) => Number.isFinite(d.value))
        .sort((a, b) => b.value - a.value)
        .slice(0, 25);

    const config = {
        value: { label: plan.aggregates[0]?.alias ?? 'count', color: 'var(--chart-1)' },
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
                        formatter={(v) => formatNumber(v)}
                    />
                </Bar>
            </BarChart>
        </ChartContainer>
    );
}

function TableView({ rows }: { rows: Array<Record<string, unknown>> }) {
    const columns = Object.keys(rows[0]);
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
                                    {formatCell(row[c])}
                                </TableCell>
                            ))}
                        </TableRow>
                    ))}
                </TableBody>
            </Table>
        </div>
    );
}

function findNumericKey(row: Record<string, unknown>): string {
    for (const [k, v] of Object.entries(row)) {
        if (typeof v === 'number' || (typeof v === 'string' && !isNaN(Number(v)))) {
            return k;
        }
    }
    return Object.keys(row)[0];
}

// The RDW package's getProjection() returns rows keyed by the Dutch RDW
// snake_case key (e.g. "eerste_kleur"), not the English enum name. This map
// covers the common groupable fields surfaced by the model. Unknown names
// fall through unchanged.
const ENGLISH_TO_RDW_KEY: Record<string, string> = {
    Brand: 'merk',
    CommercialName: 'handelsbenaming',
    PrimaryColor: 'eerste_kleur',
    SecondaryColor: 'tweede_kleur',
    VehicleType: 'voertuigsoort',
    NetherlandsSubcategory: 'subcategorie_nederland',
    BodyType: 'inrichting',
    Configuration: 'inrichting',
};

function rdwKeyFor(englishName: string): string {
    return ENGLISH_TO_RDW_KEY[englishName] ?? englishName;
}

function formatNumber(v: unknown): string {
    const n = typeof v === 'number' ? v : Number(v);
    return Number.isFinite(n) ? n.toLocaleString('nl-NL') : String(v ?? '');
}

function formatCell(v: unknown): string {
    if (v === null || v === undefined) return '—';
    if (typeof v === 'boolean') return v ? 'Ja' : 'Nee';
    return String(v);
}
