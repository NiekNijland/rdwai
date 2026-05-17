<?php

declare(strict_types=1);

namespace Tests\Feature\Middleware;

use App\Enums\Locale;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Route;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class SetLocaleTest extends TestCase
{
    public function test_route_locale_takes_priority_over_other_sources(): void
    {
        $request = $this->makeRequest('/en');
        $route = new Route('GET', '/{locale}', []);
        $route->bind($request);
        $route->setParameter('locale', 'en');
        $request->setRouteResolver(static fn (): Route => $route);
        $request->session()->put('locale', 'nl');
        $request->cookies->set('locale', 'nl');

        $this->runMiddleware($request);

        self::assertSame('en', app()->getLocale());
        self::assertSame('en', $request->session()->get('locale'));
    }

    public function test_authenticated_user_locale_wins_when_no_route_locale(): void
    {
        $user = User::factory()->createOne(['locale' => Locale::Dutch->value]);
        $this->actingAs($user);

        $request = $this->makeRequest('/api/query');
        $request->setUserResolver(static fn (): User => $user);
        $request->session()->put('locale', 'en');

        $this->runMiddleware($request);

        self::assertSame('nl', app()->getLocale());
    }

    public function test_session_locale_used_when_no_user_or_route_locale(): void
    {
        $request = $this->makeRequest('/api/query');
        $request->session()->put('locale', 'en');

        $this->runMiddleware($request);

        self::assertSame('en', app()->getLocale());
    }

    public function test_cookie_locale_used_when_session_empty(): void
    {
        $request = $this->makeRequest('/api/query');
        $request->cookies->set('locale', 'en');

        $this->runMiddleware($request);

        self::assertSame('en', app()->getLocale());
    }

    public function test_falls_back_to_default_when_nothing_resolves(): void
    {
        config()->set('app.locale', 'nl');

        $request = $this->makeRequest('/api/query');
        // Symfony's Request::create defaults Accept-Language to 'en-us,en' —
        // strip it so we exercise the default-locale tail of the chain.
        $request->headers->remove('Accept-Language');

        $this->runMiddleware($request);

        self::assertSame('nl', app()->getLocale());
    }

    public function test_does_not_persist_for_anonymous_visitor_matching_only_accept_language(): void
    {
        config()->set('app.locale', 'nl');

        $request = $this->makeRequest('/api/query');
        $request->headers->set('Accept-Language', 'en-US,en;q=0.9');

        $response = $this->runMiddleware($request);

        self::assertSame('en', app()->getLocale());
        self::assertFalse($request->session()->has('locale'));
        self::assertEmpty($this->cookieNames($response));
    }

    public function test_persists_for_authenticated_user_even_without_explicit_choice(): void
    {
        $user = User::factory()->createOne(['locale' => Locale::English->value]);
        config()->set('app.locale', 'nl');

        $request = $this->makeRequest('/api/query');
        $request->setUserResolver(static fn (): User => $user);

        $response = $this->runMiddleware($request);

        self::assertSame('en', $request->session()->get('locale'));
        self::assertContains('locale', $this->cookieNames($response));
    }

    public function test_persists_when_visiting_locale_prefixed_route(): void
    {
        config()->set('app.locale', 'nl');

        $request = $this->makeRequest('/en');
        $route = new Route('GET', '/{locale}', []);
        $route->bind($request);
        $route->setParameter('locale', 'en');
        $request->setRouteResolver(static fn (): Route => $route);

        $response = $this->runMiddleware($request);

        self::assertSame('en', $request->session()->get('locale'));
        self::assertContains('locale', $this->cookieNames($response));
    }

    public function test_refreshes_session_when_existing_session_value_disagrees(): void
    {
        config()->set('app.locale', 'nl');

        $request = $this->makeRequest('/en');
        $route = new Route('GET', '/{locale}', []);
        $route->bind($request);
        $route->setParameter('locale', 'en');
        $request->setRouteResolver(static fn (): Route => $route);
        $request->session()->put('locale', 'nl');

        $this->runMiddleware($request);

        self::assertSame('en', $request->session()->get('locale'));
    }

    private function makeRequest(string $uri): Request
    {
        $request = Request::create($uri, 'GET');
        $request->setLaravelSession($this->app['session.store']);

        return $request;
    }

    private function runMiddleware(Request $request): Response
    {
        $middleware = new SetLocale();

        $response = $middleware->handle(
            $request,
            static fn (Request $r): Response => new Response('ok'),
        );

        self::assertInstanceOf(Response::class, $response);

        return $response;
    }

    /**
     * @return list<string>
     */
    private function cookieNames(Response $response): array
    {
        return array_values(array_map(
            static fn (Cookie $cookie): string => $cookie->getName(),
            $response->headers->getCookies(),
        ));
    }
}
