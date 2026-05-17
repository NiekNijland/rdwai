import { useTranslation } from '@/hooks/use-translation';

import type { QueryResult } from '../types';
import { BarsView } from './bars-view';
import { CountView } from './count-view';
import { HistogramView } from './histogram-view';
import { PieView } from './pie-view';
import { RecordView } from './record-view';
import { StackedBarsView } from './stacked-bars-view';
import { StatsView } from './stats-view';
import { TableView } from './table-view';
import { TimeseriesView } from './timeseries-view';
import { UnsupportedView } from './unsupported-view';

export function ResultBody({
    result,
    locale,
}: {
    result: QueryResult;
    locale: string;
}) {
    const { t } = useTranslation();
    const { rows, displayHint, plan } = result;

    // Unsupported is a refusal, not a data shortage — show it before the empty
    // check so an off-topic question doesn't render as "no rows matched".
    if (displayHint === 'unsupported') {
        return <UnsupportedView />;
    }

    if (rows.length === 0) {
        return (
            <p className="text-sm text-neutral-500">
                {t('pages.query.noRows')}
            </p>
        );
    }

    const table = <TableView rows={rows} plan={plan} locale={locale} />;

    switch (displayHint) {
        case 'count':
            return <CountView rows={rows} plan={plan} locale={locale} />;
        case 'stats':
            return <StatsView rows={rows} plan={plan} locale={locale} />;
        case 'bars':
            return (
                <BarsView
                    rows={rows}
                    plan={plan}
                    locale={locale}
                    fallback={table}
                />
            );
        case 'stacked_bars':
            return (
                <StackedBarsView
                    rows={rows}
                    plan={plan}
                    locale={locale}
                    fallback={table}
                />
            );
        case 'pie':
            return (
                <PieView
                    rows={rows}
                    plan={plan}
                    locale={locale}
                    fallback={table}
                />
            );
        case 'histogram':
            return (
                <HistogramView
                    rows={rows}
                    plan={plan}
                    locale={locale}
                    fallback={table}
                />
            );
        case 'timeseries':
            return (
                <TimeseriesView
                    rows={rows}
                    plan={plan}
                    locale={locale}
                    fallback={table}
                />
            );
        case 'record':
            return <RecordView rows={rows} locale={locale} />;
        case 'table':
        default:
            return table;
    }
}
