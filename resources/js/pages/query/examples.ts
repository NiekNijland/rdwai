// Example prompts shown on the idle query page. Two surfaces draw from here:
// the typewriter placeholder cycles the flat SUGGESTIONS_* lists, and the
// discover cards pick one entry per visualization from DISCOVER_POOL.

// Curated against the planner's capabilities (RegisteredVehicles dataset for
// general vehicle facts; RegisteredVehicleFuels for engine power, CO2, fuel
// consumption). Each item maps to a clean display hint: count, bars,
// timeseries, table, record, stats, or histogram.
export const SUGGESTIONS_NL: readonly string[] = [
    'Hoeveel Tesla Model 3 zijn er in Nederland?',
    'Hoeveel Ferrari’s zijn er geregistreerd?',
    'Hoeveel BMW M3 staan er op kenteken?',
    'Hoeveel Land Rover Defender zijn er in Nederland?',
    'Hoeveel Porsche 911 zijn er geregistreerd?',
    'Hoeveel campers staan er op kenteken?',
    'Hoeveel motorfietsen zwaarder dan 1000 cc?',
    'Welk percentage auto’s heeft meer dan 150 kW vermogen?',
    'Hoeveel auto’s stoten meer dan 200 g/km CO2 uit?',
    'Hoeveel oranje voertuigen zijn er?',
    'Hoeveel Fiat 500’s uit 2015 zijn er?',
    'Hoeveel voertuigen ouder dan 30 jaar zijn er?',
    'Welke kleuren Volkswagen Up! uit 2017 zijn er?',
    'Kleurverdeling Audi A4 uit bouwjaar 2020',
    'Welke autokleur is het zeldzaamst?',
    'Welk merk heeft de meeste roze auto’s?',
    'Meest geregistreerde Tesla-modellen',
    'Top 10 populairste automerken',
    'Meest voorkomende kleuren in het hele register',
    'Volkswagen Golf-tenaamstellingen per maand in 2024',
    'Aantal Tesla’s per jaar sinds 2015',
    'Nieuwe Porsche-registraties per maand in 2024',
    'Aantal motorfietsen per bouwjaar sinds 2000',
    'Recente overschrijvingen Suzuki GSX-R 1100 uit 1991',
    'Top 5 zwaarste voertuigen op kenteken',
    'Top 10 snelste motorfietsen op kenteken',
    'Top 10 duurste Ferrari’s op catalogusprijs',
    'Recente Tesla-overschrijvingen',
    '10 oudste actieve motorfietsen',
    '10 nieuwste Bugatti’s op kenteken',
    'Toon alles over kenteken GT-486-N',
    'Toon alles over kenteken 42-JHB-6',
    'Toon alles over kenteken JD-72-LB',
    'Toyota in cijfers: aantal, gemiddelde massa en gemiddelde catalogusprijs',
    'Statistieken Volkswagen Golf: aantal en gemiddelde massa',
    'BMW stats: aantal, gemiddelde topsnelheid en gemiddelde catalogusprijs',
    'Verdeling van leeg gewicht van Volkswagen Up!',
    'Hoe is de cilinderinhoud van motorfietsen verdeeld?',
    'Verdeling aantal zitplaatsen bij personenauto’s',
    'Hoeveel APK-keuringen verlopen deze maand?',
    'Hoeveel verzekerde Toyota’s zijn er?',
    'Hoeveel taxi’s staan er op kenteken?',
    'Hoeveel voertuigen wachten op keuring?',
];

export const SUGGESTIONS_EN: readonly string[] = [
    'How many Tesla Model 3 are registered in the Netherlands?',
    'How many Ferraris are registered?',
    'How many BMW M3 in the Dutch register?',
    'How many Land Rover Defenders in the Netherlands?',
    'How many Porsche 911 are registered?',
    'How many campers are registered?',
    'How many motorcycles over 1000 cc?',
    'What percentage of cars have more than 150 kW of engine power?',
    'How many cars emit more than 200 g/km of CO2?',
    'How many orange vehicles are there?',
    'How many 2015 Fiat 500s are there?',
    'How many vehicles over 30 years old?',
    'What colors of Volkswagen Up! from 2017 are out there?',
    'Color breakdown of Audi A4 from model year 2020',
    'What is the rarest car color?',
    'Which brand has the most pink cars?',
    'Most-registered Tesla models',
    'Top 10 most popular car brands',
    'Most common colors across the entire register',
    'Volkswagen Golf transfers per month in 2024',
    'Tesla registrations per year since 2015',
    'New Porsche registrations per month in 2024',
    'Motorcycles per model year since 2000',
    'Recent transfers for Suzuki GSX-R 1100 (1991)',
    'Top 5 heaviest registered vehicles',
    'Top 10 fastest registered motorcycles',
    'Top 10 most expensive Ferraris by catalog price',
    'Recent Tesla transfers',
    '10 oldest active motorcycles',
    '10 newest Bugattis in the register',
    'Show everything about plate GT-486-N',
    'Show everything about plate 42-JHB-6',
    'Show everything about plate JD-72-LB',
    'Toyota in numbers: count, average mass and average catalog price',
    'Stats on Volkswagen Golf: count and average mass',
    'BMW stats: count, average top speed and average catalog price',
    'Distribution of curb weight of Volkswagen Up!',
    'How is motorcycle engine displacement distributed?',
    'Distribution of seat counts across passenger cars',
    'How many MOT inspections expire this month?',
    'How many insured Toyotas are there?',
    'How many taxis are registered?',
    'How many vehicles are awaiting inspection?',
];

export type DiscoverViz = 'kpi' | 'bars' | 'spark' | 'plate';

export type DiscoverItem = { question: string; viz: DiscoverViz };

type DiscoverEntry = { nl: string; en: string };

// Curated example pools, grouped by the viz they'll render. We pick one
// random entry per viz so the four cards always span all four visual styles.
const DISCOVER_POOL: Readonly<Record<DiscoverViz, readonly DiscoverEntry[]>> = {
    kpi: [
        {
            nl: 'Hoeveel Tesla Model 3 zijn er in Nederland?',
            en: 'How many Tesla Model 3 are registered in the Netherlands?',
        },
        {
            nl: 'Hoeveel Ferrari’s zijn er geregistreerd?',
            en: 'How many Ferraris are registered?',
        },
        {
            nl: 'Hoeveel BMW M3 staan er op kenteken?',
            en: 'How many BMW M3 in the Dutch register?',
        },
        {
            nl: 'Hoeveel Land Rover Defender zijn er in Nederland?',
            en: 'How many Land Rover Defenders in the Netherlands?',
        },
        {
            nl: 'Hoeveel Porsche 911 zijn er geregistreerd?',
            en: 'How many Porsche 911 are registered?',
        },
        {
            nl: 'Hoeveel campers staan er op kenteken?',
            en: 'How many campers are registered?',
        },
        {
            nl: 'Hoeveel motorfietsen zwaarder dan 1000 cc?',
            en: 'How many motorcycles over 1000 cc?',
        },
        {
            nl: 'Hoeveel oranje voertuigen zijn er?',
            en: 'How many orange vehicles are there?',
        },
        {
            nl: 'Hoeveel Fiat 500’s uit 2015 zijn er?',
            en: 'How many 2015 Fiat 500s are there?',
        },
        {
            nl: 'Hoeveel voertuigen ouder dan 30 jaar zijn er?',
            en: 'How many vehicles over 30 years old?',
        },
        {
            nl: 'Hoeveel APK-keuringen verlopen deze maand?',
            en: 'How many MOT inspections expire this month?',
        },
        {
            nl: 'Hoeveel verzekerde Toyota’s zijn er?',
            en: 'How many insured Toyotas are there?',
        },
        {
            nl: 'Hoeveel taxi’s staan er op kenteken?',
            en: 'How many taxis are registered?',
        },
        {
            nl: 'Hoeveel voertuigen wachten op keuring?',
            en: 'How many vehicles are awaiting inspection?',
        },
    ],
    bars: [
        {
            nl: 'Top 10 populairste automerken',
            en: 'Top 10 most popular car brands',
        },
        {
            nl: 'Meest voorkomende kleuren in het hele register',
            en: 'Most common colors across the entire register',
        },
        {
            nl: 'Kleurverdeling Audi A4 uit bouwjaar 2020',
            en: 'Color breakdown of Audi A4 from model year 2020',
        },
        {
            nl: 'Welke kleuren Volkswagen Up! uit 2017 zijn er?',
            en: 'What colors of Volkswagen Up! from 2017 are out there?',
        },
        {
            nl: 'Welke autokleur is het zeldzaamst?',
            en: 'What is the rarest car color?',
        },
        {
            nl: 'Welk merk heeft de meeste roze auto’s?',
            en: 'Which brand has the most pink cars?',
        },
        {
            nl: 'Meest geregistreerde Tesla-modellen',
            en: 'Most-registered Tesla models',
        },
        {
            nl: 'Verdeling aantal zitplaatsen bij personenauto’s',
            en: 'Distribution of seat counts across passenger cars',
        },
        {
            nl: 'Verdeling van leeg gewicht van Volkswagen Up!',
            en: 'Distribution of curb weight of Volkswagen Up!',
        },
        {
            nl: 'Hoe is de cilinderinhoud van motorfietsen verdeeld?',
            en: 'How is motorcycle engine displacement distributed?',
        },
    ],
    spark: [
        {
            nl: 'Aantal Tesla’s per jaar sinds 2015',
            en: 'Tesla registrations per year since 2015',
        },
        {
            nl: 'Volkswagen Golf-tenaamstellingen per maand in 2024',
            en: 'Volkswagen Golf transfers per month in 2024',
        },
        {
            nl: 'Nieuwe Porsche-registraties per maand in 2024',
            en: 'New Porsche registrations per month in 2024',
        },
        {
            nl: 'Aantal motorfietsen per bouwjaar sinds 2000',
            en: 'Motorcycles per model year since 2000',
        },
    ],
    plate: [
        {
            nl: 'Toon alles over kenteken GT-486-N',
            en: 'Show everything about plate GT-486-N',
        },
        {
            nl: 'Toon alles over kenteken 42-JHB-6',
            en: 'Show everything about plate 42-JHB-6',
        },
        {
            nl: 'Toon alles over kenteken JD-72-LB',
            en: 'Show everything about plate JD-72-LB',
        },
        {
            nl: 'Toon alles over kenteken R-915-FK',
            en: 'Show everything about plate R-915-FK',
        },
        {
            nl: 'Toon alles over kenteken 8-KZD-53',
            en: 'Show everything about plate 8-KZD-53',
        },
        {
            nl: 'Toon alles over kenteken 56-TV-PL',
            en: 'Show everything about plate 56-TV-PL',
        },
    ],
};

const DISCOVER_VIZ_ORDER: readonly DiscoverViz[] = [
    'kpi',
    'bars',
    'spark',
    'plate',
];

export function pickDiscoverItems(locale: string): DiscoverItem[] {
    return DISCOVER_VIZ_ORDER.map((viz) => {
        const pool = DISCOVER_POOL[viz];
        const entry = pool[Math.floor(Math.random() * pool.length)];

        return {
            viz,
            question: locale === 'nl' ? entry.nl : entry.en,
        };
    });
}
