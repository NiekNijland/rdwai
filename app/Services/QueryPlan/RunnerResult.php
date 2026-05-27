<?php

declare(strict_types=1);

namespace App\Services\QueryPlan;

/**
 * Result of running a {@see Plan} against RDW: the normalised rows, the SoQL
 * params we sent (for the debug pane), and the fully-qualified request URL.
 *
 * `rows` and `soql` stay as arrays — their keys are query-dependent (row
 * columns vary per plan; SoQL param names are a `$select`/`$where`/… map) so
 * neither has a fixed shape to model. This wrapper does, hence the DTO.
 */
final readonly class RunnerResult
{
    /**
     * @param list<array<string, mixed>> $rows
     * @param array<string, string> $soql
     */
    public function __construct(
        public array $rows,
        public array $soql,
        public string $url,
    ) {
    }
}
