<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\View;
use Stancl\Tenancy\Exceptions\TenantCouldNotBeIdentifiedOnDomainException;

return Application::configure(basePath: dirname(__DIR__))
    /*
     | Routes are registered explicitly rather than via `web:`/`then:` because
     | ORDER MATTERS HERE and the defaults get it wrong.
     |
     | The marketing routes are unconstrained (they answer on any central
     | domain), and the tenant routes are constrained to a subdomain. Laravel
     | matches in registration order, so if the marketing routes went first,
     | `acme.sme.test/` would match the marketing homepage before it ever
     | reached the tenant route. stancl's own TenancyServiceProvider::mapRoutes
     | registers tenant routes inside `app->booted()` — i.e. dead last — which
     | is exactly that bug. It is disabled there; this is the single place
     | route order is decided.
     */
    ->withRouting(
        commands: __DIR__.'/../routes/console.php',
        using: function () {
            Route::middleware('web')->get('/up', function () {
                Event::dispatch(new DiagnosingHealth);

                return View::file(base_path('vendor/laravel/framework/src/Illuminate/Foundation/resources/health-up.blade.php'));
            });

            /*
             | Both groups get the `web` stack here, in one place. Taking over
             | registration means it is no longer applied for us — and without
             | it the marketing site loses sessions, CSRF and the shared
             | $errors bag, which is a silent break on the lead form.
             */

            // Tenant app — subdomain-constrained. Must be registered first.
            Route::middleware('web')->group(base_path('routes/tenant.php'));

            // Public marketing site — unauthenticated, no tenancy.
            Route::middleware('web')->group(base_path('routes/web.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        /*
         | The app's sign-in route is `app.login`, not Laravel's assumed
         | `login`. Without this, an unauthenticated hit on a tenant route
         | throws RouteNotFoundException (a 500) instead of redirecting.
         |
         | The tenant parameter is read off the request rather than left to
         | ShareTenantUrlDefault's URL::defaults — Laravel's middleware priority
         | runs Authenticate before unprioritised middleware, so the default is
         | not reliably registered yet at this point.
         */
        $middleware->redirectGuestsTo(
            fn (Request $request) => route('app.login', ['tenant' => $request->route('tenant')])
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
         | An unknown subdomain is a wrong address, not a server fault. stancl
         | throws TenantCouldNotBeIdentified..., which would otherwise surface
         | as a 500 — and in production a 500 page is also a louder signal to
         | anyone probing for valid tenant names than a plain 404.
         */
        $exceptions->render(function (TenantCouldNotBeIdentifiedOnDomainException $e) {
            abort(404);
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
