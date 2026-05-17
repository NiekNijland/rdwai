import { Area, AreaChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

import { findNumericKey, formatNumber, isDateLike, localeTag } from '../format';
import type { Plan, QueryRow } from '../types';

export function TimeseriesView({
    rows,
    plan,
    locale,
    fallback,
}: {
    rows: QueryRow[];
    plan: Plan;
    locale: string;
    fallback: React.ReactNode;
}) {
    const firstRow = rows[0] ?? {};
    const groupFields = plan.groupBy.map((k) => k.field);
    const dateKey =
        groupFields.find((k) => isDateLike(firstRow[k])) ?? groupFields[0];
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(firstRow);

    if (dateKey === undefined || valueKey === undefined) {
        return <>{fallback}</>;
    }

    const data = rows
        .map((r) => ({
            x: String(r[dateKey] ?? ''),
            value: Number(r[valueKey] ?? 0),
        }))
        .filter((d) => d.x !== '' && Number.isFinite(d.value))
        .sort((a, b) => a.x.localeCompare(b.x));

    if (data.length === 0) {
        return <>{fallback}</>;
    }

    const config = {
        value: {
            label: plan.aggregates[0]?.alias ?? 'count',
            color: 'var(--chart-1)',
        },
    } satisfies ChartConfig;

    const granularity = detectGranularity(data.map((d) => d.x));

    return (
        <ChartContainer config={config} className="h-[360px] w-full">
            <AreaChart
                data={data}
                margin={{ left: 12, right: 12, top: 8, bottom: 8 }}
            >
                <defs>
                    <linearGradient
                        id="timeseries-fill"
                        x1="0"
                        y1="0"
                        x2="0"
                        y2="1"
                    >
                        <stop
                            offset="0%"
                            stopColor="var(--chart-1)"
                            stopOpacity={0.35}
                        />
                        <stop
                            offset="100%"
                            stopColor="var(--chart-1)"
                            stopOpacity={0.02}
                        />
                    </linearGradient>
                </defs>
                <CartesianGrid vertical={false} strokeDasharray="3 3" />
                <XAxis
                    dataKey="x"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    tick={{ fontSize: 11 }}
                    tickFormatter={(v) =>
                        formatDateTick(String(v), granularity, locale)
                    }
                    minTickGap={32}
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    width={48}
                    tick={{ fontSize: 11 }}
                    tickFormatter={(v) => formatNumber(v, locale)}
                />
                <ChartTooltip
                    cursor={{ stroke: 'var(--chart-1)', strokeOpacity: 0.3 }}
                    content={
                        <ChartTooltipContent
                            indicator="line"
                            labelFormatter={(label) =>
                                formatDateLabel(
                                    String(label),
                                    granularity,
                                    locale,
                                )
                            }
                            formatter={(value) => formatNumber(value, locale)}
                        />
                    }
                />
                <Area
                    type="monotone"
                    dataKey="value"
                    stroke="var(--chart-1)"
                    strokeWidth={2}
                    fill="url(#timeseries-fill)"
                />
            </AreaChart>
        </ChartContainer>
    );
}

type Granularity = 'year' | 'month' | 'day';

// 80%+ on Jan 1 / day 1 is enough: a single off-day point shouldn't demote
// the whole axis to daily ticks.
const GRANULARITY_THRESHOLD = 0.8;

function detectGranularity(xs: string[]): Granularity {
    if (xs.length === 0) {
        return 'day';
    }

    const onJan1 = xs.filter((x) => /^\d{4}-01-01/.test(x)).length;
    const onDay1 = xs.filter((x) => /^\d{4}-\d{2}-01/.test(x)).length;

    if (onJan1 / xs.length >= GRANULARITY_THRESHOLD) {
        return 'year';
    }

    if (onDay1 / xs.length >= GRANULARITY_THRESHOLD) {
        return 'month';
    }

    return 'day';
}

function formatDateTick(
    value: string,
    granularity: Granularity,
    locale: string,
): string {
    if (granularity === 'year') {
        return value.slice(0, 4);
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    if (granularity === 'month') {
        return date.toLocaleDateString(localeTag(locale), {
            month: 'short',
            year: '2-digit',
        });
    }

    return date.toLocaleDateString(localeTag(locale), {
        day: 'numeric',
        month: 'short',
    });
}

function formatDateLabel(
    value: string,
    granularity: Granularity,
    locale: string,
): string {
    if (granularity === 'year') {
        return value.slice(0, 4);
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    return date.toLocaleDateString(localeTag(locale), {
        day: granularity === 'day' ? 'numeric' : undefined,
        month: 'long',
        year: 'numeric',
    });
}
