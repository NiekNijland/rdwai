<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use BackedEnum;
use NiekNijland\RDW\Fields\RegisteredVehicleField;
use NiekNijland\RDW\Fields\RegisteredVehicleFuelField;

final class FieldLookup
{
    /** @var array<string, array<string, BackedEnum>> */
    private static array $byDataset = [];

    public static function tryGet(TargetDataset $dataset, string $name): ?BackedEnum
    {
        return self::map($dataset)[$name] ?? null;
    }

    /**
     * @return array<string, BackedEnum>
     */
    private static function map(TargetDataset $dataset): array
    {
        if (! isset(self::$byDataset[$dataset->value])) {
            $cases = match ($dataset) {
                TargetDataset::RegisteredVehicles => RegisteredVehicleField::cases(),
                TargetDataset::RegisteredVehicleFuels => RegisteredVehicleFuelField::cases(),
            };

            $map = [];
            foreach ($cases as $case) {
                $map[$case->name] = $case;
            }
            self::$byDataset[$dataset->value] = $map;
        }

        return self::$byDataset[$dataset->value];
    }
}
