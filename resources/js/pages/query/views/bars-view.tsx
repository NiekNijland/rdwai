import {
    Bar,
    BarChart,
    CartesianGrid,
    LabelList,
    XAxis,
    YAxis,
} from 'recharts';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';

import { findNumericKey, formatNumber } from '../format';
import type { Plan, QueryRow } from '../types';

export function BarsView({
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
    const groupKey =
        plan.groupBy[0]?.field ??
        Object.keys(firstRow).find((k) => typeof firstRow[k] === 'string') ??
        Object.keys(firstRow)[0];
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(firstRow);

    if (groupKey === undefined || valueKey === undefined) {
        return <>{fallback}</>;
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
