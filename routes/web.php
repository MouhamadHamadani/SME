<?php

use App\Http\Controllers\Marketing\HomeController;
use App\Http\Controllers\Marketing\LeadController;
use App\Http\Controllers\WhatsAppWebhookController;
use App\Http\Middleware\SetMarketingLocale;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
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

/*
 | WhatsApp delivery callbacks from Meta.
 |
 | Central and unauthenticated by necessity: Meta calls one url for the whole
 | app and has no session. It is NOT part of the marketing site — it is an
 | integration endpoint that happens to live on the same domain, and it is
 | protected by an HMAC signature rather than by auth.
 |
 | CSRF is excluded because the caller is Meta, not a browser form.
 */
Route::prefix('webhooks/whatsapp')->group(function () {
    Route::get('/', [WhatsAppWebhookController::class, 'verify'])->name('whatsapp.webhook.verify');
    Route::post('/', [WhatsAppWebhookController::class, 'handle'])
        ->withoutMiddleware([PreventRequestForgery::class])
        ->name('whatsapp.webhook');
});
