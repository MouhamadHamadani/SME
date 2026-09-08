<?php

declare(strict_types=1);

use App\Http\Controllers\App\CreditNoteController;
use App\Http\Controllers\App\CustomerController;
use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\EmployeeController;
use App\Http\Controllers\App\EosiController;
use App\Http\Controllers\App\ExchangeRateController;
use App\Http\Controllers\App\InvoiceController;
use App\Http\Controllers\App\PayrollController;
use App\Http\Controllers\App\VatController;
use App\Http\Controllers\App\WhatsAppSettingsController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Middleware\SetAppLocale;
use App\Http\Middleware\TenantRouteParameter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Stancl\Tenancy\Middleware\InitializeTenancyBySubdomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

/*
|--------------------------------------------------------------------------
| Tenant routes — the authenticated app
|--------------------------------------------------------------------------
|
| One business per subdomain: acme.sme.test resolves tenant "acme" and swaps
| the database connection to that tenant's own database before anything else
| runs. Nothing here is reachable from a central domain.
|
| Registered BEFORE routes/web.php — see bootstrap/app.php for why.
|
*/

Route::domain('{tenant}.'.config('tenancy.app_domain'))
    ->middleware([
        // `web` is applied by bootstrap/app.php for both route files.
        InitializeTenancyBySubdomain::class,
        PreventAccessFromCentralDomains::class,
        TenantRouteParameter::class,
        SetAppLocale::class,
    ])
    ->group(function () {
        // Guests
        Route::middleware('guest')->group(function () {
            Route::get('/login', [LoginController::class, 'show'])->name('app.login');
            Route::post('/login', [LoginController::class, 'store'])
                ->middleware('throttle:5,1')
                ->name('app.login.attempt');
        });

        // Signed in
        Route::middleware('auth')->group(function () {
            Route::get('/', DashboardController::class)->name('app.dashboard');
            Route::get('/payroll', [PayrollController::class, 'index'])->name('app.payroll');
            Route::post('/payroll', [PayrollController::class, 'store'])->name('app.payroll.draft');
            Route::post('/payroll/finalise', [PayrollController::class, 'finalise'])->name('app.payroll.finalise');

            Route::get('/invoices', [InvoiceController::class, 'index'])->name('app.invoices.index');
            Route::get('/invoices/new', [InvoiceController::class, 'create'])->name('app.invoices.create');
            Route::post('/invoices', [InvoiceController::class, 'store'])->name('app.invoices.store');
            Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('app.invoices.show');
            Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('app.invoices.edit');
            Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('app.invoices.update');
            Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->name('app.invoices.issue');
            Route::post('/invoices/{invoice}/paid', [InvoiceController::class, 'markPaid'])->name('app.invoices.mark-paid');
            Route::post('/invoices/{invoice}/whatsapp', [InvoiceController::class, 'sendWhatsApp'])->name('app.invoices.whatsapp');
            Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('app.invoices.void');

            Route::get('/invoices/{invoice}/credit', [CreditNoteController::class, 'create'])->name('app.invoices.credit.create');
            Route::post('/invoices/{invoice}/credit', [CreditNoteController::class, 'store'])->name('app.invoices.credit');
            Route::post('/invoices/{invoice}/credit/full', [CreditNoteController::class, 'storeFull'])->name('app.invoices.credit.full');
            Route::get('/credit-notes/{creditNote}', [CreditNoteController::class, 'show'])->name('app.credit-notes.show');

            Route::get('/customers', [CustomerController::class, 'index'])->name('app.customers.index');
            Route::get('/customers/new', [CustomerController::class, 'create'])->name('app.customers.create');
            Route::post('/customers', [CustomerController::class, 'store'])->name('app.customers.store');
            Route::get('/customers/{customer}', [CustomerController::class, 'edit'])->name('app.customers.edit');
            Route::put('/customers/{customer}', [CustomerController::class, 'update'])->name('app.customers.update');
            Route::get('/vat', [VatController::class, 'index'])->name('app.vat');
            Route::post('/vat', [VatController::class, 'store'])->name('app.vat.draft');
            Route::post('/vat/file', [VatController::class, 'file'])->name('app.vat.file');

            /*
             | Connecting WhatsApp is self-serve: an owner who signed up an
             | hour ago cannot ssh in and run `whatsapp:connect`. Owner-only —
             | see the manage-settings gate.
             */
            /*
             | The two national rates are read-only here and entered centrally
             | with `exchange:rate` — one business must not be able to edit the
             | rate every other business files at. The form on this screen
             | writes the business's OWN booking rate.
             */
            Route::get('/settings/exchange-rates', [ExchangeRateController::class, 'index'])->name('app.settings.exchange-rates');
            Route::post('/settings/exchange-rates', [ExchangeRateController::class, 'update'])->name('app.settings.exchange-rates.update');
            Route::get('/revaluation', [ExchangeRateController::class, 'revaluation'])->name('app.revaluation');

            Route::get('/settings/whatsapp', [WhatsAppSettingsController::class, 'edit'])->name('app.settings.whatsapp');
            Route::post('/settings/whatsapp', [WhatsAppSettingsController::class, 'update'])->name('app.settings.whatsapp.update');
            Route::post('/settings/whatsapp/test', [WhatsAppSettingsController::class, 'test'])->name('app.settings.whatsapp.test');
            Route::delete('/settings/whatsapp', [WhatsAppSettingsController::class, 'destroy'])->name('app.settings.whatsapp.disconnect');

            Route::get('/employees', [EmployeeController::class, 'index'])->name('app.employees.index');
            Route::get('/employees/new', [EmployeeController::class, 'create'])->name('app.employees.create');
            Route::post('/employees', [EmployeeController::class, 'store'])->name('app.employees.store');
            Route::get('/employees/{employee}', [EmployeeController::class, 'edit'])->name('app.employees.edit');
            Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('app.employees.update');
            Route::post('/employees/{employee}/end', [EmployeeController::class, 'end'])->name('app.employees.end');

            // End-of-service. Viewing is open to any signed-in user; every
            // write is gated on manage-payroll inside the controller.
            Route::get('/employees/{employee}/eosi', [EosiController::class, 'show'])->name('app.employees.eosi');
            Route::post('/employees/{employee}/eosi', [EosiController::class, 'accrue'])->name('app.employees.eosi.accrue');
            Route::post('/employees/{employee}/pay-elements', [EosiController::class, 'storePayElement'])->name('app.employees.pay-elements.store');
            Route::delete('/employees/{employee}/pay-elements/{payElement}', [EosiController::class, 'destroyPayElement'])->name('app.employees.pay-elements.destroy');
            Route::post('/logout', [LoginController::class, 'destroy'])->name('app.logout');
        });

        // Locale toggle works signed in or out.
        Route::post('/locale', function (Request $request) {
            $locale = in_array($request->input('locale'), SetAppLocale::SUPPORTED, true)
                ? $request->input('locale')
                : 'ar';

            $request->session()->put('app_locale', $locale);

            if ($user = $request->user()) {
                $user->update(['locale' => $locale]);
            }

            return back();
        })->name('app.locale');
    });
