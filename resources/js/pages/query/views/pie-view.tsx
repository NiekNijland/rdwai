import { Cell, Label, Pie, PieChart } from 'recharts';

import {
    ChartContainer,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import type { ChartConfig } from '@/components/ui/chart';
import { useTranslation } from '@/hooks/use-translation';

import {
    chartColor,
    findNumericKey,
    formatNumber,
    formatPercent,
} from '../format';
import type { Plan, QueryRow } from '../types';

const MAX_SLICES = 6;

export function PieView({
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
    const groupKey =
        plan.groupBy[0]?.field ??
        Object.keys(firstRow).find((k) => typeof firstRow[k] === 'string') ??
        Object.keys(firstRow)[0];
    const valueKey = plan.aggregates[0]?.alias ?? findNumericKey(firstRow);

    if (groupKey === undefined || valueKey === undefined) {
        return <>{fallback}</>;
    }

    const cleaned = rows
        .map((r) => ({
            label: String(r[groupKey] ?? '—'),
            value: Number(r[valueKey] ?? 0),
        }))
        .filter((d) => Number.isFinite(d.value) && d.value > 0)
        .sort((a, b) => b.value - a.value);

    if (cleaned.length === 0) {
        return <>{fallback}</>;
    }

    // Collapse the long tail into a single "Other" slice when we have more
    // categories than chart slots; pies become unreadable past ~6 slices.
    const visible = cleaned.slice(0, MAX_SLICES - 1);
    const rest = cleaned.slice(MAX_SLICES - 1);
    const data =
        rest.length === 0
            ? cleaned
            : [
                  ...visible,
                  {
                      label: t('pages.query.pieOther'),
                      value: rest.reduce((sum, d) => sum + d.value, 0),
                  },
              ];

    const total = data.reduce((sum, d) => sum + d.value, 0);

    const config: ChartConfig = Object.fromEntries(
        data.map((d, i) => [d.label, { label: d.label, color: chartColor(i) }]),
    );

    return (
        <div className="flex flex-col items-center gap-4 sm:flex-row sm:items-stretch sm:justify-center sm:gap-8">
            <ChartContainer
                config={config}
                className="aspect-square h-[280px] w-[280px]"
            >
                <PieChart>
                    <ChartTooltip
                        cursor={false}
                        content={
                            <ChartTooltipContent
                                hideLabel
                                formatter={(value, name) => (
                                    <div className="flex w-full items-center justify-between gap-3">
                                        <span className="text-muted-foreground">
                                            {String(name)}
                                        </span>
                                        <span className="font-mono font-medium tabular-nums">
                                            {formatNumber(value, locale)}
                                            <span className="ml-1.5 text-muted-foreground">
                                                (
                                                {formatPercent(
                                                    Number(value) / total,
                                                    locale,
                                                )}
                                                )
                                            </span>
                                        </span>
                                    </div>
                                )}
                            />
                        }
                    />
                    <Pie
                        data={data}
                        dataKey="value"
                        nameKey="label"
                        innerRadius={70}
                        outerRadius={110}
                        paddingAngle={2}
                        strokeWidth={2}
                    >
                        {data.map((d, i) => (
                            <Cell
                                key={d.label}
                                fill={chartColor(i)}
                                stroke="var(--background)"
                            />
                        ))}
                        <Label
                            content={({ viewBox }) => {
                                if (
                                    viewBox === undefined ||
                                    !('cx' in viewBox) ||
                                    !('cy' in viewBox)
                                ) {
                                    return null;
                                }

                                const { cx, cy } = viewBox as {
                                    cx: number;
                                    cy: number;
                                };

                                return (
                                    <text
                                        x={cx}
                                        y={cy}
                                        textAnchor="middle"
                                        dominantBaseline="central"
                                    >
                                        <tspan
                                            x={cx}
                                            y={cy - 8}
                                            className="fill-foreground text-2xl font-semibold tabular-nums"
                                        >
                                            {formatNumber(total, locale)}
                                        </tspan>
                                        <tspan
                                            x={cx}
                                            y={cy + 14}
                                            className="fill-muted-foreground text-xs"
                                        >
                                            {t('pages.query.pieTotal')}
                                        </tspan>
                                    </text>
                                );
                            }}
                        />
                    </Pie>
                </PieChart>
            </ChartContainer>

            <ul className="flex flex-col justify-center gap-2 text-sm">
                {data.map((d, i) => (
                    <li
                        key={d.label}
                        className="grid grid-cols-[12px_auto_1fr_auto] items-center gap-2"
                    >
                        <span
                            className="h-3 w-3 shrink-0 rounded-[3px]"
                            style={{ backgroundColor: chartColor(i) }}
                        />
                        <span className="text-neutral-700 dark:text-neutral-300">
                            {d.label}
                        </span>
                        <span className="text-right text-neutral-500 tabular-nums dark:text-neutral-400">
                            {formatPercent(d.value / total, locale)}
                        </span>
                        <span className="text-right tabular-nums">
                            {formatNumber(d.value, locale)}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}
