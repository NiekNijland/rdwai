import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

import { findNumericKey, formatNumber } from '../format';
import type { Plan, QueryRow } from '../types';

export function HistogramView({
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
    const binKey = plan.groupBy[0]?.field ?? Object.keys(firstRow)[0];
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(firstRow);

    if (binKey === undefined || valueKey === undefined) {
        return <>{fallback}</>;
    }

    // Only collapse to year-labels when *all* buckets are Jan 1. Sub-year
    // buckets (months, weeks) get formatted as full dates so they don't
    // visually collide on the x-axis.
    const treatAsYear = rows.every((r) => {
        const v = r[binKey];

        return typeof v === 'string' && /^\d{4}-01-01/.test(v);
    });

    const data = rows
        .map((r) => {
            const raw = r[binKey];
            const sort =
                typeof raw === 'number'
                    ? raw
                    : typeof raw === 'string' &&
                        raw !== '' &&
                        !Number.isNaN(Number(raw))
                      ? Number(raw)
                      : null;

            return {
                bin: formatBinLabel(raw, treatAsYear),
                rawBin: raw,
                value: Number(r[valueKey] ?? 0),
                sort,
            };
        })
        .filter((d) => d.bin !== '' && Number.isFinite(d.value))
        .sort((a, b) => {
            if (a.sort !== null && b.sort !== null) {
                return a.sort - b.sort;
            }

            return String(a.rawBin).localeCompare(String(b.rawBin));
        });

    if (data.length === 0) {
        return <>{fallback}</>;
    }

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
                margin={{ left: 12, right: 12, top: 8, bottom: 8 }}
                barCategoryGap={1}
            >
                <CartesianGrid vertical={false} strokeDasharray="3 3" />
                <XAxis
                    dataKey="bin"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    tick={{ fontSize: 11 }}
                    minTickGap={4}
                    interval="preserveStartEnd"
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    width={48}
                    tick={{ fontSize: 11 }}
                    tickFormatter={(v) => formatNumber(v, locale)}
                />
                <ChartTooltip
                    cursor={{ fill: 'var(--chart-1)', fillOpacity: 0.08 }}
                    content={
                        <ChartTooltipContent
                            indicator="dot"
                            formatter={(value) => formatNumber(value, locale)}
                        />
                    }
                />
                <Bar
                    dataKey="value"
                    fill="var(--chart-1)"
                    radius={[2, 2, 0, 0]}
                />
            </BarChart>
        </ChartContainer>
    );
}

function formatBinLabel(raw: unknown, treatAsYear: boolean): string {
    if (raw === null || raw === undefined) {
        return '';
    }

    if (treatAsYear && typeof raw === 'string') {
        return raw.slice(0, 4);
    }

    return String(raw);
}
