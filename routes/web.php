<?php

use App\Http\Controllers\Marketing\HomeController;
use App\Http\Controllers\Marketing\LeadController;
use App\Http\Middleware\SetMarketingLocale;
use Illuminate\Support\Facades\Route;

/*
 | Public marketing site.
 |
 | These routes are the front door, not part of the app: they are explicitly
 | unauthenticated and must stay outside any tenant/auth middleware the
 | app-foundation session adds. Authenticated app routes belong in their own
 | file/group with their own middleware stack.
 |
 | Locale is carried in the URL — '/' is English, '/ar' is Arabic — so a shared
 | link or an ad destination keeps its language. See SetMarketingLocale.
 */
Route::middleware(SetMarketingLocale::class)->group(function () {
    Route::get('/', HomeController::class)
        ->defaults('locale', 'en')
        ->name('marketing.home');

    Route::get('/ar', HomeController::class)
        ->defaults('locale', 'ar')
        ->name('marketing.home.ar');

    // Rate limited per IP; the form also carries a honeypot field.
    Route::post('/early-access', [LeadController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('marketing.leads.store');
});
