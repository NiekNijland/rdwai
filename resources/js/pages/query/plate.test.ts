import { describe, expect, it } from 'vitest';

import {
    detectPlate,
    extractPlateFromText,
    formatPlate,
    isMotorcyclePlate,
    splitPlateLines,
} from './plate';

describe('detectPlate', () => {
    it('accepts every Dutch sidecode and normalises separators/casing', () => {
        const cases: Array<[string, string]> = [
            ['AB-12-34', 'AB1234'], // 1: XX-99-99
            ['12-34-AB', '1234AB'], // 2: 99-99-XX
            ['12-AB-34', '12AB34'], // 3: 99-XX-99
            ['JD-72-LB', 'JD72LB'], // 4: XX-99-XX
            ['56-TV-PL', '56TVPL'], // 6: 99-XX-XX
            ['42-JHB-6', '42JHB6'], // 7: 99-XXX-9
            ['8-KZD-53', '8KZD53'], // 8: 9-XXX-99
            ['GT-486-N', 'GT486N'], // 9: XX-999-X
            ['R-915-FK', 'R915FK'], // 10: X-999-XX
        ];

        for (const [input, expected] of cases) {
            expect(detectPlate(input)).toBe(expected);
            expect(detectPlate(input.toLowerCase())).toBe(expected);
        }
    });

    it('rejects strings that are not six plate characters', () => {
        expect(detectPlate('ABC')).toBeNull();
        expect(detectPlate('GT-486-NX')).toBeNull();
        expect(detectPlate('')).toBeNull();
    });

    it('rejects six characters that match no sidecode pattern', () => {
        expect(detectPlate('ABCDEF')).toBeNull();
        expect(detectPlate('123456')).toBeNull();
        expect(detectPlate('ABC123')).toBeNull();
    });
});

describe('formatPlate', () => {
    it('inserts dashes at the sidecode boundaries', () => {
        expect(formatPlate('GT486N')).toBe('GT-486-N');
        expect(formatPlate('42JHB6')).toBe('42-JHB-6');
        expect(formatPlate('8KZD53')).toBe('8-KZD-53');
    });

    it('returns the input untouched when it matches no sidecode', () => {
        expect(formatPlate('ABCDEF')).toBe('ABCDEF');
    });
});

describe('isMotorcyclePlate', () => {
    it('treats M-series plates as motorcycles regardless of separators', () => {
        expect(isMotorcyclePlate('ML-82-BV')).toBe(true);
        expect(isMotorcyclePlate('ml82bv')).toBe(true);
        expect(isMotorcyclePlate('MF-001-K')).toBe(true);
    });

    it('treats every other series as a regular plate', () => {
        expect(isMotorcyclePlate('GT-486-N')).toBe(false);
        expect(isMotorcyclePlate('8-KZD-53')).toBe(false);
        expect(isMotorcyclePlate('')).toBe(false);
    });
});

describe('splitPlateLines', () => {
    it('puts every group but the last on the top line', () => {
        expect(splitPlateLines('ML-82-BV')).toEqual(['ML-82', 'BV']);
        expect(splitPlateLines('14-MB-BP')).toEqual(['14-MB', 'BP']);
    });

    it('returns an empty second line when there are no separators', () => {
        expect(splitPlateLines('ML82BV')).toEqual(['ML82BV', '']);
    });
});

describe('extractPlateFromText', () => {
    it('finds a plate embedded in a sentence', () => {
        expect(extractPlateFromText('Toon alles over kenteken GT-486-N')).toBe(
            'GT-486-N',
        );
    });

    it('finds a plate written without separators', () => {
        expect(extractPlateFromText('kenteken GT486N graag')).toBe('GT-486-N');
    });

    it('returns null when no plate is present', () => {
        expect(
            extractPlateFromText('How many Teslas are registered?'),
        ).toBeNull();
        expect(extractPlateFromText('')).toBeNull();
    });
});
