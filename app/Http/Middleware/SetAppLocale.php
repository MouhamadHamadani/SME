<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale for the authenticated app.
 *
 * Unlike the marketing site — where locale lives in the URL so a shared link
 * carries its language — the app resolves locale per *person*: their saved
 * preference, falling back to the business default, falling back to Arabic.
 * A URL prefix would be noise on every internal link for no benefit, since
 * nobody shares deep links into someone else's books.
 */
class SetAppLocale
{
    public const SUPPORTED = ['ar', 'en'];

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('app_locale')
            ?? $request->user()?->locale
            ?? tenant()?->settings['language']
            ?? 'ar';

        app()->setLocale(in_array($locale, self::SUPPORTED, true) ? $locale : 'ar');

        return $next($request);
    }
}
