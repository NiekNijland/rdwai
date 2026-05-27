// Dutch license plate "sidecodes" — all are 6 alphanumeric chars arranged
// into one of these letter/digit patterns. Anything else is not a plate.
const NL_PLATE_SIDECODES: readonly RegExp[] = [
    /^([A-Z]{2})(\d{2})(\d{2})$/, // 1: XX-99-99
    /^(\d{2})(\d{2})([A-Z]{2})$/, // 2: 99-99-XX
    /^(\d{2})([A-Z]{2})(\d{2})$/, // 3: 99-XX-99
    /^([A-Z]{2})(\d{2})([A-Z]{2})$/, // 4: XX-99-XX
    /^([A-Z]{2})([A-Z]{2})(\d{2})$/, // 5: XX-XX-99
    /^(\d{2})([A-Z]{2})([A-Z]{2})$/, // 6: 99-XX-XX
    /^(\d{2})([A-Z]{3})(\d)$/, // 7: 99-XXX-9
    /^(\d)([A-Z]{3})(\d{2})$/, // 8: 9-XXX-99
    /^([A-Z]{2})(\d{3})([A-Z])$/, // 9: XX-999-X
    /^([A-Z])(\d{3})([A-Z]{2})$/, // 10: X-999-XX
    /^([A-Z]{3})(\d{2})([A-Z])$/, // 11: XXX-99-X
    /^([A-Z])(\d{2})([A-Z]{3})$/, // 12: X-99-XXX
    /^(\d)([A-Z]{2})(\d{3})$/, // 13: 9-XX-999
];

export function detectPlate(input: string): string | null {
    const cleaned = input
        .trim()
        .replace(/[^0-9A-Za-z]/g, '')
        .toUpperCase();

    if (cleaned.length !== 6) {
        return null;
    }

    if (!NL_PLATE_SIDECODES.some((re) => re.test(cleaned))) {
        return null;
    }

    return cleaned;
}

export function formatPlate(plate: string): string {
    for (const re of NL_PLATE_SIDECODES) {
        const match = plate.match(re);

        if (match !== null) {
            return `${match[1]}-${match[2]}-${match[3]}`;
        }
    }

    return plate;
}

/**
 * Dutch motorcycle plates are issued in the "M" series, so the plate always
 * starts with an M. That first letter is the only signal available before the
 * vehicle's RDW record is fetched, so the composer chip relies on it to pick
 * the square two-line plate rendering. Accepts a raw or dash-formatted plate.
 */
export function isMotorcyclePlate(plate: string): boolean {
    return plate
        .trim()
        .replace(/[^0-9A-Za-z]/g, '')
        .toUpperCase()
        .startsWith('M');
}

/**
 * Splits a dash-formatted plate into two rows for the square motorcycle plate:
 * all groups but the last on top, the final group below
 * (e.g. "14-MB-BP" → ["14-MB", "BP"]). Returns an empty second row when the
 * plate has no separators so the caller renders a single line.
 */
export function splitPlateLines(formatted: string): [string, string] {
    const groups = formatted.split('-');

    if (groups.length < 2) {
        return [formatted, ''];
    }

    return [groups.slice(0, -1).join('-'), groups[groups.length - 1]];
}

// A plate has at most three groups, so it can span at most three
// whitespace-separated tokens ("GT-486-N", "GT486N" and "GT 486 N" are all the
// same plate). Anything wider than that is sentence text, never a plate.
const MAX_PLATE_TOKENS = 3;

export function extractPlateFromText(text: string): string | null {
    // Split on whitespace only — a space is a token boundary, never part of a
    // plate. (detectPlate still strips dashes inside a token, so "GT-486-N"
    // stays one token.) Then probe runs of up to three adjacent tokens, which
    // recovers space-separated plates without ever merging a whole sentence
    // into one oversized candidate.
    const tokens = text.split(/\s+/).filter((token) => token.length > 0);

    for (let start = 0; start < tokens.length; start++) {
        for (
            let size = 1;
            size <= MAX_PLATE_TOKENS && start + size <= tokens.length;
            size++
        ) {
            const plate = detectPlate(
                tokens.slice(start, start + size).join(''),
            );

            if (plate !== null) {
                return formatPlate(plate);
            }
        }
    }

    return null;
}
