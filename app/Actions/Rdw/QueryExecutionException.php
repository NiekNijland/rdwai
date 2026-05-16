<?php

declare(strict_types=1);

namespace App\Actions\Rdw;

use App\Services\QueryPlan\Plan;
use RuntimeException;
use Throwable;

/**
 * Wraps a runner failure with the plan that triggered it so the controller
 * can surface both the original error and the (likely-malformed) plan to the
 * user / log.
 */
final class QueryExecutionException extends RuntimeException
{
    public function __construct(public readonly Plan $plan, Throwable $previous)
    {
        parent::__construct($previous->getMessage(), 0, $previous);
    }
}
