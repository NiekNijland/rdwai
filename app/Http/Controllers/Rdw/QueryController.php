<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rdw;

use App\Actions\Rdw\PersistQueryRun;
use App\Actions\Rdw\QueryExecutionException;
use App\Actions\Rdw\RunNaturalLanguageQuery;
use App\Enums\Locale;
use App\Http\Controllers\Controller;
use App\Http\Requests\Rdw\RunQueryRequest;
use App\Http\Requests\Rdw\SubmitFeedbackRequest;
use App\Models\QueryRun;
use App\Services\QueryPlan\PlanPresenter;
use App\Services\QueryPlan\TokenUsage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use InvalidArgumentException;
use NiekNijland\RDW\Exceptions\RateLimitException;
use NiekNijland\RDW\Exceptions\RdwException;
use Throwable;

final class QueryController extends Controller
{
    private const string CLIENT_COOKIE = 'rdw_client';

    private const int CLIENT_COOKIE_MINUTES = 525_600;

    public function index(Request $request, string $locale, ?string $slug = null): InertiaResponse
    {
        $sharedRun = null;

        if (is_string($slug) && $slug !== '') {
            $run = QueryRun::query()->where('slug', $slug)->first();

            if ($run instanceof QueryRun) {
                $sharedRun = $this->serializeRun($run, $this->currentClientToken($request));
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
                'transient' => $e->isTransient,
                'plan' => $serialisedPlan,
                'soql' => $e->soql,
                'url' => $e->url,
                'responseBody' => $e->responseBody,
            ]);

            // Transient upstream failures get a retryable 504, not a rephrase-it 422.
            return response()->json([
                'error' => __($e->isTransient ? 'query.errors.timeout' : 'query.errors.rejected'),
                'plan' => $serialisedPlan,
                'soql' => $e->soql,
                'url' => $e->url,
                // Raw upstream body may carry internal detail; only expose in debug.
                'responseBody' => (bool) config('app.debug') ? $e->responseBody : null,
            ], $e->isTransient ? 504 : 422);
        } catch (InvalidArgumentException $e) {
            // Plan validation errors reference internal field names; return the localized fallback.
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

        $steps = PlanPresenter::stepsToArray($result->steps);
        $presentation = PlanPresenter::presentationToArray($result->presentation, $result->derived);

        $user = $request->user();
        $run = $persist->execute(
            prompt: $prompt,
            locale: app()->getLocale(),
            plan: $result->plan,
            rows: $result->rows,
            soql: $result->soql,
            url: $result->url,
            userId: $user !== null ? (string) $user->getAuthIdentifier() : null,
            model: $result->model,
            tokens: $result->tokens,
            estimatedCost: $result->estimatedCost,
            steps: $steps,
            presentation: $presentation,
        );

        return response()->json([
            'slug' => $run->slug,
            'plan' => PlanPresenter::toArray($result->plan),
            'soql' => $result->soql,
            'url' => $result->url,
            'rows' => $result->rows,
            'displayHint' => $result->plan->display->value,
            'steps' => $steps,
            'presentation' => $presentation,
            'model' => $result->model,
            'tokens' => $result->tokens->toArray(),
            'estimatedCost' => $result->estimatedCost,
        ]);
    }

    public function feedback(SubmitFeedbackRequest $request, string $slug): JsonResponse
    {
        $run = QueryRun::query()->where('slug', $slug)->first();

        if (! $run instanceof QueryRun) {
            return response()->json(['error' => __('query.errors.not_found')], 404);
        }

        // Feedback belongs to the client that first left it; an unrated run is claimable by anyone.
        $client = $this->issueClientToken($request);
        if (is_string($run->rated_by) && $run->rated_by !== '' && $run->rated_by !== $client) {
            return response()->json(['error' => __('query.errors.feedback_forbidden')], 403);
        }

        $run->fill([
            'rating' => $request->validated('rating'),
            'comment' => $request->validated('comment'),
            'rated_at' => now(),
            'rated_by' => $client,
        ])->save();

        return response()->json([
            'rating' => $run->rating,
            'comment' => $run->comment,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeRun(QueryRun $run, ?string $clientToken): array
    {
        $isAuthor = $clientToken !== null && $run->rated_by === $clientToken;

        return [
            'slug' => $run->slug,
            'prompt' => $run->prompt,
            'locale' => $run->locale,
            'plan' => PlanPresenter::normalisePersisted($run->plan),
            'soql' => $run->soql,
            'url' => $run->url,
            'rows' => $run->rows,
            'displayHint' => $run->display_hint,
            'steps' => PlanPresenter::normalisePersistedSteps($run->steps),
            'presentation' => $run->presentation,
            'rating' => $run->rating,
            // Only the author sees their own free-text comment.
            'comment' => $isAuthor ? $run->comment : null,
            // Coalesce to '' so the TS shape stays non-nullable.
            'model' => $run->model ?? '',
            'tokens' => TokenUsage::fromQueryRun($run)->toArray(),
            'estimatedCost' => $run->estimated_cost,
        ];
    }

    private function currentClientToken(Request $request): ?string
    {
        $token = $request->cookie(self::CLIENT_COOKIE);

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function issueClientToken(Request $request): string
    {
        $existing = $this->currentClientToken($request);
        if ($existing !== null) {
            return $existing;
        }

        $token = Str::random(40);
        Cookie::queue(Cookie::make(
            name: self::CLIENT_COOKIE,
            value: $token,
            minutes: self::CLIENT_COOKIE_MINUTES,
            httpOnly: true,
        ));

        return $token;
    }
}
