import {
    Area,
    AreaChart,
    Bar,
    BarChart,
    CartesianGrid,
    XAxis,
    YAxis,
} from 'recharts';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { useTranslation } from '@/hooks/use-translation';

import {
    detectTimeGranularity,
    fillTimeBuckets,
    findNumericKey,
    formatNumber,
    isDateLike,
    localeTag,
    translateColumn,
    valueAxisLabel,
} from '../format';
import type { TimeGranularity } from '../format';
import type { Plan, QueryRow } from '../types';
import { ValueTooltipRow } from './value-tooltip-row';

// At or below this many buckets the series reads as discrete periods, so bars
// communicate "a count per period" better than an interpolated area; beyond it
// the bars get too thin and a trend area is clearer.
const BARS_MAX_BUCKETS = 31;

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
    const { t } = useTranslation();
    const firstRow = rows[0] ?? {};
    const groupFields = plan.groupBy.map((k) => k.field);
    const dateKey =
        groupFields.find((k) => isDateLike(firstRow[k])) ?? groupFields[0];
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(firstRow);

    if (dateKey === undefined || valueKey === undefined) {
        return <>{fallback}</>;
    }

    const points = rows
        .map((r) => ({
            x: String(r[dateKey] ?? ''),
            value: Number(r[valueKey] ?? 0),
        }))
        .filter((d) => d.x !== '' && Number.isFinite(d.value))
        .sort((a, b) => a.x.localeCompare(b.x));

    if (points.length === 0) {
        return <>{fallback}</>;
    }

    const granularity = detectTimeGranularity(points.map((d) => d.x));
    const data = fillTimeBuckets(points, granularity);

    const xLabel = translateColumn(dateKey, t);
    const yLabel = valueAxisLabel(plan, t);

    const config = {
        value: {
            label: yLabel,
            color: 'var(--chart-1)',
        },
    } satisfies ChartConfig;

    const xAxis = (
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
            label={{
                value: xLabel,
                position: 'insideBottom',
                offset: -16,
                fill: 'var(--muted-foreground)',
                fontSize: 12,
            }}
        />
    );

    const yAxis = (
        <YAxis
            tickLine={false}
            axisLine={false}
            width={64}
            tick={{ fontSize: 11 }}
            allowDecimals={false}
            tickFormatter={(v) => formatNumber(v, locale)}
            label={{
                value: yLabel,
                angle: -90,
                position: 'insideLeft',
                offset: 8,
                style: { textAnchor: 'middle' },
                fill: 'var(--muted-foreground)',
                fontSize: 12,
            }}
        />
    );

    const tooltip = (
        <ChartTooltip
            cursor={{ stroke: 'var(--chart-1)', strokeOpacity: 0.3 }}
            content={
                // "dot" keeps the period header above the value row; with "line"
                // the single-series label nests and gets dropped by the formatter.
                <ChartTooltipContent
                    indicator="dot"
                    labelFormatter={(label) =>
                        formatDateLabel(String(label), granularity, locale)
                    }
                    formatter={(value) => (
                        <ValueTooltipRow
                            label={yLabel}
                            value={value}
                            locale={locale}
                        />
                    )}
                />
            }
        />
    );

    // Discrete, sparse periods render as bars: each bucket stands on its own and
    // zero-months show as empty slots rather than a line dipping through them.
    if (data.length <= BARS_MAX_BUCKETS) {
        return (
            <ChartContainer config={config} className="h-[360px] w-full">
                <BarChart
                    data={data}
                    margin={{ left: 12, right: 12, top: 8, bottom: 28 }}
                >
                    <CartesianGrid vertical={false} strokeDasharray="3 3" />
                    {xAxis}
                    {yAxis}
                    {tooltip}
                    <Bar
                        dataKey="value"
                        fill="var(--chart-1)"
                        radius={[4, 4, 0, 0]}
                    />
                </BarChart>
            </ChartContainer>
        );
    }

    return (
        <ChartContainer config={config} className="h-[360px] w-full">
            <AreaChart
                data={data}
                margin={{ left: 12, right: 12, top: 8, bottom: 28 }}
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
                {xAxis}
                {yAxis}
                {tooltip}
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

function formatDateTick(
    value: string,
    granularity: TimeGranularity,
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
    granularity: TimeGranularity,
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
