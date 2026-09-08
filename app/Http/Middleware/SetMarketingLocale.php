<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active locale for the public marketing site.
 *
 * Locale lives in the URL ('/' = en, '/ar' = ar) rather than the session, so a
 * link someone shares — or the URL a WhatsApp/Instagram ad points at — carries
 * its own language. GET routes declare it as a route default; the lead-capture
 * POST carries it as a form field, so a submission comes back in the language
 * it was filled in.
 */
class SetMarketingLocale
{
    public const SUPPORTED = ['en', 'ar'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->route('locale') ?? $request->input('locale');

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'en');

        return $next($request);
    }
}
