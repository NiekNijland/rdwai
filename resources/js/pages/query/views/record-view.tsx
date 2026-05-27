import { useTranslation } from '@/hooks/use-translation';
import { cn } from '@/lib/utils';

import {
    formatBucketLabel,
    formatCell,
    isDateLike,
    translateColumn,
} from '../format';
import { formatPlate, isMotorcyclePlate, splitPlateLines } from '../plate';
import type { QueryRow } from '../types';

const SECTION_ORDER = [
    'identification',
    'classification',
    'specifications',
    'dimensions',
    'dates',
    'financial',
    'status',
    'other',
] as const;

type SectionKey = (typeof SECTION_ORDER)[number];

const FIELD_SECTION: Record<string, SectionKey> = {
    LicensePlate: 'identification',
    Brand: 'identification',
    CommercialName: 'identification',
    Type: 'identification',
    Variant: 'identification',
    Execution: 'identification',
    TypeApprovalNumber: 'identification',

    VehicleType: 'classification',
    Configuration: 'classification',
    EuropeanVehicleCategory: 'classification',
    EuropeanVehicleCategoryAddition: 'classification',
    EuropeanVariantCategoryAddition: 'classification',
    NetherlandsSubcategory: 'classification',

    PrimaryColor: 'specifications',
    SecondaryColor: 'specifications',
    SeatCount: 'specifications',
    DoorCount: 'specifications',
    CylinderCount: 'specifications',
    EngineDisplacement: 'specifications',
    GasInstallationType: 'specifications',
    WheelCount: 'specifications',
    StandingPlaceCount: 'specifications',
    WheelchairPlaceCount: 'specifications',
    MaximumDesignSpeed: 'specifications',
    LoadCapacity: 'specifications',
    PowerToReadyMassRatio: 'specifications',
    EfficiencyClassification: 'specifications',
    LegalPassengerSeatCount: 'specifications',

    EmptyMass: 'dimensions',
    ReadyToDriveMass: 'dimensions',
    PermittedMaximumMass: 'dimensions',
    TechnicalMaximumMass: 'dimensions',
    MaximumCombinationMass: 'dimensions',
    Length: 'dimensions',
    Width: 'dimensions',
    Height: 'dimensions',
    Wheelbase: 'dimensions',

    RegistrationDate: 'dates',
    FirstAdmissionDate: 'dates',
    FirstNetherlandsRegistrationDate: 'dates',
    ApkExpiryDate: 'dates',
    TachographExpiryDate: 'dates',
    LastOdometerRegistrationYear: 'dates',

    CatalogPrice: 'financial',
    GrossBpm: 'financial',

    IsWamInsured: 'status',
    IsTaxi: 'status',
    IsExportRegistration: 'status',
    IsWaitingForInspection: 'status',
    HasOpenRecall: 'status',
    CanBeTransferred: 'status',
    OdometerJudgement: 'status',
    OdometerJudgementCode: 'status',
};

// RDW's `voertuigsoort` value for motorcycles. Stored verbatim (Title-case
// Dutch), so the projected VehicleType column carries this exact string; it
// drives the square two-line plate rendering below.
const MOTORCYCLE_VEHICLE_TYPE = 'Motorfiets';

export function RecordView({
    rows,
    locale,
}: {
    rows: QueryRow[];
    locale: string;
}) {
    const { t } = useTranslation();
    const record = rows[0] ?? {};
    const sections = groupBySection(record);
    const plate =
        typeof record.LicensePlate === 'string' && record.LicensePlate !== ''
            ? record.LicensePlate
            : null;
    const isMotorcycle =
        record.VehicleType === MOTORCYCLE_VEHICLE_TYPE ||
        (plate !== null && isMotorcyclePlate(plate));

    return (
        <div className="flex flex-col gap-6">
            {plate !== null && (
                <LicensePlateBadge plate={plate} isMotorcycle={isMotorcycle} />
            )}

            <div className="grid grid-cols-1 gap-4 md:grid-cols-2">
                {SECTION_ORDER.map((key) => {
                    const entries = sections[key];

                    if (entries === undefined || entries.length === 0) {
                        return null;
                    }

                    return (
                        <section
                            key={key}
                            className={cn(
                                'rounded-lg border border-neutral-200 bg-white p-4 dark:border-neutral-800 dark:bg-neutral-950',
                                key === 'identification' && 'md:col-span-2',
                            )}
                        >
                            <h3 className="mb-3 text-[11px] font-semibold tracking-wide text-neutral-500 uppercase dark:text-neutral-400">
                                {t(`pages.query.recordSections.${key}`)}
                            </h3>
                            <dl className="grid grid-cols-2 gap-x-4 gap-y-2 sm:grid-cols-3">
                                {entries.map(([field, value]) => (
                                    <div
                                        key={field}
                                        className="flex flex-col gap-0.5"
                                    >
                                        <dt className="text-[11px] text-neutral-500 dark:text-neutral-400">
                                            {translateColumn(field, t)}
                                        </dt>
                                        <dd className="text-sm tabular-nums">
                                            {isDateLike(value)
                                                ? formatBucketLabel(
                                                      value,
                                                      'day',
                                                      locale,
                                                  )
                                                : formatCell(value, locale, t)}
                                        </dd>
                                    </div>
                                ))}
                            </dl>
                        </section>
                    );
                })}
            </div>
        </div>
    );
}

function LicensePlateBadge({
    plate,
    isMotorcycle,
}: {
    plate: string;
    isMotorcycle: boolean;
}) {
    const cleaned = plate.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    const formatted = formatPlate(cleaned);

    if (isMotorcycle) {
        const [top, bottom] = splitPlateLines(formatted);

        return (
            <div className="flex justify-center">
                <div className="inline-flex items-stretch overflow-hidden rounded-md border-2 border-black bg-[#ffd400] font-mono shadow-sm">
                    <div className="flex w-7 items-center justify-center bg-[#0033a0] text-[10px] font-bold text-white">
                        NL
                    </div>
                    <div className="flex flex-col items-center justify-center px-3 py-2 leading-none">
                        <span className="text-2xl font-bold tracking-[0.08em] text-black tabular-nums sm:text-3xl">
                            {top}
                        </span>
                        {bottom !== '' && (
                            <span className="mt-0.5 text-2xl font-bold tracking-[0.08em] text-black tabular-nums sm:text-3xl">
                                {bottom}
                            </span>
                        )}
                    </div>
                </div>
            </div>
        );
    }

    return (
        <div className="flex justify-center">
            <div className="inline-flex items-stretch overflow-hidden rounded-md border-2 border-black bg-[#ffd400] font-mono shadow-sm">
                <div className="flex w-7 items-center justify-center bg-[#0033a0] text-[10px] font-bold text-white">
                    NL
                </div>
                <div className="px-4 py-2 text-2xl font-bold tracking-[0.15em] text-black tabular-nums sm:text-3xl">
                    {formatted}
                </div>
            </div>
        </div>
    );
}

function groupBySection(
    record: QueryRow,
): Partial<Record<SectionKey, Array<[string, unknown]>>> {
    const out: Partial<Record<SectionKey, Array<[string, unknown]>>> = {};

    for (const [field, value] of Object.entries(record)) {
        if (value === null || value === undefined || value === '') {
            continue;
        }

        const section = FIELD_SECTION[field] ?? 'other';
        out[section] = out[section] ?? [];
        out[section]!.push([field, value]);
    }

    return out;
}
