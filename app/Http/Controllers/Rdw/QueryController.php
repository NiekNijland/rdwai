<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rdw;

use App\Actions\Rdw\QueryExecutionException;
use App\Actions\Rdw\RunNaturalLanguageQuery;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rdw\RunQueryRequest;
use App\Services\QueryPlan\Plan;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use NiekNijland\RDW\Exceptions\RateLimitException;
use NiekNijland\RDW\Exceptions\RdwException;
use Throwable;

final class QueryController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('query/index', [
            'examples' => [
                'How many white Volkswagen Ups from February 2017 are registered and insured?',
                'What colors of Toyota Aygo are registered, and how many per color?',
                'Show me 10 red BMWs with their license plate, model and registration date',
                'How many electric Tesla Model 3 are insured in the Netherlands?',
            ],
        ]);
    }

    public function run(RunQueryRequest $request, RunNaturalLanguageQuery $action): JsonResponse
    {
        try {
            $result = $action->execute($request->string('prompt')->toString());
        } catch (RateLimitException $e) {
            return response()->json([
                'error' => 'RDW rate limit reached. Try again in '.$e->retryAfterSeconds.'s.',
            ], 429);
        } catch (QueryExecutionException $e) {
            Log::warning('RDW query failed', [
                'message' => $e->getMessage(),
                'plan' => $this->serializePlan($e->plan),
            ]);

            return response()->json([
                'error' => 'The generated query was rejected by RDW: '.$e->getMessage(),
                'plan' => $this->serializePlan($e->plan),
            ], 422);
        } catch (RdwException|\InvalidArgumentException $e) {
            return response()->json([
                'error' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => 'Something went wrong building or running the query.',
            ], 500);
        }

        return response()->json([
            'plan' => $this->serializePlan($result['plan']),
            'soql' => $result['soql'],
            'rows' => $result['rows'],
            'displayHint' => $result['plan']->display->value,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializePlan(Plan $plan): array
    {
        return [
            'where' => array_map(static fn ($c): array => [
                'field' => $c->field,
                'op' => $c->op->value,
                'value' => $c->value,
            ], $plan->where),
            'select' => $plan->select,
            'groupBy' => $plan->groupBy,
            'aggregates' => array_map(static fn ($a): array => [
                'fn' => $a->fn->value,
                'field' => $a->field,
                'alias' => $a->alias,
            ], $plan->aggregates),
            'orderBy' => array_map(static fn ($o): array => [
                'expr' => $o->expr,
                'direction' => $o->direction->value,
            ], $plan->orderBy),
            'limit' => $plan->limit,
            'display' => $plan->display->value,
            'explanation' => $plan->explanation,
        ];
    }
}
