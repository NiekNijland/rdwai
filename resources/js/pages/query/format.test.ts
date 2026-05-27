import { describe, expect, it } from 'vitest';

import {
    bucketForColumn,
    chartColor,
    detectTimeGranularity,
    fillTimeBuckets,
    findNumericKey,
    formatBucketLabel,
    formatCell,
    formatNumber,
    formatPercent,
    humanizePascalCase,
    humanizeSnakeCase,
    isDateLike,
    localeTag,
    translateColumn,
    valueAxisLabel,
} from './format';
import type { Plan } from './types';

const identity = (key: string): string => key;

function planWithGroupBy(groupBy: Plan['groupBy']): Plan {
    return {
        where: [],
        select: [],
        groupBy,
        aggregates: [],
        orderBy: [],
        limit: null,
        display: 'table',
        explanation: '',
    };
}

describe('localeTag', () => {
    it('maps the short locale to a BCP-47 tag', () => {
        expect(localeTag('nl')).toBe('nl-NL');
        expect(localeTag('en')).toBe('en-US');
        expect(localeTag('anything-else')).toBe('en-US');
    });
});

describe('formatNumber', () => {
    it('groups finite numbers per locale', () => {
        expect(formatNumber(72184, 'en')).toBe('72,184');
        expect(formatNumber(72184, 'nl')).toBe('72.184');
        expect(formatNumber('1234', 'en')).toBe('1,234');
    });

    it('falls back to a string for non-numeric input', () => {
        expect(formatNumber('abc', 'en')).toBe('abc');
        expect(formatNumber(undefined, 'en')).toBe('');
    });

    // Number(null) is 0, so null slips past the finite check as "0". Callers
    // (formatCell) guard nullish values before reaching here.
    it('coerces null to zero', () => {
        expect(formatNumber(null, 'en')).toBe('0');
    });
});

describe('formatCell', () => {
    const t = (key: string): string =>
        key === 'pages.query.boolean.yes' ? 'Yes' : 'No';

    it('renders an em dash for nullish values', () => {
        expect(formatCell(null, 'en', t)).toBe('—');
        expect(formatCell(undefined, 'en', t)).toBe('—');
    });

    it('translates booleans', () => {
        expect(formatCell(true, 'en', t)).toBe('Yes');
        expect(formatCell(false, 'en', t)).toBe('No');
    });

    it('formats numbers and passes strings through', () => {
        expect(formatCell(1234, 'en', t)).toBe('1,234');
        expect(formatCell('Tesla', 'en', t)).toBe('Tesla');
    });
});

describe('humanizePascalCase', () => {
    it('splits PascalCase into a sentence', () => {
        expect(humanizePascalCase('FirstAdmissionDate')).toBe(
            'First admission date',
        );
    });

    it('keeps known acronyms uppercase', () => {
        expect(humanizePascalCase('ApkExpiryDate')).toBe('APK expiry date');
        expect(humanizePascalCase('BpmAmount')).toBe('BPM amount');
    });

    it('returns the input when there are no tokens', () => {
        expect(humanizePascalCase('---')).toBe('---');
    });
});

describe('humanizeSnakeCase', () => {
    it('turns snake/kebab aliases into a sentence', () => {
        expect(humanizeSnakeCase('avg_mass')).toBe('Avg mass');
        expect(humanizeSnakeCase('total-count')).toBe('Total count');
    });

    it('returns the alias when it has no content', () => {
        expect(humanizeSnakeCase('__')).toBe('__');
    });
});

describe('translateColumn', () => {
    it('uses a translation when one exists', () => {
        const t = (key: string): string =>
            key === 'pages.query.columns.Merk' ? 'Brand' : key;

        expect(translateColumn('Merk', t)).toBe('Brand');
    });

    it('falls back to the humanized column name', () => {
        expect(translateColumn('FirstAdmissionDate', identity)).toBe(
            'First admission date',
        );
    });
});

describe('valueAxisLabel', () => {
    const t = (key: string): string => {
        const map: Record<string, string> = {
            'pages.query.axis.vehicles': 'Vehicles',
            'pages.query.axis.fn.avg': 'Average',
            'pages.query.axis.fn.sum': 'Total',
        };

        return map[key] ?? key;
    };

    function planWithAggregate(aggregate: Plan['aggregates'][number]): Plan {
        return {
            where: [],
            select: [],
            groupBy: [],
            aggregates: [aggregate],
            orderBy: [],
            limit: null,
            display: 'histogram',
            explanation: '',
        };
    }

    it('labels a count aggregate as the vehicle unit', () => {
        const plan = planWithAggregate({
            fn: 'count',
            field: null,
            alias: 'count',
        });

        expect(valueAxisLabel(plan, t)).toBe('Vehicles');
    });

    it('falls back to the vehicle unit when there is no aggregate', () => {
        expect(valueAxisLabel(planWithGroupBy([]), t)).toBe('Vehicles');
    });

    it('names the summarised field for non-count aggregates', () => {
        const plan = planWithAggregate({
            fn: 'avg',
            field: 'EmptyMass',
            alias: 'avg_mass',
        });

        expect(valueAxisLabel(plan, t)).toBe('Average Empty mass');
    });

    it('uses the function label alone when the aggregate has no field', () => {
        const plan = planWithAggregate({ fn: 'sum', field: null, alias: 'n' });

        expect(valueAxisLabel(plan, t)).toBe('Total');
    });
});

describe('findNumericKey', () => {
    it('returns the first numeric column (number or numeric string)', () => {
        expect(findNumericKey({ brand: 'Tesla', count: 12 })).toBe('count');
        expect(findNumericKey({ label: 'x', total: '34' })).toBe('total');
    });

    it('falls back to the first key when nothing is numeric', () => {
        expect(findNumericKey({ brand: 'Tesla', color: 'red' })).toBe('brand');
    });
});

describe('isDateLike', () => {
    it('detects ISO-prefixed date strings', () => {
        expect(isDateLike('2011-03-15')).toBe(true);
        expect(isDateLike('2011-03-15T00:00:00.000')).toBe(true);
    });

    it('rejects non-date values', () => {
        expect(isDateLike('2011')).toBe(false);
        expect(isDateLike(2011)).toBe(false);
        expect(isDateLike(null)).toBe(false);
    });
});

describe('bucketForColumn', () => {
    it('returns the bucket for a grouped column', () => {
        const plan = planWithGroupBy([{ field: 'datum', bucket: 'month' }]);

        expect(bucketForColumn(plan, 'datum')).toBe('month');
    });

    it('returns null when the column is not grouped', () => {
        const plan = planWithGroupBy([{ field: 'datum', bucket: 'month' }]);

        expect(bucketForColumn(plan, 'merk')).toBeNull();
    });
});

describe('formatBucketLabel', () => {
    it('renders an em dash for nullish values', () => {
        expect(formatBucketLabel(null, 'year', 'en')).toBe('—');
    });

    it('passes through when the bucket is none or the value is not a date', () => {
        expect(formatBucketLabel('Tesla', 'none', 'en')).toBe('Tesla');
        expect(formatBucketLabel('Tesla', 'month', 'en')).toBe('Tesla');
    });

    it('renders the year for year buckets via a string slice', () => {
        expect(formatBucketLabel('2011-03-15T00:00:00.000', 'year', 'en')).toBe(
            '2011',
        );
    });

    it('formats month and day buckets through Intl', () => {
        const value = '2011-03-15T00:00:00.000';
        const date = new Date(value);

        expect(formatBucketLabel(value, 'month', 'en')).toBe(
            date.toLocaleDateString('en-US', {
                month: 'short',
                year: 'numeric',
            }),
        );
        expect(formatBucketLabel(value, 'day', 'en')).toBe(
            date.toLocaleDateString('en-US', {
                day: 'numeric',
                month: 'short',
                year: 'numeric',
            }),
        );
    });

    it('returns the raw value for an unparseable date', () => {
        expect(formatBucketLabel('2011-13-45', 'month', 'en')).toBe(
            '2011-13-45',
        );
    });
});

describe('detectTimeGranularity', () => {
    it('defaults to day for an empty series', () => {
        expect(detectTimeGranularity([])).toBe('day');
    });

    it('detects yearly series when at least 80% of points sit on Jan 1', () => {
        expect(
            detectTimeGranularity([
                '2015-01-01',
                '2016-01-01',
                '2017-01-01',
                '2018-01-01',
                '2019-06-01',
            ]),
        ).toBe('year');
    });

    it('detects monthly series when at least 80% of points sit on day 1', () => {
        expect(
            detectTimeGranularity([
                '2015-02-01',
                '2015-03-01',
                '2015-04-01',
                '2015-05-01',
                '2015-06-15',
            ]),
        ).toBe('month');
    });

    it('falls back to day when points are scattered', () => {
        expect(
            detectTimeGranularity(['2015-02-03', '2015-03-09', '2015-04-21']),
        ).toBe('day');
    });
});

describe('fillTimeBuckets', () => {
    it('returns the input untouched for fewer than two points', () => {
        const points = [{ x: '2015-01-01T00:00:00.000', value: 1 }];

        expect(fillTimeBuckets(points, 'year')).toBe(points);
    });

    it('inserts zero-valued buckets for missing years', () => {
        const filled = fillTimeBuckets(
            [
                { x: '2015-01-01T00:00:00.000', value: 10 },
                { x: '2018-01-01T00:00:00.000', value: 5 },
            ],
            'year',
        );

        expect(filled.map((p) => p.x.slice(0, 10))).toEqual([
            '2015-01-01',
            '2016-01-01',
            '2017-01-01',
            '2018-01-01',
        ]);
        expect(filled.map((p) => p.value)).toEqual([10, 0, 0, 5]);
    });

    it('inserts zero-valued buckets for missing months across a year boundary', () => {
        const filled = fillTimeBuckets(
            [
                { x: '2015-11-01T00:00:00.000', value: 3 },
                { x: '2016-02-01T00:00:00.000', value: 7 },
            ],
            'month',
        );

        expect(filled.map((p) => p.x.slice(0, 10))).toEqual([
            '2015-11-01',
            '2015-12-01',
            '2016-01-01',
            '2016-02-01',
        ]);
        expect(filled.map((p) => p.value)).toEqual([3, 0, 0, 7]);
    });

    it('reattaches the canonical midnight suffix to every bucket', () => {
        const filled = fillTimeBuckets(
            [
                { x: '2015-01-01T00:00:00.000', value: 1 },
                { x: '2016-01-01T00:00:00.000', value: 2 },
            ],
            'year',
        );

        expect(filled.every((p) => p.x.endsWith('T00:00:00.000'))).toBe(true);
    });
});

describe('chartColor', () => {
    it('cycles through the five palette slots', () => {
        expect(chartColor(0)).toBe('var(--chart-1)');
        expect(chartColor(4)).toBe('var(--chart-5)');
        expect(chartColor(5)).toBe('var(--chart-1)');
    });
});

describe('formatPercent', () => {
    it('renders an em dash for non-finite ratios', () => {
        expect(formatPercent(Number.NaN, 'en')).toBe('—');
        expect(formatPercent(Number.POSITIVE_INFINITY, 'en')).toBe('—');
    });

    it('uses one fraction digit below 10% and none above', () => {
        expect(formatPercent(0.05, 'en')).toBe('5%');
        expect(formatPercent(0.123, 'en')).toBe('12%');
        expect(formatPercent(0.5, 'en')).toBe('50%');
    });
});
