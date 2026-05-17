<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Enums\Locale;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Restore the user's locale preference on each request.
     *
     * Priority: route locale -> authenticated user -> session -> cookie -> Accept-Language header -> app default.
     * Keeps the cookie in sync so the preference survives session expiry. Does
     * NOT persist to the user row — that happens only via the explicit
     * UpdateLocale action so a user visiting /en/... doesn't clobber their
     * stored preference.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeLocale = $request->route('locale');
        $user = $request->user();

        // Defensive: read the raw column via the enum cast, but tolerate a
        // stale / legacy DB value by falling back to null instead of throwing.
        $userLocale = $user instanceof User
            ? $user->locale?->value
            : null;

        $defaultLocale = (string) config('app.locale');

        $hasRouteLocale = is_string($routeLocale);
        $sessionLocale = $request->session()->get('locale');
        $cookieLocale = $request->cookie('locale');

        $locale = ($hasRouteLocale ? $routeLocale : null)
            ?? $userLocale
            ?? $sessionLocale
            ?? $cookieLocale
            ?? $this->detectFromAcceptLanguage($request)
            ?? $defaultLocale;

        $resolvedLocaleEnum = Locale::tryFrom((string) $locale);
        $resolvedLocale = $resolvedLocaleEnum !== null
            ? $resolvedLocaleEnum->value
            : $defaultLocale;

        $shouldPersist = $this->shouldPersistLocale(
            sessionLocale: is_string($sessionLocale) ? $sessionLocale : null,
            cookieLocale: is_string($cookieLocale) ? $cookieLocale : null,
            hasRouteLocale: $hasRouteLocale,
            user: $user,
            resolvedLocale: $resolvedLocale,
        );

        app()->setLocale($resolvedLocale);
        URL::defaults(['locale' => $resolvedLocale]);

        if ($shouldPersist) {
            $request->session()->put('locale', $resolvedLocale);
        }

        /** @var Response $response */
        $response = $next($request);

        if ($shouldPersist && $cookieLocale !== $resolvedLocale && method_exists($response, 'cookie')) {
            /** @var \Illuminate\Http\Response $response */
            $response->cookie('locale', $resolvedLocale, 60 * 24 * 365);
        }

        return $response;
    }

    /**
     * Only persist the resolved locale when the visitor actually expressed a
     * preference: they hit a locale-prefixed URL, they're authenticated (which
     * is how UpdateLocaleController takes effect), or they already have a
     * stored preference whose value we're refreshing. An anonymous visitor who
     * just happens to differ from the app default (e.g. via Accept-Language)
     * is NOT enough to claim consent for a year-long cookie.
     */
    private function shouldPersistLocale(
        ?string $sessionLocale,
        ?string $cookieLocale,
        bool $hasRouteLocale,
        ?object $user,
        string $resolvedLocale,
    ): bool {
        if ($sessionLocale === $resolvedLocale && $cookieLocale === $resolvedLocale) {
            return false;
        }

        if ($user !== null) {
            return true;
        }

        if ($hasRouteLocale) {
            return true;
        }

        return $sessionLocale !== null || $cookieLocale !== null;
    }

    /**
     * Detect the preferred locale from the browser's Accept-Language header.
     *
     * Returns a supported locale string or null if no match is found.
     */
    private function detectFromAcceptLanguage(Request $request): ?string
    {
        $supported = array_map(
            static fn (Locale $locale): string => $locale->value,
            Locale::cases(),
        );

        $preferred = $request->getPreferredLanguage($supported);

        return $preferred !== '' ? $preferred : null;
    }
}
