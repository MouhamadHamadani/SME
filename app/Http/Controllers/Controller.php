<?php

namespace App\Http\Controllers;

use Illuminate\Foundation\Auth\Access\AuthorizesRequests;

abstract class Controller
{
    /*
     | Laravel 11+ leaves this off the base controller. Payroll controllers
     | call $this->authorize('manage-payroll'), so it goes back on: an
     | authorize() that silently did not exist would be a missing permission
     | check, not a fatal error.
     */
    use AuthorizesRequests;
}
