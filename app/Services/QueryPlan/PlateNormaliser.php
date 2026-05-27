<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

/**
 * Normalises a Dutch license plate to RDW's stored form (uppercase, no separators); mirrors frontend plate.ts.
 */
final class PlateNormaliser
{
    public static function normalise(string $value): string
    {
        return strtoupper((string) preg_replace('/[^0-9A-Za-z]/', '', $value));
    }
}
