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
use InvalidArgumentException;
use NiekNijland\RDW\Exceptions\RateLimitException;
use NiekNijland\RDW\Exceptions\RdwException;
use Throwable;

final class QueryController extends Controller
{
    public function index(): InertiaResponse
    {
        return Inertia::render('query/index');
    }

    public function run(
        RunQueryRequest $request,
        RunNaturalLanguageQuery $action,
    ): JsonResponse {
        try {
            $result = $action->execute($request->string('prompt')->toString());
        } catch (RateLimitException $e) {
            return response()->json([
                'error' => __('query.errors.rate_limited', ['seconds' => $e->retryAfterSeconds]),
            ], 429);
        } catch (QueryExecutionException $e) {
            $serialisedPlan = $this->serializePlan($e->plan);
            Log::warning('RDW query failed', [
                'message' => $e->getMessage(),
                'plan' => $serialisedPlan,
                'soql' => $e->soql,
                'url' => $e->url,
                'responseBody' => $e->responseBody,
            ]);

            return response()->json([
                'error' => __('query.errors.rejected'),
                'plan' => $serialisedPlan,
                'soql' => $e->soql,
                'url' => $e->url,
                'responseBody' => $e->responseBody,
            ], 422);
        } catch (InvalidArgumentException $e) {
            // Field-name / alias / enum validation failures from PlanFactory or
            // PlanRunner. The message references internal field names, so we
            // return the localized fallback to the user.
            Log::info('RDW plan invalid', ['message' => $e->getMessage()]);

            return response()->json([
                'error' => __('query.errors.malformed'),
            ], 422);
        } catch (RdwException $e) {
            Log::warning('RDW package error', ['message' => $e->getMessage()]);

            return response()->json([
                'error' => __('query.errors.rejected'),
            ], 422);
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'error' => __('query.errors.unexpected'),
            ], 500);
        }

        return response()->json([
            'plan' => $this->serializePlan($result['plan']),
            'soql' => $result['soql'],
            'url' => $result['url'],
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
