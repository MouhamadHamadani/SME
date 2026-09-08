<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        /*
         | Who can change payroll.
         |
         | Owners run their own business; accountants are brought in precisely
         | to do this (accountant seats are a Pro-plan feature). Staff can see
         | the payroll screen but not alter salaries or draft runs — salary
         | data is the most sensitive thing in the product, and read access is
         | already a deliberate choice by whoever created the account.
         |
         | ASSUMPTION, not a stated requirement: revisit if staff need to
         | maintain the employee list.
         */
        Gate::define('manage-payroll', fn (User $user) => $user->isOwner() || $user->isAccountant());
    }
}
