import type { Bucket, Plan, QueryRow } from './types';

export function localeTag(locale: string): string {
    return locale === 'nl' ? 'nl-NL' : 'en-US';
}

export function formatNumber(v: unknown, locale: string): string {
    const n = typeof v === 'number' ? v : Number(v);

    return Number.isFinite(n)
        ? n.toLocaleString(localeTag(locale))
        : String(v ?? '');
}

export function formatCell(
    v: unknown,
    locale: string,
    t: (key: string) => string,
): string {
    if (v === null || v === undefined) {
        return '—';
    }

    if (typeof v === 'boolean') {
        return v ? t('pages.query.boolean.yes') : t('pages.query.boolean.no');
    }

    if (typeof v === 'number') {
        return formatNumber(v, locale);
    }

    return String(v);
}

// Acronyms that should stay uppercase in humanized output ("ApkExpiryDate"
// -> "APK expiry date" instead of "Apk expiry date").
const ACRONYMS = new Set(['APK', 'BPM', 'WAM', 'NL', 'EU', 'VAT', 'ID']);

// "FirstAdmissionDate" -> "First admission date"; "ApkExpiryDate" -> "APK expiry date".
export function humanizePascalCase(name: string): string {
    const tokens = name.match(/[A-Z][a-z]+|[A-Z]+(?![a-z])|[0-9]+/g);

    if (tokens === null || tokens.length === 0) {
        return name;
    }

    const normalized = tokens.map((token, index) => {
        const upper = token.toUpperCase();

        if (ACRONYMS.has(upper)) {
            return upper;
        }

        const lower = token.toLowerCase();

        return index === 0
            ? lower.charAt(0).toUpperCase() + lower.slice(1)
            : lower;
    });

    return normalized.join(' ');
}

// "avg_mass" / "total-count" -> "Avg mass" / "Total count"
export function humanizeSnakeCase(alias: string): string {
    const cleaned = alias.replace(/[_-]+/g, ' ').trim();

    if (cleaned.length === 0) {
        return alias;
    }

    return cleaned.charAt(0).toUpperCase() + cleaned.slice(1).toLowerCase();
}

export function findNumericKey(row: QueryRow): string | undefined {
    for (const [k, v] of Object.entries(row)) {
        if (
            typeof v === 'number' ||
            (typeof v === 'string' && v !== '' && !Number.isNaN(Number(v)))
        ) {
            return k;
        }
    }

    return Object.keys(row)[0];
}

export function isDateLike(value: unknown): boolean {
    if (typeof value !== 'string') {
        return false;
    }

    return /^\d{4}-\d{2}-\d{2}/.test(value);
}

// The plan is authoritative for date granularity: a column appears in
// `plan.groupBy` exactly when the user asked for a date-trunc'd breakdown and
// the bucket on that entry says how coarse to render. Sniffing the rows
// instead would mis-label naturally Jan-1st dates as year-truncated.
export function bucketForColumn(plan: Plan, column: string): Bucket | null {
    const key = plan.groupBy.find((k) => k.field === column);

    return key === undefined ? null : key.bucket;
}

// Format a group-by value using the bucket from the query plan, so date-truncated
// columns render as "2011" / "Mar 2011" / "15 Mar 2011" instead of raw ISO strings.
export function formatBucketLabel(
    value: unknown,
    bucket: Bucket,
    locale: string,
): string {
    if (value === null || value === undefined) {
        return '—';
    }

    if (bucket === 'none' || typeof value !== 'string' || !isDateLike(value)) {
        return String(value);
    }

    if (bucket === 'year') {
        return value.slice(0, 4);
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return value;
    }

    if (bucket === 'month') {
        return date.toLocaleDateString(localeTag(locale), {
            month: 'short',
            year: 'numeric',
        });
    }

    return date.toLocaleDateString(localeTag(locale), {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

// 5 chart slots in the design system. We cycle for groups with more categories.
const CHART_PALETTE_SIZE = 5;

export function chartColor(index: number): string {
    return `var(--chart-${(index % CHART_PALETTE_SIZE) + 1})`;
}

export function formatPercent(ratio: number, locale: string): string {
    if (!Number.isFinite(ratio)) {
        return '—';
    }

    return new Intl.NumberFormat(localeTag(locale), {
        style: 'percent',
        maximumFractionDigits: ratio < 0.1 ? 1 : 0,
    }).format(ratio);
}
