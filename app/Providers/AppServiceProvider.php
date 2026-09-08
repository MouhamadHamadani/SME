<?php

namespace App\Providers;

use App\Models\User;
use App\Payroll\Eosi\EosiStrategy;
use App\Payroll\Eosi\LumpSumStrategy;
use App\WhatsApp\SenderFactory;
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
        /*
         | Senders are per tenant, so the factory is what gets injected — never
         | a single shared sender. Resolving one at boot would bind whichever
         | business happened to be in context first.
         */
        $this->app->singleton(SenderFactory::class);

        /*
         | End-of-service calculation, bound in ONE place.
         |
         | Law 319 could move EOSI from a lump sum to a monthly pension. When
         | it does, this line changes to the pension implementation and every
         | caller — the accrual action, the screen, the command — is untouched.
         | That is the whole reason EosiStrategy is an interface.
         |
         | Existing accrual rows keep their own strategy and version, so they
         | continue to say what they said under the old law.
         */
        $this->app->bind(EosiStrategy::class, LumpSumStrategy::class);

        Gate::define('manage-payroll', fn (User $user) => $user->isOwner() || $user->isAccountant());

        /*
         | Who can change the business's own settings.
         |
         | Narrower than manage-payroll on purpose: connecting WhatsApp stores
         | a bearer credential that can message customers AS the business, and
         | changing it changes who those customers hear from. That is the
         | owner's decision, not the visiting accountant's.
         */
        Gate::define('manage-settings', fn (User $user) => $user->isOwner());
    }
}
