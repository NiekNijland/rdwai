import { render, screen } from '@testing-library/react';
import { describe, expect, it } from 'vitest';

import { ValueTooltipRow } from './value-tooltip-row';

describe('ValueTooltipRow', () => {
    it('pairs the axis label with the locale-grouped value', () => {
        render(<ValueTooltipRow label="Voertuigen" value={1005} locale="nl" />);

        expect(screen.getByText('Voertuigen')).toBeInTheDocument();
        expect(screen.getByText('1.005')).toBeInTheDocument();
    });

    it('groups the value with English separators under the en locale', () => {
        render(<ValueTooltipRow label="Vehicles" value={1005} locale="en" />);

        expect(screen.getByText('Vehicles')).toBeInTheDocument();
        expect(screen.getByText('1,005')).toBeInTheDocument();
    });

    it('coerces numeric strings before formatting', () => {
        render(<ValueTooltipRow label="Vehicles" value="72184" locale="en" />);

        expect(screen.getByText('72,184')).toBeInTheDocument();
    });
});
