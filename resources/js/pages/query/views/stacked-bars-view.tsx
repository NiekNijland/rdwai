import { Bar, BarChart, CartesianGrid, XAxis, YAxis } from 'recharts';

import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { useTranslation } from '@/hooks/use-translation';

import {
    chartColor,
    findNumericKey,
    formatNumber,
    isDateLike,
} from '../format';
import type { Plan, QueryRow } from '../types';

const MAX_STACKS = 8;

export function StackedBarsView({
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
    const [outerKey, innerKey] = plan.groupBy.map((k) => k.field);
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(rows[0] ?? {});

    if (
        outerKey === undefined ||
        innerKey === undefined ||
        valueKey === undefined
    ) {
        return <>{fallback}</>;
    }

    // Pivot to wide: one row per outer value, one column per inner value.
    const totalByInner = new Map<string, number>();
    const totalByOuter = new Map<string, number>();
    const cell = new Map<string, Map<string, number>>();

    for (const row of rows) {
        const outer = String(row[outerKey] ?? '');
        const inner = String(row[innerKey] ?? '');
        const value = Number(row[valueKey] ?? 0);

        if (outer === '' || inner === '' || !Number.isFinite(value)) {
            continue;
        }

        totalByInner.set(inner, (totalByInner.get(inner) ?? 0) + value);
        totalByOuter.set(outer, (totalByOuter.get(outer) ?? 0) + value);

        const innerMap = cell.get(outer) ?? new Map<string, number>();
        innerMap.set(inner, (innerMap.get(inner) ?? 0) + value);
        cell.set(outer, innerMap);
    }

    if (cell.size === 0) {
        return <>{fallback}</>;
    }

    // Pick the top N inner categories, lump everything else into "Other".
    const innerSorted = [...totalByInner.entries()].sort((a, b) => b[1] - a[1]);
    const visibleInner = innerSorted.slice(0, MAX_STACKS).map(([k]) => k);
    const otherSet = new Set(innerSorted.slice(MAX_STACKS).map(([k]) => k));
    const otherLabel = t('pages.query.stackedOther');

    const outerValues = [...totalByOuter.keys()];
    const outerLooksLikeDate = outerValues.every((v) => isDateLike(v));
    const sortedOuters = [...outerValues].sort((a, b) => {
        if (outerLooksLikeDate) {
            return a.localeCompare(b);
        }

        return (totalByOuter.get(b) ?? 0) - (totalByOuter.get(a) ?? 0);
    });

    const stackKeys =
        otherSet.size > 0 ? [...visibleInner, otherLabel] : visibleInner;

    const data = sortedOuters.map((outer) => {
        const innerMap = cell.get(outer) ?? new Map<string, number>();
        const point: Record<string, number | string> = {
            outer: outerLooksLikeDate ? outer.slice(0, 4) : outer,
        };

        for (const k of visibleInner) {
            point[k] = innerMap.get(k) ?? 0;
        }

        if (otherSet.size > 0) {
            let other = 0;

            for (const key of otherSet) {
                other += innerMap.get(key) ?? 0;
            }

            point[otherLabel] = other;
        }

        return point;
    });

    const config: ChartConfig = Object.fromEntries(
        stackKeys.map((k, i) => [k, { label: k, color: chartColor(i) }]),
    );

    return (
        <ChartContainer config={config} className="h-[420px] w-full">
            <BarChart
                data={data}
                margin={{ left: 12, right: 12, top: 8, bottom: 8 }}
            >
                <CartesianGrid vertical={false} strokeDasharray="3 3" />
                <XAxis
                    dataKey="outer"
                    tickLine={false}
                    axisLine={false}
                    tickMargin={8}
                    tick={{ fontSize: 11 }}
                    minTickGap={16}
                />
                <YAxis
                    tickLine={false}
                    axisLine={false}
                    width={48}
                    tick={{ fontSize: 11 }}
                    tickFormatter={(v) => formatNumber(v, locale)}
                />
                <ChartTooltip
                    cursor={{ fill: 'var(--chart-1)', fillOpacity: 0.05 }}
                    content={
                        <ChartTooltipContent
                            formatter={(value, name) => (
                                <div className="flex w-full items-center justify-between gap-3">
                                    <span className="text-muted-foreground">
                                        {String(name)}
                                    </span>
                                    <span className="font-mono font-medium tabular-nums">
                                        {formatNumber(value, locale)}
                                    </span>
                                </div>
                            )}
                        />
                    }
                />
                <ChartLegend content={<ChartLegendContent />} />
                {stackKeys.map((k, i) => (
                    <Bar
                        key={k}
                        dataKey={k}
                        stackId="stack"
                        fill={chartColor(i)}
                        radius={
                            // Round only the last (topmost) stack so the column has a
                            // clean rounded crown.
                            i === stackKeys.length - 1
                                ? [3, 3, 0, 0]
                                : [0, 0, 0, 0]
                        }
                    />
                ))}
            </BarChart>
        </ChartContainer>
    );
}
