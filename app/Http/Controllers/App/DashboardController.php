<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * The app shell. Deliberately thin: this session built the foundation
 * (tenancy, auth, the shared design system), not the accounting domain.
 *
 * The payroll/NSSF/VAT models are NOT scaffolded here on purpose — see
 * docs/ARCHITECTURE.md.
 */
class DashboardController extends Controller
{
    public function __invoke(): View
    {
        return view('app.dashboard');
    }
}
