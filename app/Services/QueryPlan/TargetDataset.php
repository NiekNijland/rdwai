<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

use NiekNijland\RDW\Datasets\DatasetId;

enum TargetDataset: string
{
    case RegisteredVehicles = 'RegisteredVehicles';
    case RegisteredVehicleFuels = 'RegisteredVehicleFuels';

    public function datasetId(): DatasetId
    {
        return match ($this) {
            self::RegisteredVehicles => DatasetId::RegisteredVehicles,
            self::RegisteredVehicleFuels => DatasetId::RegisteredVehicleFuels,
        };
    }
}
