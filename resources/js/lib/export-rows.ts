export type ExportFormat = 'csv' | 'json';

/**
 * Browser-side downloader for the rows currently shown to the user. We keep
 * everything off the server because the rows have already been transferred
 * and the formatting is purely presentational.
 */
export function downloadRows(
    rows: Array<Record<string, unknown>>,
    format: ExportFormat,
    filenameBase: string,
): void {
    if (typeof window === 'undefined' || rows.length === 0) {
        return;
    }

    const safeBase =
        filenameBase
            .toLowerCase()
            .replace(/[^a-z0-9-_]+/g, '-')
            .replace(/^-+|-+$/g, '')
            .slice(0, 60) || 'rdwai-result';
    const stamp = new Date().toISOString().replace(/[:.]/g, '-');
    const filename = `${safeBase}-${stamp}.${format}`;

    const blob =
        format === 'csv'
            ? new Blob([rowsToCsv(rows)], {
                  type: 'text/csv;charset=utf-8',
              })
            : new Blob([JSON.stringify(rows, null, 2)], {
                  type: 'application/json;charset=utf-8',
              });

    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

function rowsToCsv(rows: Array<Record<string, unknown>>): string {
    const columns = collectColumns(rows);
    const header = columns.map(escapeCsv).join(',');
    const body = rows
        .map((row) =>
            columns.map((col) => escapeCsv(formatCsvCell(row[col]))).join(','),
        )
        .join('\n');

    return body.length > 0 ? `${header}\n${body}\n` : `${header}\n`;
}

function collectColumns(rows: Array<Record<string, unknown>>): string[] {
    const seen: string[] = [];
    const set = new Set<string>();

    for (const row of rows) {
        for (const key of Object.keys(row)) {
            if (!set.has(key)) {
                set.add(key);
                seen.push(key);
            }
        }
    }

    return seen;
}

function formatCsvCell(value: unknown): string {
    if (value === null || value === undefined) {
        return '';
    }

    if (typeof value === 'object') {
        return JSON.stringify(value);
    }

    return String(value);
}

function escapeCsv(value: string): string {
    if (/[",\n\r]/.test(value)) {
        return `"${value.replace(/"/g, '""')}"`;
    }

    return value;
}
