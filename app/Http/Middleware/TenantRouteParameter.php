<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Takes the `{tenant}` route parameter out of the way, in both directions.
 *
 * Tenant routes live under `Route::domain('{tenant}.example.com')`, so every
 * one of them carries a `tenant` parameter that the app itself never wants.
 * It causes two separate problems, and this fixes both:
 *
 * 1. URL GENERATION — without a default, every `route('app.dashboard')` call
 *    throws UrlGenerationException: Missing required parameter.
 *
 * 2. CONTROLLER DISPATCH — and this one is nastier, because it only shows up
 *    once a route takes a model. Route parameters are passed to the controller
 *    POSITIONALLY, and `tenant` is the first one. So on
 *    `/employees/{employee}`, a controller declaring `edit(Employee $employee)`
 *    receives the string "acme" instead of the model — even though implicit
 *    binding resolved the Employee correctly. Forgetting the parameter once
 *    tenancy is initialized leaves the controller with only what it asked for.
 *
 * stancl does exactly this for PATH identification (PathTenantResolver calls
 * forgetParameter). Subdomain identification has no equivalent, so it lives
 * here. Must run after the tenancy middleware, which reads the subdomain from
 * the host rather than from this parameter.
 */
class TenantRouteParameter
{
    public function handle(Request $request, Closure $next): Response
    {
        $route = $request->route();

        if ($route && ($subdomain = $route->parameter('tenant'))) {
            // Keeps generated links on the subdomain the request came in on.
            URL::defaults(['tenant' => $subdomain]);

            $route->forgetParameter('tenant');
        }

        return $next($request);
    }
}
