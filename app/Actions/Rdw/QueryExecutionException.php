<?php

declare(strict_types=1);

namespace App\Actions\Rdw;

use App\Services\QueryPlan\Plan;
use NiekNijland\RDW\Exceptions\HttpException;
use RuntimeException;
use Throwable;

/**
 * Wraps a runner failure with the plan, the SoQL params we sent, the request
 * URL, and the raw response body returned by RDW (when available). The
 * controller surfaces all four so the user can see the generated query
 * alongside the error instead of just a generic "rejected" message.
 */
final class QueryExecutionException extends RuntimeException
{
    /** @var array<string, string> */
    public readonly array $soql;

    public readonly string $url;

    public readonly ?string $responseBody;

    /**
     * @param array<string, string> $soql
     */
    public function __construct(
        public readonly Plan $plan,
        array $soql,
        string $url,
        Throwable $previous,
    ) {
        $this->soql = $soql;
        $this->url = $url;
        $this->responseBody = $previous instanceof HttpException ? $previous->responseBody : null;

        parent::__construct($previous->getMessage(), $previous->getCode(), $previous);
    }
}
