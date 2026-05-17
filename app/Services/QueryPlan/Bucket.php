<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

/**
 * Date truncation granularity for a {@see GroupKey} on a date field.
 *
 * `None` means group by the raw stored value (daily granularity for dates,
 * exact value otherwise). The other cases emit SoQL `date_trunc_y`,
 * `date_trunc_ym`, or `date_trunc_ymd` so a "per year" / "per month" question
 * actually produces yearly/monthly buckets instead of one row per day.
 */
enum Bucket: string
{
    case None = 'none';
    case Year = 'year';
    case Month = 'month';
    case Day = 'day';
}
