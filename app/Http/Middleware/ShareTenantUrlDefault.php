<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tenant routes live under `Route::domain('{tenant}.example.com')`, so every
 * one of them carries a required `tenant` parameter. stancl only strips that
 * parameter for *path* identification, not subdomain identification — without
 * this, every `route('app.dashboard')` call would throw
 * UrlGenerationException: Missing required parameter.
 *
 * Registering it as a URL default means route helpers just work, and links
 * stay on the subdomain the request came in on.
 */
class ShareTenantUrlDefault
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($subdomain = $request->route('tenant')) {
            URL::defaults(['tenant' => $subdomain]);
        }

        return $next($request);
    }
}
