import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWithI18n } from '@/test/render';

import type { Plan } from '../types';
import { CountView } from './count-view';

function countPlan(): Plan {
    return {
        dataset: 'RegisteredVehicles',
        where: [],
        select: [],
        groupBy: [],
        aggregates: [{ fn: 'count', field: null, alias: 'count' }],
        orderBy: [],
        limit: null,
        display: 'count',
        explanation: '',
    };
}

describe('CountView', () => {
    it('renders the aggregate value with locale grouping and a label', () => {
        renderWithI18n(
            <CountView
                rows={[{ count: 72184 }]}
                plan={countPlan()}
                locale="en"
            />,
        );

        expect(screen.getByText('72,184')).toBeInTheDocument();
        expect(screen.getByText('matching vehicles')).toBeInTheDocument();
    });

    it('groups the number with Dutch separators under the nl locale', () => {
        renderWithI18n(
            <CountView
                rows={[{ count: 72184 }]}
                plan={countPlan()}
                locale="nl"
            />,
            { locale: 'nl' },
        );

        expect(screen.getByText('72.184')).toBeInTheDocument();
    });
});
