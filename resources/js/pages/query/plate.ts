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

export function extractPlateFromText(text: string): string | null {
    const candidates = text.match(/[0-9A-Za-z]+(?:[-\s][0-9A-Za-z]+)*/g);

    if (candidates === null) {
        return null;
    }

    for (const candidate of candidates) {
        const plate = detectPlate(candidate);

        if (plate !== null) {
            return formatPlate(plate);
        }
    }

    return null;
}
