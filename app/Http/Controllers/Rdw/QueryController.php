<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rdw;

use App\Actions\Rdw\FindPopularQueries;
use App\Actions\Rdw\PersistQueryRun;
use App\Actions\Rdw\QueryExecutionException;
use App\Actions\Rdw\RunNaturalLanguageQuery;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rdw\RunQueryRequest;
use App\Http\Requests\Rdw\SubmitFeedbackRequest;
use App\Models\QueryRun;
use App\Services\QueryPlan\PlanPresenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use NiekNijland\RDW\Exceptions\RateLimitException;
use NiekNijland\RDW\Exceptions\RdwException;
use Throwable;

final class QueryController extends Controller
{
    public function index(Request $request): InertiaResponse
    {
        $sharedRun = null;
        $slug = $request->query('q');

        if (is_string($slug) && $slug !== '') {
            $run = QueryRun::query()->where('slug', $slug)->first();

            if ($run instanceof QueryRun) {
                $sharedRun = $this->serializeRun($run);
            }
        }

        return Inertia::render('query/index', [
            'sharedRun' => $sharedRun,
        ]);
    }

    public function run(
        RunQueryRequest $request,
        RunNaturalLanguageQuery $action,
        PersistQueryRun $persist,
    ): JsonResponse {
        $prompt = $request->string('prompt')->toString();
        $locale = Locale::tryFrom(app()->getLocale()) ?? Locale::English;

        try {
            $result = $action->execute($prompt, $locale);
        } catch (RateLimitException $e) {
            return response()->json([
                'error' => __('query.errors.rate_limited', ['seconds' => $e->retryAfterSeconds]),
            ], 429);
        } catch (QueryExecutionException $e) {
            $serialisedPlan = PlanPresenter::toArray($e->plan);
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

        $user = $request->user();
        $run = $persist->execute(
            prompt: $prompt,
            locale: app()->getLocale(),
            plan: $result['plan'],
            rows: $result['rows'],
            soql: $result['soql'],
            url: $result['url'],
            userId: $user !== null ? (string) $user->getAuthIdentifier() : null,
            model: $result['model'],
            tokens: $result['tokens'],
            estimatedCost: $result['estimatedCost'],
        );

        return response()->json([
            'slug' => $run->slug,
            'plan' => PlanPresenter::toArray($result['plan']),
            'soql' => $result['soql'],
            'url' => $result['url'],
            'rows' => $result['rows'],
            'displayHint' => $result['plan']->display->value,
            'model' => $result['model'],
            'tokens' => $result['tokens'],
            'estimatedCost' => $result['estimatedCost'],
        ]);
    }

    public function feedback(SubmitFeedbackRequest $request, string $slug): JsonResponse
    {
        $run = QueryRun::query()->where('slug', $slug)->first();

        if (! $run instanceof QueryRun) {
            return response()->json(['error' => __('query.errors.not_found')], 404);
        }

        $run->fill([
            'rating' => $request->validated('rating'),
            'comment' => $request->validated('comment'),
            'rated_at' => now(),
        ])->save();

        return response()->json([
            'rating' => $run->rating,
            'comment' => $run->comment,
        ]);
    }

    public function popular(Request $request, FindPopularQueries $finder): JsonResponse
    {
        return response()->json([
            'prompts' => $finder->execute($this->resolveLocale($request)),
        ]);
    }

    private function resolveLocale(Request $request): string
    {
        $candidate = $request->query('locale');
        $allowed = array_map(static fn (Locale $l): string => $l->value, Locale::cases());

        return is_string($candidate) && in_array($candidate, $allowed, true)
            ? $candidate
            : app()->getLocale();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(QueryRun $run): array
    {
        return [
            'slug' => $run->slug,
            'prompt' => $run->prompt,
            'locale' => $run->locale,
            'plan' => PlanPresenter::normalisePersisted($run->plan),
            'soql' => $run->soql,
            'url' => $run->url,
            'rows' => $run->rows,
            'displayHint' => $run->display_hint,
            'rating' => $run->rating,
            'comment' => $run->comment,
            // Coalesce nullable model to '' so the TS shape stays non-nullable
            // and the frontend's `Boolean(s)` filter still hides empty values.
            'model' => $run->model ?? '',
            'tokens' => [
                'prompt' => $run->prompt_tokens ?? 0,
                'completion' => $run->completion_tokens ?? 0,
                'cacheRead' => $run->cache_read_tokens ?? 0,
                'thought' => $run->thought_tokens ?? 0,
            ],
            'estimatedCost' => $run->estimated_cost,
        ];
    }
}
