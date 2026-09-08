<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

class HomeController extends Controller
{
    /** Locale is already set by SetMarketingLocale from the route default. */
    public function __invoke(): View
    {
        return view('marketing.home');
    }
}
