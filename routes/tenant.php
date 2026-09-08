<?php

declare(strict_types=1);

use App\Http\Controllers\App\DashboardController;
use App\Http\Controllers\App\EmployeeController;
use App\Http\Controllers\App\PayrollController;
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

            Route::get('/employees', [EmployeeController::class, 'index'])->name('app.employees.index');
            Route::get('/employees/new', [EmployeeController::class, 'create'])->name('app.employees.create');
            Route::post('/employees', [EmployeeController::class, 'store'])->name('app.employees.store');
            Route::get('/employees/{employee}', [EmployeeController::class, 'edit'])->name('app.employees.edit');
            Route::put('/employees/{employee}', [EmployeeController::class, 'update'])->name('app.employees.update');
            Route::post('/employees/{employee}/end', [EmployeeController::class, 'end'])->name('app.employees.end');
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
