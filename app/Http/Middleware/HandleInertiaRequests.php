<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * Cache the locale->label map per active app locale. The labels are
     * translated, so we can't share across locales, but each label set is
     * stable for the lifetime of a request (and across requests serving the
     * same locale within one PHP worker).
     *
     * @var array<string, array<string, string>>
     */
    private static array $localeLabelCache = [];

    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $locale = app()->getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
            'locale' => $locale,
            'locales' => $this->localeLabels($locale),
            'fallbackLocale' => config('app.fallback_locale'),
        ];
    }

    /**
     * @return array<string, string>
     */
    private function localeLabels(string $locale): array
    {
        if (! isset(self::$localeLabelCache[$locale])) {
            $labels = [];
            foreach (Locale::cases() as $case) {
                $labels[$case->value] = $case->label();
            }
            self::$localeLabelCache[$locale] = $labels;
        }

        return self::$localeLabelCache[$locale];
    }
}
