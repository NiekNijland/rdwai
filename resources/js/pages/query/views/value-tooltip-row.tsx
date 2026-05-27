import { formatNumber } from '../format';

/**
 * Tooltip row for single-series charts. Pairs the value axis label (e.g.
 * "Voertuigen") with the locale-formatted number so a hovered point reads as
 * "Voertuigen 1.005" instead of a bare, context-free figure. The period itself
 * (date / bin) is rendered separately as the tooltip's header label.
 */
export function ValueTooltipRow({
    label,
    value,
    locale,
}: {
    label: string;
    value: unknown;
    locale: string;
}) {
    return (
        <div className="flex w-full items-center justify-between gap-3">
            <span className="text-muted-foreground">{label}</span>
            <span className="font-mono font-medium tabular-nums">
                {formatNumber(value, locale)}
            </span>
        </div>
    );
}
