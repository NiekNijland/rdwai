import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWithI18n } from '@/test/render';

import type { DisplayHint, Plan, QueryResult } from '../types';
import { ResultBody } from './result-body';

function basePlan(display: DisplayHint): Plan {
    return {
        dataset: 'RegisteredVehicles',
        where: [],
        select: [],
        groupBy: [],
        aggregates: [{ fn: 'count', field: null, alias: 'count' }],
        orderBy: [],
        limit: null,
        display,
        explanation: '',
    };
}

function result(overrides: Partial<QueryResult>): QueryResult {
    const display = overrides.displayHint ?? 'table';

    return {
        prompt: '',
        plan: basePlan(display),
        soql: {},
        url: '',
        rows: [],
        displayHint: display,
        rating: null,
        comment: null,
        model: '',
        tokens: { prompt: 0, completion: 0, cacheRead: 0, thought: 0 },
        estimatedCost: null,
        ...overrides,
    };
}

describe('ResultBody', () => {
    it('shows the refusal marker for unsupported questions', () => {
        const { container } = renderWithI18n(
            <ResultBody
                result={result({ displayHint: 'unsupported' })}
                locale="en"
            />,
        );

        // No body copy — just the icon — and crucially not the empty-state text.
        expect(
            screen.queryByText('No rows matched this query.'),
        ).not.toBeInTheDocument();
        expect(container.querySelector('svg')).toBeInTheDocument();
    });

    it('shows the empty state when a data query returns no rows', () => {
        renderWithI18n(
            <ResultBody
                result={result({ displayHint: 'table', rows: [] })}
                locale="en"
            />,
        );

        expect(
            screen.getByText('No rows matched this query.'),
        ).toBeInTheDocument();
    });

    it('renders a derived figure regardless of row count', () => {
        renderWithI18n(
            <ResultBody
                result={result({
                    displayHint: 'count',
                    rows: [],
                    presentation: {
                        resultRef: 'a',
                        display: 'count',
                        derive: null,
                        derived: {
                            op: 'percentage',
                            value: 0.5,
                            numerator: 5,
                            denominator: 10,
                        },
                        explanation: '',
                    },
                })}
                locale="en"
            />,
        );

        expect(screen.getByText('50%')).toBeInTheDocument();
    });

    it('routes the count hint to the big-number view', () => {
        renderWithI18n(
            <ResultBody
                result={result({
                    displayHint: 'count',
                    rows: [{ count: 50 }],
                })}
                locale="en"
            />,
        );

        expect(screen.getByText('50')).toBeInTheDocument();
        expect(screen.getByText('matching vehicles')).toBeInTheDocument();
    });

    it('falls back to a table for the table hint', () => {
        renderWithI18n(
            <ResultBody
                result={result({
                    displayHint: 'table',
                    rows: [{ value: 'hello' }],
                })}
                locale="en"
            />,
        );

        expect(screen.getByRole('table')).toBeInTheDocument();
        expect(screen.getByText('hello')).toBeInTheDocument();
    });
});
