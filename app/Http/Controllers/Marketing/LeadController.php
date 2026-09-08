<?php

namespace App\Http\Controllers\Marketing;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class LeadController extends Controller
{
    /**
     * Pre-launch lead capture. No account is created and nothing is charged —
     * self-serve billing is Phase 2 (§6). This just records who asked.
     */
    public function store(Request $request): RedirectResponse
    {
        // Honeypot, checked before validation: a bot that filled it must not
        // learn anything from the response, including which fields failed.
        if (filled($request->input('website'))) {
            return $this->thankYou();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'business_name' => ['required', 'string', 'max:160'],
            'phone' => ['required', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:190'],
            'business_type' => ['required', Rule::in(array_keys(__('marketing.form.business_types')))],
            'currently_using' => ['nullable', Rule::in(array_keys(__('marketing.form.currently_using_options')))],
        ]);

        Lead::create($validated + ['locale' => app()->getLocale()]);

        return $this->thankYou();
    }

    /**
     * Back to the homepage in the language it was submitted in, anchored at the
     * form so the thank-you state is what the visitor lands on.
     */
    private function thankYou(): RedirectResponse
    {
        $route = app()->getLocale() === 'ar' ? 'marketing.home.ar' : 'marketing.home';

        return redirect()
            ->to(route($route).'#early-access')
            ->with('lead_submitted', true);
    }
}
