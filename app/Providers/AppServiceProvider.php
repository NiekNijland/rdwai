<?php

declare(strict_types=1);

namespace App\Providers;

use App\Services\QueryPlan\CostEstimator;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use NiekNijland\RDW\Http\Configuration as RdwConfiguration;
use NiekNijland\RDW\Rdw;
use NiekNijland\RDW\Schema\SchemaRegistry;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(Rdw::class, static fn (): Rdw => new Rdw(new RdwConfiguration(
            appToken: config('rdwai.rdw_app_token'),
            userAgent: 'rdwai/0.1 (laravel)',
            timeoutSeconds: 15.0,
        )));

        // Share the SchemaRegistry the Rdw client uses with the rest of the
        // app (e.g. PlanFactory) so schema lookups cannot diverge across
        // collaborators.
        $this->app->singleton(SchemaRegistry::class, static fn ($app): SchemaRegistry => $app->make(Rdw::class)->schemas());

        // Bind (not singleton) so a `config()->set('rdwai.model_prices', …)`
        // call from a test that runs *after* boot is still honoured the next
        // time the estimator is resolved.
        $this->app->bind(CostEstimator::class, static fn (): CostEstimator => new CostEstimator(
            (array) config('rdwai.model_prices', []),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
    }

    /**
     * Configure rate limiters for the public RDW query endpoint. The route
     * is open to anonymous visitors, so we layer a per-IP burst limit under
     * a global daily cap to protect the OpenAI spend.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('rdw-query', fn (Request $request): array => [
            Limit::perMinute((int) config('rdwai.rate_limit.per_minute'))->by((string) $request->ip()),
            Limit::perDay((int) config('rdwai.rate_limit.per_day_global'))->by('rdw-query:global'),
        ]);

        RateLimiter::for('rdw-feedback', fn (Request $request): array => [
            Limit::perMinute((int) config('rdwai.rate_limit.feedback_per_minute'))->by((string) $request->ip()),
        ]);

        RateLimiter::for('rdw-read', fn (Request $request): array => [
            Limit::perMinute((int) config('rdwai.rate_limit.read_per_minute'))->by((string) $request->ip()),
        ]);
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
