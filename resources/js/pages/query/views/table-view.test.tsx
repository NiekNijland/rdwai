import { screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { renderWithI18n } from '@/test/render';

import type { Plan } from '../types';
import { TableView } from './table-view';

function plan(groupBy: Plan['groupBy'] = []): Plan {
    return {
        dataset: 'RegisteredVehicles',
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

describe('TableView', () => {
    it('renders one column header per field and formats numeric cells', () => {
        renderWithI18n(
            <TableView
                rows={[{ Merk: 'TESLA', count: 1234 }]}
                plan={plan()}
                locale="en"
            />,
        );

        expect(screen.getAllByRole('columnheader')).toHaveLength(2);
        expect(screen.getByText('TESLA')).toBeInTheDocument();
        expect(screen.getByText('1,234')).toBeInTheDocument();
    });

    it('formats grouped date columns using the plan bucket', () => {
        renderWithI18n(
            <TableView
                rows={[{ Bouwjaar: '2020-01-01T00:00:00.000' }]}
                plan={plan([{ field: 'Bouwjaar', bucket: 'year' }])}
                locale="en"
            />,
        );

        expect(screen.getByText('2020')).toBeInTheDocument();
    });

    it('renders an em dash for null cells', () => {
        renderWithI18n(
            <TableView rows={[{ Kleur: null }]} plan={plan()} locale="en" />,
        );

        expect(screen.getByText('—')).toBeInTheDocument();
    });
});
