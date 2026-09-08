<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

/**
 * Sign-in for a business's own users.
 *
 * Runs inside tenant context: the auth guard reads `users` from the tenant's
 * database, so credentials valid at one business are simply not present at
 * another. There is no registration route — during early access the founder
 * provisions each business with `php artisan tenant:create`, because there is
 * no self-serve billing yet (Whish/OMT is Phase 2, §6).
 */
class LoginController extends Controller
{
    public function show(): View
    {
        return view('app.auth.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            // One generic message: never reveal whether the address exists at
            // this business. The route is rate limited on top of this.
            throw ValidationException::withMessages([
                'email' => __('app.auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        return redirect()->intended(route('app.dashboard'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('app.login');
    }
}
